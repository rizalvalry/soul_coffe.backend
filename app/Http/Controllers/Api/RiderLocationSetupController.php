<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\RiderLocationSetupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

class RiderLocationSetupController extends Controller
{
    public function __construct(private readonly RiderLocationSetupService $setups) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->is_active, 403);

        return response()->json(['data' => $this->setups->riders()]);
    }

    public function update(Request $request, User $rider): JsonResponse
    {
        abort_unless($request->user()->is_active, 403);

        $data = $request->validate([
            'location_id' => ['nullable', 'integer', Rule::exists('locations', 'id')],
            'basis' => ['required', Rule::in(['kitchen', 'selling_location'])],
        ]);
        try {
            $setup = $this->setups->save($request->user(), $rider, $data['location_id'] ?? null, $data['basis']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $setup]);
    }
}
