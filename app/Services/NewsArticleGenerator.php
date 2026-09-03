<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Turns a content creator's one-line brief into a full draft — every field the news editor form
 * can show, except the ones that are an editorial decision no model should make: status, audience,
 * scheduling, and whether it's highlighted. Those always keep the form's own defaults.
 *
 * A brief, not a body: the writer describes what they want ("promo matcha baru, nada ceria, ajak
 * staff coba minggu ini"), the model returns a structured draft, and the writer edits from there —
 * this never submits on its own.
 *
 * Deliberately a single OpenAI call with `response_format: json_object`, not a chain of tool calls
 * or an agent loop: the shape of the output (six named fields) is fixed and known ahead of time,
 * so there is nothing here for the model to plan around.
 */
class NewsArticleGenerator
{
    private const ENDPOINT = 'https://api.openai.com/v1/chat/completions';

    /**
     * @return array{kicker: ?string, title: string, slug: string, excerpt: ?string, body: string, tags: array<string>, accent_color: ?string}
     *
     * @throws RuntimeException on a missing key, a failed request, or a response that doesn't
     *                          parse into the expected shape — always with a message safe to show
     *                          the writer directly, never a raw API/HTTP error.
     */
    public function generate(string $prompt): array
    {
        $apiKey = config('services.openai.key');

        if (blank($apiKey)) {
            throw new RuntimeException(
                'Fitur AI belum diaktifkan — OPENAI_API_KEY belum diisi di server.'
            );
        }

        $response = Http::withToken($apiKey)
            ->timeout(45)
            ->post(self::ENDPOINT, [
                'model' => config('services.openai.model', 'gpt-4o-mini'),
                'response_format' => ['type' => 'json_object'],
                'temperature' => 0.7,
                'messages' => [
                    ['role' => 'system', 'content' => $this->systemPrompt()],
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

        if ($response->failed()) {
            report(new RuntimeException(
                "NewsArticleGenerator: OpenAI returned {$response->status()}: {$response->body()}"
            ));

            throw new RuntimeException(
                'Gagal menghubungi layanan AI (status '.$response->status().'). Coba lagi sebentar lagi.'
            );
        }

        $content = $response->json('choices.0.message.content');
        $draft = is_string($content) ? json_decode($content, true) : null;

        if (! is_array($draft) || blank($draft['title'] ?? null) || blank($draft['body'] ?? null)) {
            report(new RuntimeException(
                'NewsArticleGenerator: unparseable or incomplete AI response: '.json_encode($content)
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
