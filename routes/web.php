<?php

use App\Enums\Role;
use App\Http\Controllers\NewsInlineImageController;
use App\Models\CashTransaction;
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
    abort_unless(auth()->user()?->is_active && in_array(auth()->user()?->role, [Role::FINANCE, Role::MANAGEMENT], true), 403);
    abort_if($transaction->evidence_path === null
        || preg_match('~\Acash-evidence/[^/\\\\]+\z~D', $transaction->evidence_path) !== 1
        || str_contains($transaction->evidence_path, '..')
        || ! Storage::disk('local')->exists($transaction->evidence_path), 404);

    return Storage::disk('local')->download($transaction->evidence_path);
})->name('admin.cash-receipts.download');
