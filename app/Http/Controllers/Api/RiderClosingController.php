<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\RiderClosing;
use App\Services\RiderClosingService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use RuntimeException;

class RiderClosingController extends Controller implements HasMiddleware
{
    public function __construct(private readonly RiderClosingService $closings) {}

    public static function middleware(): array
    {
        return [
            new Middleware('role:STAFF', only: ['draft', 'store']),
            new Middleware('role:FINANCE', only: ['correct']),
        ];
    }

    public function draft(Request $request): JsonResponse
    {
        try {
            return response()->json(['data' => $this->closings->draft($request->user())]);
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $assignment = $this->closings->assignment($request->user());

        try {
            $closing = $this->closings->record(
                $request->user(), Cart::query()->findOrFail($assignment->cart_id),
                $request->user(), Carbon::today(), $data['lines'], (int) $data['qris'],
                (int) $data['transfer'], $this->gps($data),
                idempotencyKey: $request->header('Idempotency-Key'),
            );
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->closings->draft($request->user())], $closing->wasRecentlyCreated ? 201 : 200);
    }

    public function correct(Request $request, RiderClosing $closing): JsonResponse
    {
        $data = $this->validated($request, true);

        try {
            $saved = $this->closings->record(
                $request->user(), $closing->cart, $closing->rider,
                $closing->operating_date, $data['lines'], (int) $data['qris'],
                (int) $data['transfer'], $this->gps($data, financeCorrection: true), $data['reason'],
                idempotencyKey: $request->header('Idempotency-Key'),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => [
            'closing_id' => $saved->id, 'total_minor' => $saved->total_minor,
            'cash_minor' => $saved->cash_minor, 'qris_minor' => $saved->qris_minor,
            'transfer_minor' => $saved->transfer_minor,
        ]]);
    }

    private function validated(Request $request, bool $finance = false): array
    {
        return $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.qty_remaining' => ['required', 'integer', 'min:0', 'max:9999'],
            'lines.*.qty_wasted' => ['required', 'integer', 'min:0', 'max:9999'],
            'qris' => ['required', 'integer', 'min:0', 'max:1000000000000'],
            'transfer' => ['required', 'integer', 'min:0', 'max:1000000000000'],
            'gps_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'gps_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'gps_unavailable' => ['sometimes', 'boolean'],
            'reason' => [$finance ? 'required' : 'nullable', 'string', 'max:225'],
        ]);
    }

    private function gps(array $data, bool $financeCorrection = false): array
    {
        $hasFix = isset($data['gps_lat'], $data['gps_lng']) && ! ($data['gps_unavailable'] ?? false);
        $financeKeepsPrevious = $financeCorrection && ! array_key_exists('gps_lat', $data)
            && ! array_key_exists('gps_lng', $data) && ! array_key_exists('gps_unavailable', $data);

        return [
            'lat' => $hasFix ? $data['gps_lat'] : null,
            'lng' => $hasFix ? $data['gps_lng'] : null,
            'unavailable' => ! $hasFix && ! $financeKeepsPrevious,
        ];
    }
}
