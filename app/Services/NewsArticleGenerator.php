<?php

namespace App\Services;

use App\Models\AiSetting;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Turns a content creator's one-line brief into a full draft — every field the news editor form
 * can show, except the ones that are an editorial decision no model should make: status, audience,
 * scheduling, and whether it's highlighted. Those always keep the form's own defaults.
 *
 * A brief, not a body: the writer describes what they want ("promo matcha baru, nada ceria, ajak
 * staff coba minggu ini"), the model returns a structured draft, and the writer edits from there —
 * this never submits on its own.
 *
 * Deliberately a single Gemini call with a JSON response mime type, not a chain of tool calls or
 * an agent loop: the shape of the output (six named fields) is fixed and known ahead of time, so
 * there is nothing here for the model to plan around.
 */
class NewsArticleGenerator
{
    /**
     * @return array{kicker: ?string, title: string, slug: string, excerpt: ?string, body: string, tags: array<string>, accent_color: ?string}
     *
     * @throws RuntimeException on a missing key, a failed request, or a response that doesn't
     *                          parse into the expected shape — always with a message safe to show
     *                          the writer directly, never a raw API/HTTP error.
     */
    public function generate(string $prompt): array
    {
        $setting = AiSetting::current();

        // The panel's own "Pengaturan AI" page (an Administrator-only setting, stored encrypted)
        // is the primary source — it exists so a key/model change never needs .env or a deploy
        // again. config('services.gemini.*') is a fallback for local dev and any environment
        // where nobody has visited that page yet.
        $apiKey = $setting->gemini_api_key ?: config('services.gemini.key');

        if (blank($apiKey)) {
            throw new RuntimeException(
                'Fitur AI belum diaktifkan — isi Gemini API Key di menu Pengaturan > AI.'
            );
        }

        // "-latest" is a Google-maintained alias, not a pinned version — pinning one (as this
        // used to) breaks the moment Google retires it, which is exactly what happened here
        // (gemini-2.0-flash was deprecated mid-project). Verified against a second, already-
        // working Gemini integration in this workspace (D:\finfam\apps-script\Code.gs) before
        // relying on it.
        $model = $setting->gemini_model ?: config('services.gemini.model', 'gemini-flash-latest');

        // The key goes in a header, not the URL query string, so it never ends up in a proxy
        // access log, a browser history entry, or an error report that happens to include the
        // request URL — all real places a query-string secret has leaked before.
        $response = Http::withHeaders(['x-goog-api-key' => $apiKey])
            ->timeout(30)
            // Gemini returns 503 "experiencing high demand" as a matter of course — it happened
            // here three minutes after an identical call succeeded, so it is capacity, not
            // configuration. Retried rather than surfaced, matching the retry loop the working
            // integration in D:\finfam\apps-script\Code.gs uses for exactly this (2 retries,
            // 500ms then 1000ms). The delay stays under ~1.5s because a person is sitting in
            // front of the button waiting for it.
            ->retry([500, 1000], when: function (Throwable $e): bool {
                // A connection that never landed is always worth another try.
                if (! $e instanceof RequestException) {
                    return true;
                }

                // Only genuinely transient statuses. Retrying a 400/401/403/404 would just
                // delay an error that is never going to resolve itself — a rejected key or a
                // retired model needs a person, not another attempt.
                return in_array($e->response->status(), [429, 500, 502, 503, 504], true);
            }, throw: false)
            ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent", [
                'systemInstruction' => [
                    'parts' => [['text' => $this->systemPrompt()]],
                ],
                'contents' => [
                    ['role' => 'user', 'parts' => [['text' => $prompt]]],
                ],
                'generationConfig' => [
                    'temperature' => 0.7,
                    'responseMimeType' => 'application/json',
                    'maxOutputTokens' => 4000,
                    // Newer Gemini models can spend their entire token budget "thinking" before
                    // ever writing the JSON — this is a straightforward structured-generation
                    // task with nothing to reason through, so that budget is switched off rather
                    // than silently eating maxOutputTokens and leaving 0 for the actual answer
                    // (same failure mode documented in D:\finfam\apps-script\Code.gs).
                    'thinkingConfig' => ['thinkingBudget' => 0],
                ],
            ]);

        if ($response->failed()) {
            report(new RuntimeException(
                "NewsArticleGenerator: Gemini returned {$response->status()}: {$response->body()}"
            ));

            // Separated because these two need different things from the reader: "busy" means
            // press the button again in a moment, while anything else means a person has to go
            // look at the key or the model name. One generic message sent everybody to the
            // wrong place.
            throw new RuntimeException(
                in_array($response->status(), [429, 503], true)
                    ? 'Layanan AI sedang sibuk (sudah dicoba 3 kali). Tunggu sebentar lalu klik Generate lagi.'
                    : 'Gagal menghubungi layanan AI (status '.$response->status().'). Cek Gemini API Key dan Model di menu Pengaturan > AI.'
            );
        }

        $content = $response->json('candidates.0.content.parts.0.text');
        $draft = is_string($content) ? json_decode($content, true) : null;

        if (! is_array($draft) || blank($draft['title'] ?? null) || blank($draft['body'] ?? null)) {
            report(new RuntimeException(
                'NewsArticleGenerator: unparseable or incomplete AI response (finishReason: '
                    .($response->json('candidates.0.finishReason') ?? 'null').'): '
                    .json_encode($content)
            ));

            throw new RuntimeException(
                'Jawaban AI tidak sesuai format yang diharapkan. Coba ubah sedikit prompt-nya dan ulangi.'
            );
        }

        return $this->sanitize($draft);
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
            Kamu adalah asisten penulis untuk newsfeed internal aplikasi Soul Coffeemate — dibaca oleh
            staff, barista, rider, dan finance di dalam aplikasi kerja mereka, bukan pelanggan. Tulis
            dalam Bahasa Indonesia yang hangat, ringkas, dan mudah dibaca cepat di layar HP.

            Balas HANYA dengan satu objek JSON, tanpa teks lain, dengan persis field berikut:
            - "kicker": string pendek (maks 60 karakter) atau null. Baris pembuka mencolok di atas judul,
              contoh gaya: "BARU NIH!", "PENTING!". Kosongkan (null) kalau brief tidak cocok punya kicker.
            - "title": string, judul artikel, jelas dan menarik, maks kira-kira 80 karakter.
            - "excerpt": string pendek (2-3 kalimat, maks 500 karakter) atau null — ringkasan yang tampil
              di kartu/daftar.
            - "body": string HTML sederhana untuk isi artikel lengkap — HANYA tag <p>, <strong>, <em>,
              <ul>, <li>, <ol>, <br> yang diperbolehkan. Tidak ada <script>, atribut, atau tag lain.
              Panjang menyesuaikan brief, minimal 2 paragraf.
            - "tags": array 0-5 string pendek (satu atau dua kata), huruf kecil.
            - "accent_color": string warna hex 7 karakter seperti "#F59E0B" yang cocok dengan suasana
              artikel, atau null kalau brief tidak menyebutkan nuansa warna tertentu.
            PROMPT;
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array{kicker: ?string, title: string, slug: string, excerpt: ?string, body: string, tags: array<string>, accent_color: ?string}
     */
    private function sanitize(array $draft): array
    {
        $title = trim((string) $draft['title']);

        return [
            'kicker' => $this->nullableString($draft['kicker'] ?? null, 60),
            'title' => Str::limit($title, 255, ''),
            'slug' => Str::slug($title),
            'excerpt' => $this->nullableString($draft['excerpt'] ?? null, 500),
            'body' => $this->sanitizeBody((string) $draft['body']),
            'tags' => $this->sanitizeTags($draft['tags'] ?? []),
            'accent_color' => $this->sanitizeColor($draft['accent_color'] ?? null),
        ];
    }

    /**
     * `strip_tags()` only removes disallowed TAGS — an allowed one keeps every attribute it
     * arrived with, so `<p onclick="...">` survives it untouched. The regex pass after it drops
     * every attribute from what's left, which is safe here specifically because the allowlist is
     * closed and tiny: nothing in it (p, strong, em, ul, li, ol, br) is ever meant to carry one.
     */
    private function sanitizeBody(string $html): string
    {
        $html = strip_tags($html, '<p><strong><em><ul><li><ol><br>');

        return preg_replace('/<(\/?\w+)[^>]*>/', '<$1>', $html) ?? '';
    }

    private function nullableString(mixed $value, int $maxLength): ?string
    {
        if (! is_string($value) || blank($value)) {
            return null;
        }

        return Str::limit(trim($value), $maxLength, '');
    }

    /**
     * @return array<string>
     */
    private function sanitizeTags(mixed $tags): array
    {
        if (! is_array($tags)) {
            return [];
        }

        return collect($tags)
            ->filter(fn ($tag) => is_string($tag) && filled($tag))
            ->map(fn (string $tag) => Str::limit(trim($tag), 30, ''))
            ->take(5)
            ->values()
            ->all();
    }

    private function sanitizeColor(mixed $color): ?string
    {
        if (! is_string($color) || ! preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            return null;
        }

        return $color;
    }
}
