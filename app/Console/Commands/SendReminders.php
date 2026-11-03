<?php

namespace App\Console\Commands;

use App\Services\Messaging\ReminderService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * The daily run: queues a reminder for every customer whose subscription is
 * ending (or has just ended). Scheduled every morning in Kabul. Safe to run
 * again the same day: what was already sent is not sent twice.
 *
 *   php artisan control:reminders --dry-run     what would go out, without sending
 *   php artisan control:reminders --date=2026-11-10   as if it were that day
 */
class SendReminders extends Command
{
    protected $signature = 'control:reminders {--date= : Run as if it were this day (YYYY-MM-DD)} {--dry-run : Show what is due without queueing anything}';

    protected $description = 'Queue subscription reminders for customers whose subscription is ending';

    public function handle(ReminderService $reminders): int
    {
        $today = $this->option('date') ? Carbon::parse($this->option('date'))->startOfDay() : ReminderService::today();
        $dry = (bool) $this->option('dry-run');

        $summary = $reminders->run($today, $dry);

        $this->info(($dry ? '[dry run] ' : '')."{$today->toDateString()}: {$summary['checked']} subscription(s) checked, {$summary['due']} due.");
        foreach ($summary['lines'] as $line) {
            $this->line('  '.$line);
        }
        if (! $dry) {
            $this->line("Queued {$summary['queued']}, skipped {$summary['skipped']} (nobody to notify), already sent {$summary['duplicates']}.");
        }

        return self::SUCCESS;
    }
}
