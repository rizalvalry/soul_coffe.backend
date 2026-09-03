<?php

use App\Http\Controllers\NewsInlineImageController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Session-guarded (Filament panel's own guard), not api/v1 — this is called from inside the
// admin panel's browser session, never from the mobile client.
Route::middleware(['web', 'auth'])
    ->post('/admin/news/inline-images', [NewsInlineImageController::class, 'store'])
    ->name('admin.news.inline-images.store');
