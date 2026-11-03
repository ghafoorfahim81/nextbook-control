<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Subscription reminders, every morning in Kabul. They are queued and sent by
// the queue worker (php artisan queue:work), so that has to be running too.
Schedule::command('control:reminders')
    ->name('control:reminders')
    ->dailyAt('08:00')
    ->timezone(config('control.messaging.timezone'))
    ->withoutOverlapping()
    ->onOneServer();
