<?php

namespace App\Http\Middleware;

use App\Models\Customer;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RejectCustomerFromStaffApi
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->bearerToken()) {
            $principal = $request->user('sanctum');
            if (! $request->is('api/v1/customer/*') && $principal instanceof Customer) {
                abort(403, 'Akun pelanggan tidak punya akses ke fitur staf.');
            }
            if ($request->is('api/v1/customer/*') && $principal instanceof User) {
                abort(403, 'Akun staf tidak punya akses ke fitur pelanggan.');
            }
        }

        return $next($request);
    }
}
