<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\NewsPost;
use App\Models\NewsPostEngagement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The in-app news feed (docs/04 §News).
 *
 * The visibility rules carry the weight here. A feed that leaks a draft, shows a scheduled post
 * early, keeps an expired promo up, or hands a Barista note to a Rider is worse than no feed, so
 * each of those gets its own test rather than being covered by one happy path.
 */
class NewsFeedTest extends TestCase
{
    use RefreshDatabase;

    private User $creator;

    private User $staff;

    private User $barista;

    protected function setUp(): void
    {
        parent::setUp();

        $this->creator = User::factory()->role(Role::CONTENT_CREATOR)->create();
        $this->staff = User::factory()->role(Role::STAFF)->create();
        $this->barista = User::factory()->role(Role::BARISTA)->create();
    }

    private function makePost(array $attributes = []): NewsPost
    {
        return NewsPost::create(array_merge([
            'title' => 'Kopi Baru Datang',
            'slug' => Str::slug('kopi-baru-'.Str::random(8)),
            'kicker' => 'BARU NIH!',
            'excerpt' => 'Ada yang baru di gerobak.',
            'body' => '<p>Isi artikel.</p>',
            'status' => 'published',
            'published_at' => now()->subMinute(),
            'sort_order' => 0,
            'author_id' => $this->creator->id,
        ], $attributes));
    }

    public function test_staff_sees_a_published_post(): void
    {
        $post = $this->makePost();

        $response = $this->actingAs($this->staff)->getJson('/api/v1/news');

        $response->assertOk();
        $response->assertJsonPath('data.0.id', $post->id);
        $response->assertJsonPath('data.0.kicker', 'BARU NIH!');
        // The list must not ship article bodies — see NewsPostResource.
        $response->assertJsonMissingPath('data.0.body');
    }

    public function test_a_draft_is_invisible_to_everyone(): void
    {
        $this->makePost(['status' => 'draft']);

        $this->actingAs($this->staff)->getJson('/api/v1/news')->assertJsonCount(0, 'data');
    }

    public function test_a_scheduled_post_stays_hidden_until_its_time(): void
    {
        $post = $this->makePost(['published_at' => now()->addHour()]);

        $this->actingAs($this->staff)->getJson('/api/v1/news')->assertJsonCount(0, 'data');

        $this->travel(2)->hours();

        $this->actingAs($this->staff)->getJson('/api/v1/news')->assertJsonPath('data.0.id', $post->id);
    }

    public function test_an_expired_post_drops_out_of_the_feed(): void
    {
        $this->makePost(['expires_at' => now()->addHour()]);

        $this->actingAs($this->staff)->getJson('/api/v1/news')->assertJsonCount(1, 'data');

        $this->travel(2)->hours();

        $this->actingAs($this->staff)->getJson('/api/v1/news')->assertJsonCount(0, 'data');
    }

    public function test_audience_targeting_is_enforced_at_the_query(): void
    {
        $baristaOnly = $this->makePost(['audience_roles' => [Role::BARISTA->value]]);

        $this->actingAs($this->staff)->getJson('/api/v1/news')->assertJsonCount(0, 'data');
        $this->actingAs($this->barista)->getJson('/api/v1/news')
            ->assertJsonPath('data.0.id', $baristaOnly->id);

        // A direct hit obeys the same rule, not just the list.
        $this->actingAs($this->staff)->getJson("/api/v1/news/{$baristaOnly->id}")->assertNotFound();
    }

    public function test_an_empty_audience_means_everyone(): void
    {
        $this->makePost(['audience_roles' => []]);

        $this->actingAs($this->staff)->getJson('/api/v1/news')->assertJsonCount(1, 'data');
        $this->actingAs($this->barista)->getJson('/api/v1/news')->assertJsonCount(1, 'data');
    }

    public function test_highlighted_posts_come_first_then_sort_order(): void
    {
        $second = $this->makePost(['title' => 'Kedua', 'sort_order' => 2]);
        $first = $this->makePost(['title' => 'Pertama', 'sort_order' => 1]);
        $highlighted = $this->makePost(['title' => 'Sorotan', 'sort_order' => 9, 'is_highlighted' => true]);

        $response = $this->actingAs($this->staff)->getJson('/api/v1/news');

        $response->assertJsonPath('data.0.id', $highlighted->id);
        $response->assertJsonPath('data.1.id', $first->id);
        $response->assertJsonPath('data.2.id', $second->id);
    }

    public function test_the_highlighted_filter_returns_only_slider_posts(): void
    {
        $this->makePost(['title' => 'Biasa']);
        $slider = $this->makePost(['title' => 'Sorotan', 'is_highlighted' => true]);

        $response = $this->actingAs($this->staff)->getJson('/api/v1/news?highlighted=1');

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $slider->id);
    }

    public function test_show_returns_the_body(): void
    {
        $post = $this->makePost();

        $this->actingAs($this->staff)
            ->getJson("/api/v1/news/{$post->id}")
            ->assertOk()
            ->assertJsonPath('data.body', '<p>Isi artikel.</p>');
    }

    public function test_marking_read_twice_is_one_read_and_keeps_the_first_time(): void
    {
        $post = $this->makePost();

        $this->actingAs($this->staff)->postJson("/api/v1/news/{$post->id}/read")->assertOk();
        $first = NewsPostEngagement::firstWhere('news_post_id', $post->id)->read_at;

        $this->travel(1)->hour();
        $this->actingAs($this->staff)->postJson("/api/v1/news/{$post->id}/read")->assertOk();

        $this->assertSame(1, NewsPostEngagement::where('news_post_id', $post->id)->count());
        $this->assertTrue(
            $first->equalTo(NewsPostEngagement::firstWhere('news_post_id', $post->id)->read_at)
        );
    }

    public function test_reacting_twice_with_the_same_reaction_clears_it(): void
    {
        $post = $this->makePost();

        $this->actingAs($this->staff)
            ->postJson("/api/v1/news/{$post->id}/react", ['reaction' => 'api'])
            ->assertOk()
            ->assertJsonPath('data.my_reaction', 'api')
            ->assertJsonPath('data.reaction_counts.api', 1);

        $this->actingAs($this->staff)
            ->postJson("/api/v1/news/{$post->id}/react", ['reaction' => 'api'])
            ->assertOk()
            ->assertJsonPath('data.my_reaction', null);
    }

    public function test_an_unknown_reaction_is_refused(): void
    {
        $post = $this->makePost();

        $this->actingAs($this->staff)
            ->postJson("/api/v1/news/{$post->id}/react", ['reaction' => 'sedih'])
            ->assertStatus(422);
    }

    public function test_a_reader_never_sees_someone_elses_reaction_as_their_own(): void
    {
        $post = $this->makePost();

        $this->actingAs($this->barista)
            ->postJson("/api/v1/news/{$post->id}/react", ['reaction' => 'mantap']);

        $response = $this->actingAs($this->staff)->getJson('/api/v1/news');

        $response->assertJsonPath('data.0.my_reaction', null);
        $response->assertJsonPath('data.0.reaction_counts.mantap', 1);
    }

    public function test_the_feed_requires_authentication(): void
    {
        $this->getJson('/api/v1/news')->assertStatus(401);
    }
}
