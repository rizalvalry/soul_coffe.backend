<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RiderClosing;
use App\Models\Settlement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class RiderDailySettlementController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware('role:STAFF')];
    }

    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()->is_active, 403);
        $data = $request->validate(['operating_date' => ['sometimes', 'date_format:Y-m-d']]);
        $date = $data['operating_date'] ?? today()->toDateString();

        $closing = RiderClosing::query()->with('cart:id,code')->where('rider_id', $request->user()->id)
            ->whereDate('operating_date', $date)->latest('id')->first();
        if (! $closing) {
            return response()->json(['data' => ['closing' => null, 'status' => null]]);
        }
        $settlement = Settlement::query()->where('cart_id', $closing->cart_id)
            ->whereDate('operating_date', $closing->operating_date->toDateString())->first();

        return response()->json(['data' => [
            'closing' => [
                'id' => $closing->id,
                'cart_code' => $closing->cart?->code,
                'operating_date' => $closing->operating_date->toDateString(),
                'total_minor' => $closing->total_minor,
                'cash_minor' => $closing->cash_minor,
                'qris_minor' => $closing->qris_minor,
                'transfer_minor' => $closing->transfer_minor,
            ],
            'status' => ! $settlement ? 'WAITING_FINANCE'
                : ($settlement->reconciled_at ? 'APPROVED' : 'RECORDED'),
            'settlement_status' => $settlement?->status,
        ]]);
    }
}
