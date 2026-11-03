<?php

namespace App\Http\Controllers;

use App\Exceptions\ControlException;
use App\Models\Customer;
use App\Services\Audit;
use App\Services\Messaging\Notifier;
use App\Services\Messaging\ReminderService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * "Send a reminder now": for the moment a person has the customer on the phone
 * and wants the message to arrive as well. It sends what fits where the
 * subscription stands today, to the same people and on the same channels as the
 * morning run, and does not take the place of that run: it is its own message,
 * so the automatic reminder still goes out when it is due.
 */
class ReminderController extends Controller
{
    /** Minutes before the same customer can be sent another by hand. */
    private const COOLDOWN_MINUTES = 10;

    public function store(Customer $customer, ReminderService $reminders, Notifier $notifier)
    {
        abort_unless(Gate::any(['subscriptions.assign', 'billing.manage']), 403);

        $subscription = $customer->subscription()->with('package')->first()
            ?? throw new ControlException(__('control.no_subscription'));

        // Pressed twice, or by two people: once is enough.
        if (! Cache::add("reminder-now.{$customer->id}", true, now()->addMinutes(self::COOLDOWN_MINUTES))) {
            throw new ControlException(__('control.reminder.too_soon', ['minutes' => self::COOLDOWN_MINUTES]));
        }

        $today = ReminderService::today();
        $kind = $reminders->kindForNow($subscription, $today);

        $result = $notifier->notify($kind, $customer, [
            'package' => $subscription->package->name,
            'date' => $subscription->ends_on,
            'days' => max(0, $subscription->daysLeft($today)),
            'reason' => $subscription->block_reason ?: '-',
        ], 'manual-'.now()->timestamp.'-'.Str::random(6));

        Audit::record('message.sent_by_hand', $customer, null, ['kind' => $kind] + $result, $customer->id);

        if ($result['queued'] === 0) {
            // Nothing was sent: let them try again once the contact is fixed.
            Cache::forget("reminder-now.{$customer->id}");

            throw new ControlException(__('control.reminder.nobody'));
        }

        return back()->with('success', __('control.reminder.sent', ['count' => $result['queued']]));
    }
}
