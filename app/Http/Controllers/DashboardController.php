<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Subscription;
use Illuminate\Support\Facades\Gate;

class DashboardController extends Controller
{
    public function __invoke()
    {
        Gate::authorize('reports.view');

        $subscriptions = Subscription::query()->with(['customer:id,name_en,name_fa', 'package:id,name'])->get();
        $states = $subscriptions->groupBy(fn (Subscription $s) => $s->state())->map->count();

        $ending = $subscriptions
            ->filter(fn (Subscription $s) => ! $s->isBlocked() && ! $s->isExpired() && $s->daysLeft() <= 30)
            ->sortBy('ends_on')
            ->take(10)
            ->map(fn (Subscription $s) => [
                'customer_id' => $s->customer_id,
                'customer' => $s->customer->displayName(),
                'package' => $s->package->name,
                'ends_on' => $s->ends_on->toDateString(),
                'days_left' => $s->daysLeft(),
            ])
            ->values();

        // What is owed, per currency: the amount less the discount, less what
        // has been paid, on every invoice that has not been voided.
        $receivable = [];
        $unpaid = 0;
        Invoice::query()->with('payments')->where('status', 'open')->get()->each(function (Invoice $invoice) use (&$receivable, &$unpaid) {
            if (bccomp($invoice->balance(), '0', 4) > 0) {
                $unpaid++;
                $receivable[$invoice->currency] = bcadd($receivable[$invoice->currency] ?? '0', $invoice->balance(), 4);
            }
        });

        return inertia('Dashboard', [
            'counts' => [
                'customers' => Customer::query()->count(),
                'active' => Customer::query()->where('status', 'active')->count(),
                'trial' => Customer::query()->where('status', 'trial')->count(),
                'expiring' => $subscriptions->filter(fn (Subscription $s) => $s->state() === 'expiring')->count(),
                'blocked' => ($states['blocked'] ?? 0) + ($states['expired'] ?? 0),
                'unpaid' => $unpaid,
                // Messages that did not get through: someone should look.
                'messages_failed' => \App\Models\Message::query()->where('status', 'failed')->count(),
            ],
            'receivable' => collect($receivable)->map(fn ($amount, $currency) => ['currency' => $currency, 'amount' => (float) $amount])->values(),
            'ending' => $ending,
        ]);
    }
}
