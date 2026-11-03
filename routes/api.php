<?php

use App\Http\Controllers\Api\InvoiceFeedController;
use App\Http\Controllers\Api\LicenseController;
use App\Http\Controllers\Api\UsageController;
use Illuminate\Support\Facades\Route;

/*
| What a customer's NextBook calls. Each ERP identifies itself with the token
| issued for its instance (Authorization: Bearer ...), and sees only its own
| customer: the licence it may work under, and a place to report what it uses.
*/
// The limit comes first: a request with a wrong token must count against it too,
// or a token could be guessed at the full speed of the server.
Route::middleware(['throttle:120,1', 'instance'])->group(function () {
    Route::get('/license', [LicenseController::class, 'show']);
    Route::get('/invoices', [InvoiceFeedController::class, 'index']);
    Route::post('/usage', [UsageController::class, 'store']);
});
