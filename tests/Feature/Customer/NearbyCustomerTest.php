<?php

namespace Tests\Feature\Customer;

use App\Enums\Role;
use App\Models\Attendance;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\Location;
use App\Models\LocationSetting;
use App\Models\RiderClosing;
use App\Models\RiderLocationSetup;
use App\Models\StaffAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Tests\TestCase;

class NearbyCustomerTest extends TestCase
{
    use RefreshDatabase;

    public function test_updated_customer_radius_takes_effect_without_cache_clear(): void
    {
        $customer = Customer::create(['google_sub' => 'radius-google']);
        $token = $customer->createToken('test', ['customer'])->plainTextToken;
        $this->openCart('AT2500', rad2deg(2500 / 6371000));

        $this->withToken($token)->postJson('/api/v1/customer/nearby-carts', ['lat' => 0, 'lng' => 0])
            ->assertOk()->assertJsonCount(0, 'data');
        LocationSetting::query()->findOrFail(1)->update(['customer_radius_m' => 3000]);
        $this->withToken($token)->postJson('/api/v1/customer/nearby-carts', ['lat' => 0, 'lng' => 0])
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'AT2500');
    }

    private function openCart(string $code, float $longitude, bool $attendance = true, bool $setup = true,
        bool $assignment = true, bool $closing = false, ?float $assignmentLongitude = null,
        ?Carbon $clockedInAt = null): void
    {
        $date = now('Asia/Jakarta')->toDateString();
        $rider = User::factory()->role(Role::STAFF)->create();
        $cart = Cart::create(['code' => $code]);
        $location = Location::create(['name' => 'Titik '.$code, 'lat' => 0, 'lng' => $longitude]);
        $assignmentLocation = $assignmentLongitude === null ? $location : Location::create([
            'name' => 'Penugasan '.$code, 'lat' => 0, 'lng' => $assignmentLongitude,
        ]);
        if ($setup) {
            RiderLocationSetup::create([
                'rider_id' => $rider->id, 'location_id' => $location->id,
                'basis' => 'selling_location',
            ]);
        }
        if ($assignment) {
            StaffAssignment::create([
                'user_id' => $rider->id, 'cart_id' => $cart->id,
                'location_id' => $assignmentLocation->id, 'operating_date' => $date,
                'assigned_by' => $rider->id, 'source' => 'hand_to_cart',
            ]);
        }
        if ($attendance) {
            Attendance::create([
                'operating_date' => $date, 'user_id' => $rider->id,
                'role' => Role::STAFF, 'clocked_in_at' => $clockedInAt ?? now()->startOfMinute(),
            ]);
        }
        if ($closing) {
            RiderClosing::create([
                'operating_date' => $date, 'cart_id' => $cart->id,
                'rider_id' => $rider->id, 'total_minor' => 0, 'cogs_minor' => 0,
                'qris_minor' => 0, 'transfer_minor' => 0, 'cash_minor' => 0,
                'closed_at' => now(),
            ]);
        }
    }

    public function test_nearby_has_strict_radius_open_rules_and_public_fields_only(): void
    {
        $customer = Customer::create(['google_sub' => 'nearby-google', 'email' => 'n@example.test', 'name' => 'N']);
        $token = $customer->createToken('test', ['customer'])->plainTextToken;
        $deg = fn (float $meters): float => rad2deg($meters / 6371000);
        $this->openCart('IN', $deg(1999));
        $this->openCart('EDGE', ceil($deg(2000) * 10000000) / 10000000);
        $this->openCart('OUT', $deg(2500));
        $this->openCart('CLOSED', $deg(500), closing: true);
        $this->openCart('NOABSEN', $deg(500), attendance: false);
        $this->openCart('NOASSIGN', $deg(500), assignment: false);
        $this->openCart('NOSETUP', $deg(500), setup: false);

        $response = $this->withToken($token)->postJson('/api/v1/customer/nearby-carts', ['lat' => 0, 'lng' => 0])
            ->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('IN', $response->json('data.0.code'));
        $this->assertSame(['code', 'name', 'location_name', 'lat', 'lng', 'distance_m', 'open_since'],
            array_keys($response->json('data.0')));
        $this->assertSame(2000, $response->json('data.0.distance_m'));
    }

    public function test_invalid_coordinates_return_422_without_logging_coordinates(): void
    {
        $customer = Customer::create(['google_sub' => 'nearby-google']);
        $token = $customer->createToken('test', ['customer'])->plainTextToken;
        $handler = new TestHandler;
        Log::getLogger()->pushHandler($handler);

        $this->withToken($token)->postJson('/api/v1/customer/nearby-carts', ['lat' => 91, 'lng' => 0])
            ->assertUnprocessable();
        $this->withToken($token)->postJson('/api/v1/customer/nearby-carts', ['lat' => 0, 'lng' => 181])
            ->assertUnprocessable();
        $this->withToken($token)->postJson('/api/v1/customer/nearby-carts',
            ['lat' => 12.345678, 'lng' => 98.765432])->assertOk();

        foreach ($handler->getRecords() as $record) {
            $this->assertStringNotContainsString('12.345678', (string) $record->message);
            $this->assertStringNotContainsString('98.765432', (string) $record->message);
            $this->assertStringNotContainsString('12.345678', json_encode($record->context));
            $this->assertStringNotContainsString('98.765432', json_encode($record->context));
        }
    }

    public function test_nearby_get_with_coordinates_is_not_available(): void
    {
        $customer = Customer::create(['google_sub' => 'nearby-method']);
        $token = $customer->createToken('test', ['customer'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/customer/nearby-carts?lat=0&lng=0')
            ->assertStatus(405);
    }

    public function test_nearby_post_rejects_query_coordinates_even_with_a_valid_json_body(): void
    {
        $customer = Customer::create(['google_sub' => 'nearby-query']);
        $token = $customer->createToken('test', ['customer'])->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/customer/nearby-carts?lat=0&lng=0', [])
            ->assertUnprocessable();
        $this->withToken($token)->postJson('/api/v1/customer/nearby-carts?lat=0&lng=0',
            ['lat' => 0, 'lng' => 0])->assertUnprocessable();
    }

    public function test_nearby_orders_nearest_first_and_caps_results_at_twenty(): void
    {
        $customer = Customer::create(['google_sub' => 'nearby-order']);
        $token = $customer->createToken('test', ['customer'])->plainTextToken;
        for ($i = 22; $i >= 1; $i--) {
            $this->openCart('C'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                rad2deg((float) ($i * 50) / 6371000));
        }
        $items = $this->withToken($token)->postJson('/api/v1/customer/nearby-carts', ['lat' => 0, 'lng' => 0])
            ->assertOk()->json('data');
        $this->assertCount(20, $items);
        $this->assertSame('C01', $items[0]['code']);
        $this->assertSame('C20', $items[19]['code']);
        $this->assertSame(range(50, 1000, 50), array_column($items, 'distance_m'));
    }

    public function test_nearby_uses_setup_point_and_jakarta_clock_in_minute(): void
    {
        $customer = Customer::create(['google_sub' => 'nearby-point']);
        $token = $customer->createToken('test', ['customer'])->plainTextToken;
        $point = rad2deg(500 / 6371000);
        $clockedInAt = now('Asia/Jakarta')->startOfDay()->addHours(7)->addMinutes(3)->addSeconds(49);
        $this->openCart('POINT', $point, assignmentLongitude: rad2deg(4000 / 6371000),
            clockedInAt: $clockedInAt);

        $item = $this->withToken($token)->postJson('/api/v1/customer/nearby-carts', ['lat' => 0, 'lng' => 0])
            ->assertOk()->json('data.0');
        $this->assertSame('Titik POINT', $item['location_name']);
        $this->assertEqualsWithDelta($point, $item['lng'], 0.000001);
        $this->assertSame(500, $item['distance_m']);
        $this->assertSame('07:03', $item['open_since']);
    }

    public function test_nearby_rate_limit_is_per_customer_with_an_ip_ceiling(): void
    {
        $customer = Customer::create(['google_sub' => 'nearby-limited']);
        $token = $customer->createToken('test', ['customer'])->plainTextToken;
        for ($i = 0; $i < 30; $i++) {
            $this->withToken($token)->postJson('/api/v1/customer/nearby-carts', ['lat' => 0, 'lng' => 0])
                ->assertOk();
        }
        $this->withToken($token)->postJson('/api/v1/customer/nearby-carts', ['lat' => 0, 'lng' => 0])
            ->assertStatus(429);
        $other = Customer::create(['google_sub' => 'nearby-other']);
        $otherToken = $other->createToken('test', ['customer'])->plainTextToken;
        Auth::forgetGuards();
        $this->withToken($otherToken)->postJson('/api/v1/customer/nearby-carts', ['lat' => 0, 'lng' => 0])
            ->assertOk();
    }
}
