<?php

namespace Tests\Feature;

use App\Enums\RefillStatus;
use App\Enums\Role;
use App\Models\Cart;
use App\Models\CentralKitchen;
use App\Models\Media;
use App\Models\RefillRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `POST /media/evidence` (docs/04, R3/E6).
 *
 * These cover the dedupe guard specifically, because it is the one rule that has to tell two
 * indistinguishable-looking things apart: a staff member REUSING an old photo, which R3 forbids,
 * and the mobile client RETRYING one upload after the connection dropped before the response
 * arrived, which must succeed or the request can never be submitted at all.
 */
class EvidenceUploadTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $otherStaff;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $kitchen = CentralKitchen::create([
            'name' => 'Dapur Test',
            'address' => 'Jl. Uji Coba No. 1',
            'open_at' => '00:00:00',
            'close_at' => '23:59:59',
            'is_active' => true,
        ]);

        $this->staff = User::factory()->role(Role::STAFF)->create(['kitchen_id' => $kitchen->id]);
        $this->otherStaff = User::factory()->role(Role::STAFF)->create(['kitchen_id' => $kitchen->id]);
    }

    /** The exact same file object cannot be replayed twice, so build an identical one twice. */
    private function photo(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('evidence.jpg', $this->jpegBytes());
    }

    private function jpegBytes(): string
    {
        $image = imagecreatetruecolor(64, 48);
        imagefilledrectangle($image, 0, 0, 63, 47, imagecolorallocate($image, 12, 34, 56));
        ob_start();
        imagejpeg($image, null, 90);

        return (string) ob_get_clean();
    }

    private function upload(User $as, UploadedFile $file)
    {
        return $this->actingAs($as)
            ->withHeader('Accept', 'application/json')
            ->post('/api/v1/media/evidence', [
                'file' => $file,
                'taken_at' => now()->toIso8601String(),
            ]);
    }

    public function test_it_stores_an_evidence_photo(): void
    {
        $response = $this->upload($this->staff, $this->photo());

        $response->assertCreated();
        $this->assertSame(1, Media::where('kind', 'evidence')->count());
    }

    /**
     * E6 + client retry: identical bytes from the same uploader, still unattached, inside the
     * retry window resolve to the SAME media row rather than a 422. Without this the mobile
     * retry introduced in `uploadFileWithStatus()` would poison every recovered upload.
     */
    public function test_a_retried_upload_returns_the_same_media_instead_of_failing(): void
    {
        $first = $this->upload($this->staff, $this->photo());
        $first->assertCreated();

        $second = $this->upload($this->staff, $this->photo());

        $second->assertCreated();
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Media::where('kind', 'evidence')->count());
    }

    /** R3: the same bytes from a DIFFERENT staff member is a reused photo, not a retry. */
    public function test_it_rejects_the_same_photo_from_another_uploader(): void
    {
        $this->upload($this->staff, $this->photo())->assertCreated();

        $this->upload($this->otherStaff, $this->photo())->assertStatus(422);
    }

    /** R3: once the photo has been spent on a refill request, re-sending it is reuse. */
    public function test_it_rejects_a_photo_already_attached_to_a_refill_request(): void
    {
        $first = $this->upload($this->staff, $this->photo());
        $first->assertCreated();

        $this->attachToRefillRequest((int) $first->json('data.id'));

        $this->upload($this->staff, $this->photo())->assertStatus(422);
    }

    /** R3: outside the retry window the same bytes are a stale photo again. */
    public function test_it_rejects_the_same_photo_after_the_retry_window_closes(): void
    {
        $this->upload($this->staff, $this->photo())->assertCreated();

        $this->travel((int) config('soul.evidence_retry_window_minutes') + 1)->minutes();

        $this->upload($this->staff, $this->photo())->assertStatus(422);
    }

    private function attachToRefillRequest(int $mediaId): void
    {
        $kitchen = CentralKitchen::first();

        $cart = Cart::create([
            'code' => '0098',
            'plate' => null,
            'status' => 'active',
            'kitchen_id' => $kitchen->id,
        ]);

        RefillRequest::create([
            'uuid' => (string) Str::uuid(),
            'code' => 'REF-'.now()->format('Ymd').'-9001',
            'operating_date' => today()->toDateString(),
            'cart_id' => $cart->id,
            'staff_id' => $this->staff->id,
            'kitchen_id' => $kitchen->id,
            'status' => RefillStatus::SUBMITTED,
            'evidence_photo_id' => $mediaId,
            'client_submitted_at' => now(),
            'submitted_at' => now(),
        ]);
    }
}
