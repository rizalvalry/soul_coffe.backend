<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PayrollItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MyPayrollSlipsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->is_active, 403);
        $items = PayrollItem::query()->where('user_id', $request->user()->id)
            ->whereHas('run', fn ($query) => $query->whereIn('status', ['APPROVED', 'PAID', 'CANCELLED']))
            ->with('run')->orderByDesc('id')->get();

        return response()->json(['data' => $items->map(fn (PayrollItem $item): array => [
            'id' => $item->id,
            'period' => $item->run->period_start->format('Y-m'),
            'status' => $item->run->status,
            'components' => $item->snapshot['components'],
            'gross_pay_minor' => $item->snapshot['gross_pay_minor'],
            'take_home_minor' => $item->snapshot['take_home_minor'],
            'download_url' => route('payroll.my-slips.download', $item),
        ])->all()]);
    }

    public function download(Request $request, PayrollItem $item): StreamedResponse
    {
        abort_unless($request->user()->is_active, 403);
        abort_unless($item->user_id === $request->user()->id, 403);
        abort_unless(in_array($item->run->status, ['APPROVED', 'PAID', 'CANCELLED'], true), 404);
        $expectedPath = 'payroll-slips/'.$item->run_id.'/item-'.$item->id
            .($item->run->status === 'CANCELLED' ? '-cancelled' : '').'.pdf';
        $legacyPath = 'payroll-slips/'.$item->run_id.'/'.$item->user_id.'.pdf';
        abort_unless(($item->slip_path === $expectedPath
            || ($item->run->status !== 'CANCELLED' && $item->slip_path === $legacyPath))
            && Storage::disk('local')->exists($item->slip_path), 404);

        return Storage::disk('local')->download($item->slip_path, 'slip-gaji-'.$item->run->period_start->format('Y-m').'.pdf');
    }
}
