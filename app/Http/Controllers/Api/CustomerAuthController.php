<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerAuthController extends Controller
{
    public function google(Request $request): JsonResponse
    {
        $identity = $request->attributes->get('customer_google_identity');

        [$customer, $token] = DB::transaction(function () use ($identity): array {
            Customer::query()->insertOrIgnore([
                'google_sub' => $identity['sub'],
                'email' => $identity['email'],
                'email_verified' => true,
                'name' => $identity['name'],
                'avatar_url' => $identity['avatar_url'],
                'status' => 'ACTIVE',
                'last_login_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $customer = Customer::withTrashed()->where('google_sub', $identity['sub'])
                ->lockForUpdate()->firstOrFail();
            abort_if($customer->trashed() || $customer->status === 'DISABLED', 403,
                'Akun kamu sedang nonaktif. Hubungi tim Soul Coffee ya.');
            $customer->update([
                'email' => $identity['email'],
                'email_verified' => true,
                'name' => $identity['name'],
                'avatar_url' => $identity['avatar_url'],
                'last_login_at' => now(),
            ]);

            return [$customer, $customer->createToken('CustomerApps', ['customer'])->plainTextToken];
        });

        return response()->json(['data' => [
            'token' => $token,
            'customer' => $customer->apiProfile(),
        ]]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->apiProfile()]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Kamu sudah keluar ya.']);
    }

    public function acceptPrivacy(Request $request): JsonResponse
    {
        $customer = $request->user();
        if ($customer->privacy_accepted_at === null) {
            $customer->update(['privacy_accepted_at' => now()]);
        }

        return response()->json(['data' => $customer->apiProfile()]);
    }

    public function delete(Request $request): JsonResponse
    {
        DB::transaction(function () use ($request): void {
            $customer = Customer::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $customer->tokens()->delete();
            $customer->update([
                'google_sub' => hash('sha256', 'deleted:'.$customer->id.':'.$customer->google_sub),
                'email' => null,
                'email_verified' => false,
                'name' => null,
                'avatar_url' => null,
                'status' => 'DISABLED',
            ]);
            $customer->delete();
        });

        return response()->json(['message' => 'Akun kamu sudah dihapus ya.']);
    }
}
