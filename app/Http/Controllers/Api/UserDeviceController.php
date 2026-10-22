<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserDevice;
use App\Services\DeviceBindingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserDeviceController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => UserDevice::query()->with('user:id,name')
            ->latest()->limit(100)->get()->map(fn (UserDevice $device): array => [
                'id' => $device->id,
                'user' => $device->user?->name,
                'device_id' => $device->maskedId(),
                'device_name' => $device->device_name,
                'model' => $device->model,
                'status' => $device->status,
                'app_version' => $device->app_version,
                'last_login_at' => $device->last_login_at,
            ])]);
    }

    public function approve(Request $request, UserDevice $device, DeviceBindingService $binding): JsonResponse
    {
        return $this->decide($request, $device, $binding, 'approve');
    }

    public function reject(Request $request, UserDevice $device, DeviceBindingService $binding): JsonResponse
    {
        return $this->decide($request, $device, $binding, 'reject');
    }

    public function revoke(Request $request, UserDevice $device, DeviceBindingService $binding): JsonResponse
    {
        return $this->decide($request, $device, $binding, 'revoke');
    }

    private function decide(Request $request, UserDevice $device, DeviceBindingService $binding, string $action): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $binding->decide($device, $request->user(), $action, $data['reason']);

        return response()->json(['data' => ['id' => $device->id, 'status' => $device->fresh()->status]]);
    }
}
