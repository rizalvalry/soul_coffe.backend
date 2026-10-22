<?php

namespace App\Http\Middleware;

use App\Models\Customer;
use App\Services\Customer\GoogleIdTokenVerifier;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Revalidates the Google credential and account state before an idempotent login replay. */
class VerifyCustomerGoogleBeforeReplay
{
    public function __construct(private readonly GoogleIdTokenVerifier $verifier) {}

    public function handle(Request $request, Closure $next): Response
    {
        $data = $request->validate(['id_token' => ['required', 'string', 'max:8192']]);
        $identity = $this->verifier->verify($data['id_token']);
        $customer = Customer::withTrashed()->where('google_sub', $identity['sub'])->first();
        abort_if($customer?->trashed() || $customer?->status === 'DISABLED', 403,
            'Akun kamu sedang nonaktif. Hubungi tim Soul Coffee ya.');
        $request->attributes->set('customer_google_identity', $identity);

        return $next($request);
    }
}
