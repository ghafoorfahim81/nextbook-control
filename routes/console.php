<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// A sign that the scheduler is really being run every minute (by cron). It
// does nothing else: `php artisan control:doctor` reads it, because a cron that
// is missing or broken otherwise fails silently, and then no reminder is sent.
Schedule::call(fn () => Cache::put('control:scheduler-heartbeat', now()->timestamp, 3600))
    ->name('control:heartbeat')
    ->everyMinute();

// Subscription reminders, every morning in Kabul. They are queued and sent by
// the queue worker (php artisan queue:work), so that has to be running too.
Schedule::command('control:reminders')
    ->name('control:reminders')
    ->dailyAt('08:00')
    ->timezone(config('control.messaging.timezone'))
    ->withoutOverlapping()
    ->onOneServer();
