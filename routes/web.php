<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Auth\TwoFactorSetupController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerSubscriptionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InstanceController;
use App\Http\Controllers\PackageController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
});

// Between the password and the code: not signed in yet, but known.
Route::get('/two-factor-challenge', [TwoFactorChallengeController::class, 'show'])->name('two-factor.challenge');
Route::post('/two-factor-challenge', [TwoFactorChallengeController::class, 'store'])->middleware('throttle:20,1');

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    // Open to a signed-in account that has not set two-factor up: it must.
    Route::get('/two-factor-setup', [TwoFactorSetupController::class, 'show'])->name('two-factor.setup');
    Route::post('/two-factor-setup', [TwoFactorSetupController::class, 'store']);

    Route::middleware('two_factor')->group(function () {
        Route::get('/dashboard', DashboardController::class)->name('dashboard');
        Route::resource('customers', CustomerController::class)->except('destroy');
        Route::resource('packages', PackageController::class)->except(['show', 'destroy']);

        // What staff do to one customer: its subscription, its ERP connection, its money.
        Route::prefix('customers/{customer}')->group(function () {
            Route::post('subscription/trial', [CustomerSubscriptionController::class, 'trial'])->name('subscription.trial');
            Route::post('subscription/assign', [CustomerSubscriptionController::class, 'assign'])->name('subscription.assign');
            Route::post('subscription/renew', [CustomerSubscriptionController::class, 'renew'])->name('subscription.renew');
            Route::post('subscription/block', [CustomerSubscriptionController::class, 'block'])->name('subscription.block');
            Route::post('subscription/unblock', [CustomerSubscriptionController::class, 'unblock'])->name('subscription.unblock');
            Route::post('subscription/override', [CustomerSubscriptionController::class, 'override'])->name('subscription.override');
            Route::post('subscription/cancel', [CustomerSubscriptionController::class, 'cancel'])->name('subscription.cancel');

            Route::put('instance', [InstanceController::class, 'update'])->name('instance.update');
            Route::post('instance/token', [InstanceController::class, 'issueToken'])->name('instance.token');

            Route::post('invoices', [BillingController::class, 'storeInvoice'])->name('invoices.store');
        });
        Route::post('invoices/{invoice}/void', [BillingController::class, 'voidInvoice'])->name('invoices.void');
        Route::post('invoices/{invoice}/payments', [BillingController::class, 'storePayment'])->name('payments.store');
        Route::delete('payments/{payment}', [BillingController::class, 'destroyPayment'])->name('payments.destroy');
    });
});
