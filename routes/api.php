<?php

use App\Http\Controllers\Api\AllocationController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BadgeController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\LoginPinController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\RefillRequestController;
use App\Http\Controllers\Api\RefillTransitionController;
use Illuminate\Support\Facades\Route;

/*
 * Soul Coffeemate API v1 — see docs/04-api-contract.md.
 *
 * The `apiPrefix: 'api/v1'` is set in bootstrap/app.php, so paths here are relative to that.
 *
 * `idempotent:require` is applied to every state transition (R12). It is not decoration: a
 * staff member on a failing connection who taps submit twice must end up with one request, and
 * the middleware is what guarantees that rather than hoping the UI disabled the button.
 *
 * Role authorisation is enforced inside the controllers/policies at the QUERY level, never by
 * filtering a response — see docs/02 §2.1. A route being reachable proves nothing about
 * whether the caller may act on the record.
 */

Route::post('auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:10,1'); // credential stuffing guard

// Tighter than the password route on purpose: a 6-digit PIN is a millionth of the search space
// a password is, so the network-level limit is halved. The per-account lockout in
// AuthController::loginWithPin is the guard that actually matters — this one only slows a
// single source down, and every staff phone on one carrier shares an address.
Route::post('auth/login-pin', [AuthController::class, 'loginWithPin'])
    ->middleware('throttle:5,1');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('me', [MeController::class, 'show']);

    // The optional PIN sign-in credential. Setting it re-checks the account password — see
    // LoginPinController for why a valid token alone is not enough to mint one.
    Route::post('me/login-pin', [LoginPinController::class, 'store']);
    Route::delete('me/login-pin', [LoginPinController::class, 'destroy']);

    // ── Master data (read-only for the mobile client) ───────────────────────
    Route::get('products', [ProductController::class, 'index']);
    Route::get('carts', [CartController::class, 'index']);
    Route::get('locations', [LocationController::class, 'index']);

    // ── Flow A — daily allocation (requirement 1) ──────────────────────────
    Route::get('allocations/today', [AllocationController::class, 'today']);
    Route::post('allocations', [AllocationController::class, 'store'])
        ->middleware('idempotent:require');
    Route::get('allocations/{allocation}', [AllocationController::class, 'show']);

    Route::get('me/allocation/today', [AllocationController::class, 'mine']);
    Route::get('me/stock', [AllocationController::class, 'myStock']);
    Route::get('kitchen/stock', [AllocationController::class, 'kitchenStock']);

    // ── Evidence & signature media ─────────────────────────────────────────
    // Upload happens BEFORE the request is created, so a refill without evidence
    // cannot exist in the database (R3, E4).
    Route::post('media/evidence', [MediaController::class, 'storeEvidence'])
        ->middleware('throttle:30,1');

    // ── Flow B — refill request (requirements 2-7) ─────────────────────────
    Route::get('refills', [RefillRequestController::class, 'index']);
    Route::get('refills/{refill}', [RefillRequestController::class, 'show']);
    Route::post('refills', [RefillRequestController::class, 'store'])
        ->middleware('idempotent:require');

    Route::prefix('refills/{refill}')
        ->middleware('idempotent:require')
        ->group(function (): void {
            Route::post('approve', [RefillTransitionController::class, 'approve']);
            Route::post('reject', [RefillTransitionController::class, 'reject']);
            Route::post('cancel', [RefillTransitionController::class, 'cancel']);
            // The requirement-4 gate. Returns 409 unless status is exactly APPROVED (R1).
            Route::post('start-preparing', [RefillTransitionController::class, 'startPreparing']);
            Route::post('ready', [RefillTransitionController::class, 'markReady']);
            Route::post('claim', [RefillTransitionController::class, 'claim']);
            Route::post('deliver', [RefillTransitionController::class, 'deliver']);
        });

    // ── Notifications & badges (requirement 3 support) ─────────────────────
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead']);
    Route::get('badges', [BadgeController::class, 'index']);
});
