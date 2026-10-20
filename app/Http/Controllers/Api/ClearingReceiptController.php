<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\ClearingReceipt;
use App\Services\ClearingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class ClearingReceiptController extends Controller implements HasMiddleware
{
    public function __construct(private readonly ClearingService $clearing) {}

    public static function middleware(): array
    {
        return [new Middleware('role:FINANCE,MANAGEMENT')];
    }

    public function index(): JsonResponse
    {
        return response()->json(['data' => ClearingReceipt::query()->with('allocations')
            ->orderByDesc('received_on')->orderByDesc('id')->limit(100)->get()]);
    }

    public function options(): JsonResponse
    {
        return response()->json(['data' => ['bank_account_id' => Account::query()->where('code', '1-1300')->value('id'),
            'aging' => $this->clearing->aging()]]);
    }

    public function aging(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->clearing->aging($request->query('as_of'))]);
    }

    public function store(Request $request): JsonResponse
    {
        $receipt = $this->clearing->record($request->user(), $request->all());

        return response()->json(['data' => $receipt], 201);
    }

    public function void(Request $request, ClearingReceipt $receipt): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:225']]);

        return response()->json(['data' => $this->clearing->void($request->user(), $receipt, $data['reason'])]);
    }

    public function match(Request $request, ClearingReceipt $receipt): JsonResponse
    {
        return response()->json(['data' => $this->clearing->match($request->user(), $receipt, $request->all())]);
    }
}
