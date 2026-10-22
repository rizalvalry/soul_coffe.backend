<?php

namespace Tests\Feature\Uat;

use App\Enums\Role;
use App\Models\Cart;
use App\Models\CentralKitchen;
use App\Models\Customer;
use App\Models\DailyAllocation;
use App\Models\Location;
use App\Models\Media;
use App\Models\NewsPost;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\RefillRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class MobileApiSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_registered_mobile_get_route_has_no_server_error_for_each_staff_role(): void
    {
        $this->seed();
        $paths = collect(Route::getRoutes())->filter(fn ($route): bool => in_array('GET', $route->methods(), true)
            && str_starts_with($route->uri(), 'api/v1/')
            && ! str_contains($route->uri(), 'customer/'))
            ->map(fn ($route): string => $route->uri())
            ->unique()->values()->all();
        $this->assertGreaterThan(20, count($paths));
        foreach (Role::cases() as $role) {
            $user = User::factory()->role($role)->create();
            foreach ($paths as $path) {
                // A missing detail fixture should be 403/404, never a server exception.
                $uri = preg_replace('/\{[^}]+\}/', '999999', $path);
                $status = $this->actingAs($user, 'sanctum')->getJson('/'.$uri)->status();
                $allowedStatuses = str_contains($path, '{') ? [200, 403, 404, 422] : [200, 403, 422];
                $this->assertContains($status, $allowedStatuses, "$role->value $path: $status");
            }
        }
    }

    public function test_wrong_roles_get_403_on_finance_kitchen_and_rider_reads(): void
    {
        $this->seed();
        $forbidden = [
            Role::STAFF->value => ['clearing-receipts', 'clearing-receipts/options',
                'settlements/queue', 'showcase/stock', 'allocations/today'],
            Role::RIDER->value => ['clearing-receipts', 'settlements/queue',
                'showcase/stock', 'me/stock'],
            Role::BARISTA->value => ['clearing-receipts', 'settlements/queue', 'me/stock'],
            Role::CONTENT_CREATOR->value => ['clearing-receipts', 'settlements/queue',
                'showcase/stock', 'me/stock'],
        ];
        foreach ($forbidden as $code => $paths) {
            $user = User::factory()->role(Role::from($code))->create();
            foreach ($paths as $path) {
                $this->actingAs($user, 'sanctum')->getJson('/api/v1/'.$path)->assertForbidden();
            }
        }
    }

    public function test_existing_detail_routes_render_for_the_owning_roles(): void
    {
        $this->seed();
        Storage::fake('local');
        $kitchen = CentralKitchen::query()->firstOrFail();
        $cart = Cart::query()->where('kitchen_id', $kitchen->id)->firstOrFail();
        $location = Location::query()->firstOrFail();
        $rider = User::factory()->role(Role::STAFF)->create();
        $production = User::factory()->role(Role::BARISTA)->create(['kitchen_id' => $kitchen->id]);
        $finance = User::factory()->role(Role::FINANCE)->create();
        $author = User::factory()->role(Role::CONTENT_CREATOR)->create();
        $allocation = DailyAllocation::create([
            'operating_date' => today(), 'cart_id' => $cart->id, 'staff_id' => $rider->id,
            'kitchen_id' => $kitchen->id, 'barista_id' => $production->id,
            'location_id' => $location->id, 'status' => 'ISSUED', 'issued_at' => now(),
        ]);
        $photo = Media::create([
            'kind' => 'evidence', 'path' => 'evidence/uat-detail.jpg', 'mime' => 'image/jpeg',
            'bytes' => 1, 'sha256' => hash('sha256', 'uat-detail'),
            'exif_taken_at' => now(), 'uploaded_by' => $rider->id,
        ]);
        $refill = RefillRequest::create([
            'uuid' => (string) Str::uuid(), 'code' => 'UAT-DETAIL', 'operating_date' => today(),
            'cart_id' => $cart->id, 'staff_id' => $rider->id, 'kitchen_id' => $kitchen->id,
            'status' => 'SUBMITTED', 'version' => 1, 'submitted_at' => now(),
            'evidence_photo_id' => $photo->id,
        ]);
        $news = NewsPost::create([
            'title' => 'Info UAT', 'slug' => 'info-uat', 'body' => 'Berita uji detail.',
            'status' => 'published', 'published_at' => now()->subMinute(), 'author_id' => $author->id,
        ]);
        foreach ([
            [$production, "allocations/{$allocation->id}"],
            [$rider, "allocations/{$allocation->id}"],
            [$rider, "refills/{$refill->id}"],
            [$finance, "refills/{$refill->id}"],
            [$rider, "news/{$news->id}"],
            [$production, "showcase/allowance/{$cart->id}"],
            [$finance, "showcase/close-out/draft/{$cart->id}?kitchen_id={$kitchen->id}"],
        ] as [$actor, $path]) {
            $status = $this->actingAs($actor, 'sanctum')->getJson('/api/v1/'.$path)->status();
            $this->assertContains($status, str_starts_with($path, 'showcase/allowance/') ? [200, 201] : [200], $path);
        }
        $payrollRun = PayrollRun::create([
            'period_start' => today()->startOfMonth(), 'status' => 'APPROVED', 'created_by' => $finance->id,
        ]);
        $slip = PayrollItem::create([
            'run_id' => $payrollRun->id, 'user_id' => $rider->id,
            'snapshot' => ['components' => [], 'gross_pay_minor' => 0, 'take_home_minor' => 0],
        ]);
        $slip->update(['slip_path' => "payroll-slips/{$payrollRun->id}/item-{$slip->id}.pdf"]);
        Storage::disk('local')->put($slip->slip_path, '%PDF-1.4 UAT');
        $this->actingAs($rider, 'sanctum')->get('/api/v1/payroll/my-slips/'.$slip->id)->assertOk();
        $this->actingAs($production, 'sanctum')->get('/api/v1/payroll/my-slips/'.$slip->id)->assertForbidden();
    }

    public function test_core_phone_screen_reads_return_200_for_their_roles(): void
    {
        $this->seed();
        $kitchen = CentralKitchen::query()->firstOrFail();
        $screens = [
            Role::BARISTA->value => ['products', 'carts', 'locations', 'showcase/stock',
                'showcase/staff', 'absen/status', 'notifications', 'badges'],
            Role::STAFF->value => ['products', 'carts', 'locations', 'refills', 'sales',
                'absen/status', 'notifications', 'badges', 'news'],
            Role::RIDER->value => ['refills', 'incidents', 'notifications', 'badges', 'news'],
            Role::FINANCE->value => ['settlements/queue', 'clearing-receipts',
                'clearing-receipts/options', 'showcase/kitchens', 'products', 'carts', 'notifications', 'badges'],
            Role::MANAGEMENT->value => ['settlements/queue', 'clearing-receipts',
                'clearing-receipts/options', 'showcase/kitchens', 'products', 'carts', 'notifications', 'badges'],
            Role::ADMINISTRATOR->value => ['settlements/queue', 'clearing-receipts',
                'clearing-receipts/options', 'showcase/kitchens', 'products', 'carts', 'notifications', 'badges'],
        ];
        foreach ($screens as $code => $paths) {
            $role = Role::from($code);
            $user = User::factory()->role($role)->create($role === Role::BARISTA ? ['kitchen_id' => $kitchen->id] : []);
            foreach ($paths as $path) {
                $this->actingAs($user, 'sanctum')->getJson('/api/v1/'.$path)->assertOk();
            }
        }
    }

    public function test_customer_get_routes_are_isolated_from_staff_tokens(): void
    {
        $customer = Customer::create(['google_sub' => 'uat-customer']);
        $staff = User::factory()->role(Role::FINANCE)->create();
        $customerToken = $customer->createToken('uat', ['customer'])->plainTextToken;
        $staffToken = $staff->createToken('uat')->plainTextToken;
        $this->withToken($customerToken)->getJson('/api/v1/customer/me')->assertOk();
        Auth::forgetGuards();
        $this->withToken($staffToken)->getJson('/api/v1/customer/me')->assertForbidden();
        Auth::forgetGuards();
        $this->withToken($customerToken)->getJson('/api/v1/products')->assertForbidden();
    }
}
