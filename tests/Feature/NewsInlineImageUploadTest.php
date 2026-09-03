<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `POST /admin/news/inline-images` — the endpoint behind InlineImagePastePlugin.
 *
 * Covers the server half of the fix for a content creator dragging an image from another browser
 * tab, or pasting a copied web image, into a news article's body: the JS side fetches the bytes
 * client-side and hands them here exactly like any other file upload, so this is a normal
 * authenticated-upload endpoint, not a URL fetcher (see the controller's docblock).
 */
class NewsInlineImageUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function jpeg(int $kilobytes = 40): UploadedFile
    {
        return UploadedFile::fake()->image('pasted.jpg')->size($kilobytes);
    }

    public function test_a_guest_cannot_upload(): void
    {
        $this->postJson('/admin/news/inline-images', [
            'image' => $this->jpeg(),
        ])->assertUnauthorized();
    }

    public function test_a_content_creator_can_upload_an_inline_image(): void
    {
        $creator = User::factory()->role(Role::CONTENT_CREATOR)->create();

        $this->actingAs($creator)
            ->postJson('/admin/news/inline-images', ['image' => $this->jpeg()])
            ->assertOk()
            ->assertJsonStructure(['url']);

        $this->assertNotEmpty(Storage::disk('public')->allFiles('news/inline'));
    }

    public function test_an_administrator_can_upload_an_inline_image(): void
    {
        $admin = User::factory()->role(Role::ADMINISTRATOR)->create();

        $this->actingAs($admin)
            ->postJson('/admin/news/inline-images', ['image' => $this->jpeg()])
            ->assertOk()
            ->assertJsonStructure(['url']);
    }

    /** Every other role writes nothing to the news feed — see NewsPostResource::canViewAny(). */
    public function test_an_operational_role_is_refused(): void
    {
        $staff = User::factory()->role(Role::STAFF)->create();

        $this->actingAs($staff)
            ->postJson('/admin/news/inline-images', ['image' => $this->jpeg()])
            ->assertForbidden();
    }

    public function test_a_non_image_file_is_rejected(): void
    {
        $creator = User::factory()->role(Role::CONTENT_CREATOR)->create();

        $this->actingAs($creator)
            ->postJson('/admin/news/inline-images', [
                'image' => UploadedFile::fake()->create('not-an-image.pdf', 40, 'application/pdf'),
            ])
            ->assertStatus(422);
    }

    /** Above the endpoint's own cap (8MB) — kept in sync with RichEditor::fileAttachmentsMaxSize() in NewsPostForm. */
    public function test_an_oversized_image_is_rejected(): void
    {
        $creator = User::factory()->role(Role::CONTENT_CREATOR)->create();

        $this->actingAs($creator)
            ->postJson('/admin/news/inline-images', ['image' => $this->jpeg(9000)])
            ->assertStatus(422);
    }

    public function test_missing_image_is_rejected(): void
    {
        $creator = User::factory()->role(Role::CONTENT_CREATOR)->create();

        $this->actingAs($creator)
            ->postJson('/admin/news/inline-images', [])
            ->assertStatus(422);
    }
}
