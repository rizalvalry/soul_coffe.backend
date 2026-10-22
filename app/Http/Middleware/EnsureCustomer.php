<?php

namespace App\Http\Middleware;

use App\Models\Customer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCustomer
{
    public function handle(Request $request, Closure $next): Response
    {
        $customer = $request->user();
        abort_unless($customer instanceof Customer && $customer->tokenCan('customer'), 403);
        abort_unless($customer->status === 'ACTIVE' && $customer->deleted_at === null, 403,
            'Akun kamu sedang nonaktif. Hubungi tim Soul Coffee ya.');

        return $next($request);
    }
}
