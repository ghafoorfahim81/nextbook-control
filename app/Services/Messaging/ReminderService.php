<?php

namespace App\Services\Messaging;

use App\Models\Subscription;
use Illuminate\Support\Carbon;

/**
 * Works out which customers are owed a reminder today, and queues it.
 *
 * Rules:
 *  - Only customers who are on trial or active. A cancelled or archived
 *    customer is not written to, and a blocked one has been told already.
 *  - A reminder goes out at 30, 14, 7 and 1 days before the end. If a day was
 *    missed (the scheduler was off), the next run still sends the one it
 *    missed, once: it picks the nearest threshold not yet passed.
 *  - "It has ended" is sent once, for a few days after the end. A customer
 *    that lapsed long ago is a matter for a person, not a reminder.
 *  - Each message is tied to the end date it is about, so after a renewal the
 *    next period gets its own reminders.
 */
class ReminderService
{
    public function __construct(private readonly Notifier $notifier)
    {
    }

    /** The date in the customer's own timezone (Kabul), as a plain date, so no time zone arithmetic touches it. */
    public static function today(): Carbon
    {
        return Carbon::parse(now(config('control.messaging.timezone'))->toDateString());
    }

    /**
     * What is due for a subscription today: the kind of message, or null.
     */
    public function kindFor(Subscription $subscription, Carbon $today): ?string
    {
        if ($subscription->isBlocked()) {
            return null;
        }

        $daysLeft = $subscription->daysLeft($today);

        if ($daysLeft >= 0) {
            $thresholds = collect(config('control.messaging.reminder_days'))->sort()->values();
            $threshold = $thresholds->first(fn (int $days) => $days >= $daysLeft);

            return $threshold === null ? null : "expiring_{$threshold}";
        }

        return $daysLeft >= -((int) config('control.messaging.expired_window_days')) ? 'expired' : null;
    }

    /**
     * What to write to a customer when a person asks for it now, whatever the
     * day: the message that fits where the subscription stands. Blocked gets the
     * blocked notice, one that has ended the "it has ended" message, and one
     * still running always an "ending" reminder (the nearest threshold, or the
     * first one when it is still further off), with the real number of days.
     */
    public function kindForNow(Subscription $subscription, Carbon $today): string
    {
        if ($subscription->isBlocked()) {
            return 'blocked';
        }

        $daysLeft = $subscription->daysLeft($today);
        if ($daysLeft < 0) {
            return 'expired';
        }

        $thresholds = collect(config('control.messaging.reminder_days'))->sort()->values();

        return 'expiring_'.($thresholds->first(fn (int $days) => $days >= $daysLeft) ?? $thresholds->last());
    }

    /**
     * @return array{checked: int, due: int, queued: int, skipped: int, duplicates: int, lines: array<int, string>}
     */
    public function run(?Carbon $today = null, bool $dryRun = false): array
    {
        $today ??= self::today();
        $summary = ['checked' => 0, 'due' => 0, 'queued' => 0, 'skipped' => 0, 'duplicates' => 0, 'lines' => []];

        Subscription::query()->with(['customer', 'package'])
            ->whereHas('customer', fn ($query) => $query->whereIn('status', ['trial', 'active']))
            ->get()
            ->each(function (Subscription $subscription) use ($today, $dryRun, &$summary) {
                $summary['checked']++;
                $kind = $this->kindFor($subscription, $today);
                if ($kind === null) {
                    return;
                }

                $summary['due']++;
                $name = $subscription->customer->displayName();

                if ($dryRun) {
                    $summary['lines'][] = "{$name}: {$kind} (ends {$subscription->ends_on->toDateString()})";

                    return;
                }

                $result = $this->notifier->notify($kind, $subscription->customer, [
                    'package' => $subscription->package->name,
                    'date' => $subscription->ends_on,
                    'days' => max(0, $subscription->daysLeft($today)),
                ], $subscription->ends_on->toDateString());

                foreach (['queued', 'skipped', 'duplicates'] as $key) {
                    $summary[$key] += $result[$key];
                }
                $summary['lines'][] = "{$name}: {$kind} → queued {$result['queued']}, skipped {$result['skipped']}, already sent {$result['duplicates']}";
            });

        return $summary;
    }
}
