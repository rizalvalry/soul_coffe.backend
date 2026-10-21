<?php

use App\Enums\Role;
use App\Http\Controllers\NewsInlineImageController;
use App\Models\CashTransaction;
use App\Models\PayrollItem;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', function () {
    return view('welcome');
});

// Session-guarded (Filament panel's own guard), not api/v1 — this is called from inside the
// admin panel's browser session, never from the mobile client.
Route::middleware(['web', 'auth'])
    ->post('/admin/news/inline-images', [NewsInlineImageController::class, 'store'])
    ->name('admin.news.inline-images.store');

Route::middleware('web')->get('/admin/cash-receipts/{transaction}', function (CashTransaction $transaction) {
    abort_unless(auth()->check(), 403);
    abort_unless(auth()->user()?->is_active && auth()->user()?->role->grants(Role::FINANCE), 403);
    abort_if($transaction->evidence_path === null
        || preg_match('~\Acash-evidence/[^/\\\\]+\z~D', $transaction->evidence_path) !== 1
        || str_contains($transaction->evidence_path, '..')
        || ! Storage::disk('local')->exists($transaction->evidence_path), 404);

    return Storage::disk('local')->download($transaction->evidence_path);
})->name('admin.cash-receipts.download');

Route::middleware(['web', 'auth'])->get('/admin/payroll-slips/{item}', function (PayrollItem $item) {
    abort_unless(auth()->user()?->is_active, 403);
    Gate::authorize('manage-payroll');
    $expectedPath = 'payroll-slips/'.$item->run_id.'/item-'.$item->id
        .($item->run->status === 'CANCELLED' ? '-cancelled' : '').'.pdf';
    $legacyPath = 'payroll-slips/'.$item->run_id.'/'.$item->user_id.'.pdf';
    abort_unless(in_array($item->run->status, ['APPROVED', 'PAID', 'CANCELLED'], true)
        && ($item->slip_path === $expectedPath
            || ($item->run->status !== 'CANCELLED' && $item->slip_path === $legacyPath))
        && Storage::disk('local')->exists($item->slip_path), 404);

    return Storage::disk('local')->download($item->slip_path, 'slip-gaji-'.$item->run->period_start->format('Y-m').'.pdf');
})->name('admin.payroll-slips.download');
