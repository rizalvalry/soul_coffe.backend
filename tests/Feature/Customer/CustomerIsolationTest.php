<?php

namespace Tests\Feature\Customer;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_token_is_denied_by_every_non_customer_v1_route(): void
    {
        $customer = Customer::create(['google_sub' => 'isolation-google']);
        $token = $customer->createToken('test', ['customer'])->plainTextToken;
        $checked = 0;
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/v1/') || str_starts_with($uri, 'api/v1/customer/')) {
                continue;
            }
            $method = collect($route->methods())->first(fn ($method) => ! in_array($method, ['HEAD', 'OPTIONS'], true));
            $path = '/'.preg_replace('/\{[^}]+\}/', '1', $uri);
            $response = $this->withToken($token)->json($method, $path);
            $this->assertContains($response->status(), [401, 403], $method.' '.$uri.' answered '.$response->status());
            $checked++;
        }
        $this->assertGreaterThan(30, $checked);
    }

    public function test_staff_token_is_denied_by_all_customer_routes_and_customer_cannot_enter_panel(): void
    {
        $staff = User::factory()->role(Role::STAFF)->create();
        $staffToken = $staff->createToken('staff')->plainTextToken;
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/customer/')) {
                continue;
            }
            $method = collect($route->methods())->first(fn ($method) => ! in_array($method, ['HEAD', 'OPTIONS'], true));
            $path = '/'.preg_replace('/\{[^}]+\}/', '1', $route->uri());
            $response = $this->withToken($staffToken)
                ->withHeader('Idempotency-Key', (string) Str::uuid())->json($method, $path);
            $this->assertContains($response->status(), [401, 403], $method.' '.$path.' answered '.$response->status());
        }

        $customer = Customer::create(['google_sub' => 'isolation-google']);
        $customerToken = $customer->createToken('test', ['customer'])->plainTextToken;
        $this->withToken($customerToken)->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_disabled_customer_is_refused_on_every_protected_customer_route(): void
    {
        $customer = Customer::create(['google_sub' => 'disabled-google', 'status' => 'DISABLED']);
        $token = $customer->createToken('test', ['customer'])->plainTextToken;
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/customer/')
                || $route->uri() === 'api/v1/customer/auth/google') {
                continue;
            }
            $method = collect($route->methods())->first(fn ($method) => ! in_array($method, ['HEAD', 'OPTIONS'], true));
            $response = $this->withToken($token)
                ->withHeader('Idempotency-Key', (string) Str::uuid())
                ->json($method, '/'.$route->uri());
            $response->assertForbidden();
        }
    }
}
