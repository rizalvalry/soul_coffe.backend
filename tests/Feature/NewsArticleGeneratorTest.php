<?php

namespace Tests\Feature;

use App\Services\NewsArticleGenerator;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * The AI half of "Generate dengan AI" — everything here is the boundary between an OpenAI
 * response and what's safe to hand back to NewsPostForm's ->action() to `$set()` onto the form,
 * so every failure mode is tested through an actual (faked) HTTP response, never by calling the
 * sanitizer methods directly.
 */
class NewsArticleGeneratorTest extends TestCase
{
    private function fakeChatResponse(array $draft, int $status = 200): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => json_encode($draft)]],
                ],
            ], $status),
        ]);
    }

    public function test_it_throws_a_friendly_message_when_the_key_is_missing(): void
    {
        config(['services.openai.key' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OPENAI_API_KEY belum diisi');

        app(NewsArticleGenerator::class)->generate('promo matcha baru');
    }

    public function test_a_complete_draft_is_returned_and_sanitized(): void
    {
        config(['services.openai.key' => 'sk-test']);

        $this->fakeChatResponse([
            'kicker' => 'BARU NIH!',
            'title' => 'Matcha Series Hadir Minggu Ini',
            'excerpt' => 'Rasakan kesegaran matcha baru kami.',
            'body' => '<p>Paragraf pertama.</p><script>alert(1)</script><p onclick="x()">Paragraf kedua.</p>',
            'tags' => ['promo', 'matcha', 'baru', 'musiman', 'favorit', 'kelima-belas'],
            'accent_color' => '#F59E0B',
        ]);

        $draft = app(NewsArticleGenerator::class)->generate('promo matcha baru minggu ini');

        $this->assertSame('BARU NIH!', $draft['kicker']);
        $this->assertSame('Matcha Series Hadir Minggu Ini', $draft['title']);
        $this->assertSame('matcha-series-hadir-minggu-ini', $draft['slug']);
        $this->assertSame('Rasakan kesegaran matcha baru kami.', $draft['excerpt']);
        $this->assertStringNotContainsString('<script>', $draft['body']);
        $this->assertStringNotContainsString('onclick', $draft['body']);
        $this->assertStringContainsString('<p>Paragraf pertama.</p>', $draft['body']);
        $this->assertCount(5, $draft['tags']); // capped, the 6th is dropped
        $this->assertSame('#F59E0B', $draft['accent_color']);
    }

    public function test_a_null_kicker_and_accent_color_pass_through_as_null(): void
    {
        config(['services.openai.key' => 'sk-test']);

        $this->fakeChatResponse([
            'kicker' => null,
            'title' => 'Judul Sederhana',
            'excerpt' => null,
            'body' => '<p>Isi singkat.</p>',
            'tags' => [],
            'accent_color' => null,
        ]);

        $draft = app(NewsArticleGenerator::class)->generate('artikel sederhana');

        $this->assertNull($draft['kicker']);
        $this->assertNull($draft['excerpt']);
        $this->assertNull($draft['accent_color']);
        $this->assertSame([], $draft['tags']);
    }

    public function test_an_invalid_accent_color_is_dropped_rather_than_stored(): void
    {
        config(['services.openai.key' => 'sk-test']);

        $this->fakeChatResponse([
            'title' => 'Judul',
            'body' => '<p>Isi.</p>',
            'accent_color' => 'javascript:alert(1)',
        ]);

        $draft = app(NewsArticleGenerator::class)->generate('artikel');

        $this->assertNull($draft['accent_color']);
    }

    public function test_a_failed_http_response_is_translated_to_a_friendly_message(): void
    {
        config(['services.openai.key' => 'sk-test']);

        Http::fake(['api.openai.com/*' => Http::response('rate limited', 429)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Gagal menghubungi layanan AI');

        app(NewsArticleGenerator::class)->generate('promo matcha baru');
    }

    public function test_a_response_missing_the_required_fields_is_rejected(): void
    {
        config(['services.openai.key' => 'sk-test']);

        $this->fakeChatResponse(['kicker' => 'BARU NIH!']); // no title, no body

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tidak sesuai format');

        app(NewsArticleGenerator::class)->generate('promo matcha baru');
    }

    public function test_non_json_content_is_rejected(): void
    {
        config(['services.openai.key' => 'sk-test']);

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'ini bukan JSON']],
                ],
            ], 200),
        ]);

        $this->expectException(RuntimeException::class);

        app(NewsArticleGenerator::class)->generate('promo matcha baru');
    }
}
