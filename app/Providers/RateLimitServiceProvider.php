<?php

namespace App\Providers;

use App\Support\PhoneNumber;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Named rate limiters for the three unauthenticated auth routes.
 *
 * TWO REASONS these exist instead of the inline `throttle:5,1` form they replace, both found by
 * probing the deployed API rather than by reading the code:
 *
 * 1. **The inline form shares one bucket across every throttled route on the domain.**
 *    `ThrottleRequests::resolveRequestSignature()` keys on the route's DOMAIN and the client IP —
 *    not the path. So three password attempts were enough to exhaust the "lupa PIN" allowance and
 *    answer 429 to a user who had not touched that endpoint. The tightest limit on the domain
 *    silently became the limit on all of them.
 *
 * 2. **The client IP is not the client.** This API is served through Cloudflare and the app does
 *    not declare trusted proxies, so `$request->ip()` is an edge address shared by every phone in
 *    the fleet. An IP-keyed limit is therefore closer to a global limit: one staff member
 *    fat-fingering their PIN would throttle everyone else at the same time.
 *
 * Both are fixed by keying on the ACCOUNT being targeted (the normalised phone) alongside the IP.
 * That is also the more useful control: brute force is aimed at one account, and this counts it
 * per account no matter which address it arrives from. The IP stays in the key so one account
 * cannot be locked out from a single hostile source without that source also being counted.
 *
 * The per-account PIN lockout in AuthController is a separate and stronger guard — it survives a
 * cache flush, which a rate limiter does not. These limits blunt volume; that one stops the attack.
 */
class RateLimitServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        RateLimiter::for('auth-login', fn (Request $request) => [
            Limit::perMinute(10)->by('auth-login:'.$this->accountKey($request)),
        ]);

        // Half the password allowance: six digits is a millionth of a password's search space.
        RateLimiter::for('auth-login-pin', fn (Request $request) => [
            Limit::perMinute(5)->by('auth-login-pin:'.$this->accountKey($request)),
        ]);

        // The tightest of the three: it writes a row and pushes every Administrator's phone. Three
        // per ten minutes per account is generous for a real person who has forgotten their PIN,
        // and useless as a way to spam the queue.
        RateLimiter::for('auth-pin-reset', fn (Request $request) => [
            Limit::perMinutes(10, 3)->by('auth-pin-reset:'.$this->accountKey($request)),
        ]);
    }

    /**
     * Account + source. Normalised through the same rule the login itself uses, so `08…`, `62…`
     * and `+62…` cannot be alternated to get three times the attempts on one account.
     */
    private function accountKey(Request $request): string
    {
        $phone = (string) $request->input('phone', '');
        $phone = $phone === '' ? 'unknown' : PhoneNumber::normalize($phone);

        return $phone.'|'.$request->ip();
    }
}
