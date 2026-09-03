<?php

namespace Tests\Feature;

use App\Services\NewsArticleGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * The AI half of "Generate dengan AI" — everything here is the boundary between a Gemini
 * response and what's safe to hand back to NewsPostForm's ->action() to `$set()` onto the form,
 * so every failure mode is tested through an actual (faked) HTTP response, never by calling the
 * sanitizer methods directly.
 *
 * The key/model come from AiSetting (the "Pengaturan AI" panel page), with config('services.gemini.*')
 * as a fallback for whoever hasn't visited that page — every test here exercises the fallback,
 * since the DB row starts empty on a fresh migration.
 */
class NewsArticleGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private function fakeGeminiResponse(array $draft, int $status = 200): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    ['content' => ['parts' => [['text' => json_encode($draft)]]]],
                ],
            ], $status),
        ]);
    }

    public function test_it_throws_a_friendly_message_when_the_key_is_missing(): void
    {
        config(['services.gemini.key' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('isi Gemini API Key di menu Pengaturan');

        app(NewsArticleGenerator::class)->generate('promo matcha baru');
    }

    public function test_a_complete_draft_is_returned_and_sanitized(): void
    {
        config(['services.gemini.key' => 'test-key']);

        $this->fakeGeminiResponse([
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
        config(['services.gemini.key' => 'test-key']);

        $this->fakeGeminiResponse([
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
        config(['services.gemini.key' => 'test-key']);

        $this->fakeGeminiResponse([
            'title' => 'Judul',
            'body' => '<p>Isi.</p>',
            'accent_color' => 'javascript:alert(1)',
        ]);

        $draft = app(NewsArticleGenerator::class)->generate('artikel');

        $this->assertNull($draft['accent_color']);
    }

    /**
     * Gemini answers 503 "experiencing high demand" routinely — it did so here three minutes
     * after an identical call succeeded. A transient upstream blip must not reach the writer as
     * a failure when simply asking again works.
     */
    public function test_a_503_is_retried_and_succeeds_without_the_writer_ever_seeing_it(): void
    {
        config(['services.gemini.key' => 'test-key']);

        Http::fakeSequence('generativelanguage.googleapis.com/*')
            ->push('overloaded', 503)
            ->push([
                'candidates' => [
                    ['content' => ['parts' => [['text' => json_encode([
                        'title' => 'Judul Setelah Retry',
                        'body' => '<p>Isi.</p>',
                    ])]]]],
                ],
            ], 200);

        $draft = app(NewsArticleGenerator::class)->generate('promo matcha baru');

        $this->assertSame('Judul Setelah Retry', $draft['title']);
        Http::assertSentCount(2);
    }

    public function test_a_persistent_503_gives_up_after_three_attempts_with_a_try_again_message(): void
    {
        config(['services.gemini.key' => 'test-key']);

        Http::fake(['generativelanguage.googleapis.com/*' => Http::response('overloaded', 503)]);

        try {
            app(NewsArticleGenerator::class)->generate('promo matcha baru');
            $this->fail('A persistent 503 should still surface as an error.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('sedang sibuk', $e->getMessage());
        }

        // 3 total attempts, matching finfam's proven 2-retry setting.
        Http::assertSentCount(3);
    }

    /**
     * A rejected key or a retired model will never fix itself, so burning two extra attempts on
     * it only delays telling the writer where to actually look.
     */
    public function test_a_permanent_failure_is_not_retried_and_points_at_the_settings(): void
    {
        config(['services.gemini.key' => 'test-key']);

        Http::fake(['generativelanguage.googleapis.com/*' => Http::response('bad key', 401)]);

        try {
            app(NewsArticleGenerator::class)->generate('promo matcha baru');
            $this->fail('A 401 should surface as an error.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Pengaturan', $e->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_a_response_missing_the_required_fields_is_rejected(): void
    {
        config(['services.gemini.key' => 'test-key']);

        $this->fakeGeminiResponse(['kicker' => 'BARU NIH!']); // no title, no body

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tidak sesuai format');

        app(NewsArticleGenerator::class)->generate('promo matcha baru');
    }

    public function test_non_json_content_is_rejected(): void
    {
        config(['services.gemini.key' => 'test-key']);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    ['content' => ['parts' => [['text' => 'ini bukan JSON']]]],
                ],
            ], 200),
        ]);

        $this->expectException(RuntimeException::class);

        app(NewsArticleGenerator::class)->generate('promo matcha baru');
    }

    public function test_the_api_key_is_sent_as_a_header_not_a_query_string(): void
    {
        config(['services.gemini.key' => 'test-key']);

        $this->fakeGeminiResponse([
            'title' => 'Judul',
            'body' => '<p>Isi.</p>',
        ]);

        app(NewsArticleGenerator::class)->generate('artikel');

        Http::assertSent(function ($request) {
            return $request->hasHeader('x-goog-api-key', 'test-key')
                && ! str_contains((string) $request->url(), 'test-key');
        });
    }
}
