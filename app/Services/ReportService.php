<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\SubscriptionChange;
use App\Models\UsageSnapshot;
use App\Services\Messaging\ReminderService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Morilog\Jalali\Jalalian;

/**
 * The numbers the owner reads: what has come in, what is owed, who renews and
 * who does not, and who is using what they bought.
 *
 * Money is never added across currencies: every figure is kept per currency
 * (AFN beside USD), since there is no one honest rate to turn one into the
 * other. Months are Solar Hijri months for Persian and Pashto, calendar months
 * for English, so a month on the page is the month the reader means.
 */
class ReportService
{
    /** How much of a limit counts as "close to it". */
    public const NEAR_LIMIT = 0.8;

    /** @var array<string, string> */
    private const BUCKETS = ['current' => 'Not yet due', 'd30' => '1-30 days', 'd60' => '31-60 days', 'd60plus' => 'More than 60 days'];

    public function today(): Carbon
    {
        return ReminderService::today();
    }

    // --- Months ------------------------------------------------------------

    /**
     * The last $count months up to and including the current one, oldest first,
     * each with the dates it covers.
     *
     * @return array<int, array{key: string, from: string, to: string, jy: int, jm: int}>
     */
    public function months(int $count, string $locale, ?Carbon $today = null): array
    {
        $today ??= $this->today();
        $jalali = in_array($locale, ['fa', 'ps'], true);
        $months = [];

        if ($jalali) {
            $now = Jalalian::fromCarbon($today);
            [$y, $m] = [$now->getYear(), $now->getMonth()];
            for ($i = 0; $i < $count; $i++) {
                $nextY = $m === 12 ? $y + 1 : $y;
                $nextM = $m === 12 ? 1 : $m + 1;
                $from = (new Jalalian($y, $m, 1))->toCarbon();
                $to = (new Jalalian($nextY, $nextM, 1))->toCarbon()->subDay();
                array_unshift($months, ['key' => sprintf('%04d-%02d', $y, $m), 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'jy' => $y, 'jm' => $m]);
                [$y, $m] = $m === 1 ? [$y - 1, 12] : [$y, $m - 1];
            }

            return $months;
        }

        $cursor = $today->copy()->startOfMonth();
        for ($i = 0; $i < $count; $i++) {
            array_unshift($months, [
                'key' => $cursor->format('Y-m'), 'from' => $cursor->toDateString(), 'to' => $cursor->copy()->endOfMonth()->toDateString(),
                'jy' => 0, 'jm' => 0,
            ]);
            $cursor->subMonthNoOverflow();
        }

        return $months;
    }

    private function monthOf(array $months, string $date): ?int
    {
        foreach ($months as $i => $month) {
            if ($date >= $month['from'] && $date <= $month['to']) {
                return $i;
            }
        }

        return null;
    }

    // --- Revenue -----------------------------------------------------------

    /**
     * What was invoiced and what was collected, month by month, and the
     * recurring revenue of today.
     *
     * @return array<string, mixed>
     */
    public function revenue(string $locale, int $count = 12): array
    {
        $months = $this->months($count, $locale);
        $rows = array_map(fn ($month) => $month + ['invoiced' => [], 'collected' => []], $months);
        $currencies = [];

        // Collected: when the money arrived, on invoices that are not void.
        DB::table('payments')->join('invoices', 'invoices.id', '=', 'payments.invoice_id')
            ->where('invoices.status', 'open')
            ->whereBetween('payments.paid_on', [$months[0]['from'], $months[count($months) - 1]['to']])
            ->get(['payments.paid_on', 'payments.amount', 'invoices.currency'])
            ->each(function ($payment) use ($months, &$rows, &$currencies) {
                $i = $this->monthOf($months, substr((string) $payment->paid_on, 0, 10));
                if ($i !== null) {
                    $currencies[$payment->currency] = true;
                    $rows[$i]['collected'][$payment->currency] = bcadd($rows[$i]['collected'][$payment->currency] ?? '0', (string) $payment->amount, 4);
                }
            });

        // Invoiced: what was billed (the amount less its discount) when it was issued.
        Invoice::query()->where('status', 'open')
            ->whereBetween('issued_on', [$months[0]['from'], $months[count($months) - 1]['to']])
            ->get()
            ->each(function (Invoice $invoice) use ($months, &$rows, &$currencies) {
                $i = $this->monthOf($months, $invoice->issued_on->toDateString());
                if ($i !== null) {
                    $currencies[$invoice->currency] = true;
                    $rows[$i]['invoiced'][$invoice->currency] = bcadd($rows[$i]['invoiced'][$invoice->currency] ?? '0', $invoice->payable(), 4);
                }
            });

        $mrr = $this->recurring();
        foreach (array_keys($mrr['by_currency']) as $currency) {
            $currencies[$currency] = true;
        }

        $currencies = array_keys($currencies);
        sort($currencies);

        return [
            'months' => $rows,
            'currencies' => $currencies,
            'recurring' => $mrr,
        ];
    }

    /**
     * Monthly recurring revenue: the list price of each paying subscription, in
     * its own currency, divided over its period. Only a subscription that is
     * paid for and running counts: not a trial, not one that has ended, been
     * blocked or been cancelled. It is the price list, not what was actually
     * billed after discounts.
     *
     * @return array{by_currency: array<string, array{monthly: string, annual: string, customers: int}>, by_province: array<int, array<string, mixed>>, unpriced: int}
     */
    public function recurring(?Carbon $today = null): array
    {
        $today ??= $this->today();
        $byCurrency = [];
        $byProvince = [];
        $unpriced = 0;

        Subscription::query()->with(['customer', 'package'])->where('kind', 'paid')
            ->whereHas('customer', fn ($q) => $q->whereIn('status', ['active']))
            ->get()
            ->each(function (Subscription $subscription) use ($today, &$byCurrency, &$byProvince, &$unpriced) {
                if ($subscription->isBlocked() || $subscription->isExpired($today)) {
                    return;
                }

                $currency = $subscription->customer->base_currency;
                $price = $subscription->package->priceOn($today, $currency);
                if (! $price) {
                    $unpriced++;

                    return;
                }

                $monthly = bcdiv((string) $price->amount, (string) max(1, $price->period_months), 4);
                $byCurrency[$currency]['monthly'] = bcadd($byCurrency[$currency]['monthly'] ?? '0', $monthly, 4);
                $byCurrency[$currency]['customers'] = ($byCurrency[$currency]['customers'] ?? 0) + 1;

                $province = $subscription->customer->province ?: '—';
                $byProvince[$province][$currency] = bcadd($byProvince[$province][$currency] ?? '0', $monthly, 4);
                $byProvince[$province]['customers'] = ($byProvince[$province]['customers'] ?? 0) + 1;
            });

        foreach ($byCurrency as $currency => $row) {
            $byCurrency[$currency]['annual'] = bcmul($row['monthly'], '12', 4);
        }
        ksort($byCurrency);

        $provinces = collect($byProvince)->map(fn ($row, $province) => ['province' => $province, 'customers' => $row['customers'], 'monthly' => collect($row)->except('customers')->all()])
            ->sortByDesc('customers')->values()->all();

        return ['by_currency' => $byCurrency, 'by_province' => $provinces, 'unpriced' => $unpriced];
    }

    // --- Receivables -------------------------------------------------------

    /**
     * What is owed, by how late it is. An invoice is late from its due date, or
     * from the day it was issued when it has none.
     *
     * @return array{rows: array<int, array<string, mixed>>, totals: array<string, array<string, string>>, buckets: array<int, string>}
     */
    public function receivables(?Carbon $today = null): array
    {
        $today ??= $this->today();
        $rows = [];
        $totals = [];

        Invoice::query()->with(['payments', 'customer'])->where('status', 'open')->get()->each(function (Invoice $invoice) use ($today, &$rows, &$totals) {
            $balance = $invoice->balance();
            if (bccomp($balance, '0', 4) <= 0) {
                return;
            }

            $due = ($invoice->due_on ?? $invoice->issued_on);
            $late = (int) $due->diffInDays($today, false);   // positive once the due date has passed
            $bucket = match (true) {
                $late <= 0 => 'current',
                $late <= 30 => 'd30',
                $late <= 60 => 'd60',
                default => 'd60plus',
            };

            $key = $invoice->customer_id.'|'.$invoice->currency;
            $rows[$key] ??= [
                'customer_id' => $invoice->customer_id, 'customer' => $invoice->customer->displayName(), 'currency' => $invoice->currency,
                'current' => '0', 'd30' => '0', 'd60' => '0', 'd60plus' => '0', 'total' => '0', 'oldest_days' => 0, 'invoices' => 0,
            ];
            $rows[$key][$bucket] = bcadd($rows[$key][$bucket], $balance, 4);
            $rows[$key]['total'] = bcadd($rows[$key]['total'], $balance, 4);
            $rows[$key]['oldest_days'] = max($rows[$key]['oldest_days'], $late);
            $rows[$key]['invoices']++;

            $totals[$invoice->currency] ??= ['current' => '0', 'd30' => '0', 'd60' => '0', 'd60plus' => '0', 'total' => '0'];
            $totals[$invoice->currency][$bucket] = bcadd($totals[$invoice->currency][$bucket], $balance, 4);
            $totals[$invoice->currency]['total'] = bcadd($totals[$invoice->currency]['total'], $balance, 4);
        });

        // The ones owed longest, and the most, first.
        $rows = collect($rows)->sort(fn ($a, $b) => [$b['oldest_days'], (float) $b['total']] <=> [$a['oldest_days'], (float) $a['total']])->values()->all();
        ksort($totals);

        return ['rows' => $rows, 'totals' => $totals, 'buckets' => array_keys(self::BUCKETS)];
    }

    // --- Renewals and churn ------------------------------------------------

    /**
     * For each month: how many subscription periods came to an end, and how
     * many of those were renewed. A period "ended" in a month if its end date
     * fell in it; it was renewed if a renewal was made on it, and lapsed if it
     * stands to this day with that end date and that date is behind us.
     *
     * Trials are left out: they are a sale being made, not a customer leaving.
     *
     * @return array<string, mixed>
     */
    public function renewals(string $locale, int $count = 6, ?Carbon $today = null): array
    {
        $today ??= $this->today();
        $months = $this->months($count, $locale, $today);
        $rows = array_map(fn ($month) => $month + ['renewed' => 0, 'lapsed' => 0, 'rate' => null], $months);

        SubscriptionChange::query()->where('type', 'renewed')->whereNotNull('ends_on_before')
            ->whereBetween('ends_on_before', [$months[0]['from'], $months[count($months) - 1]['to']])
            ->get(['ends_on_before'])
            ->each(function ($change) use ($months, &$rows) {
                $i = $this->monthOf($months, $change->ends_on_before->toDateString());
                if ($i !== null) {
                    $rows[$i]['renewed']++;
                }
            });

        Subscription::query()->where('kind', 'paid')
            ->whereBetween('ends_on', [$months[0]['from'], min($months[count($months) - 1]['to'], $today->copy()->subDay()->toDateString())])
            ->get(['ends_on'])
            ->each(function ($subscription) use ($months, &$rows) {
                $i = $this->monthOf($months, $subscription->ends_on->toDateString());
                if ($i !== null) {
                    $rows[$i]['lapsed']++;
                }
            });

        foreach ($rows as &$row) {
            $ended = $row['renewed'] + $row['lapsed'];
            $row['rate'] = $ended > 0 ? round($row['renewed'] / $ended * 100) : null;
        }
        unset($row);

        // Who to call: ended in the last 60 days and not renewed.
        $lapsed = Subscription::query()->with(['customer.contacts', 'package'])->where('kind', 'paid')
            ->whereBetween('ends_on', [$today->copy()->subDays(60)->toDateString(), $today->copy()->subDay()->toDateString()])
            ->orderByDesc('ends_on')->get()
            ->map(function (Subscription $subscription) use ($today) {
                $contact = $subscription->customer->contacts->sortByDesc('is_primary')->first();

                return [
                    'customer_id' => $subscription->customer_id,
                    'customer' => $subscription->customer->displayName(),
                    'package' => $subscription->package->name,
                    'ends_on' => $subscription->ends_on->toDateString(),
                    'days_ago' => (int) $subscription->ends_on->diffInDays($today),
                    'state' => $subscription->state($today),
                    'customer_status' => $subscription->customer->status,
                    'contact' => $contact ? trim($contact->name.' '.($contact->whatsapp ?: $contact->phone ?: $contact->email)) : null,
                ];
            })->values()->all();

        $ended = array_sum(array_column($rows, 'renewed')) + array_sum(array_column($rows, 'lapsed'));

        return [
            'months' => $rows,
            'lapsed' => $lapsed,
            'overall_rate' => $ended > 0 ? round(array_sum(array_column($rows, 'renewed')) / $ended * 100) : null,
            'cancelled' => Customer::query()->where('status', 'cancelled')->count(),
        ];
    }

    // --- Usage -------------------------------------------------------------

    /**
     * What each package's customers actually use, from the last counters their
     * NextBook reported, and who is close to a limit (a customer to talk to
     * about a bigger package).
     *
     * @return array<string, mixed>
     */
    public function usage(): array
    {
        $latest = UsageSnapshot::query()
            ->whereIn('id', UsageSnapshot::query()->selectRaw('(array_agg(id order by taken_on desc, id desc))[1]')->groupBy('customer_id'))
            ->get()->keyBy('customer_id');

        $packages = [];
        $near = [];
        $silent = 0;

        Subscription::query()->with(['customer', 'package'])
            ->whereHas('customer', fn ($q) => $q->whereIn('status', ['trial', 'active']))
            ->get()
            ->each(function (Subscription $subscription) use ($latest, &$packages, &$near, &$silent) {
                $package = $subscription->package;
                $row = &$packages[$package->id];
                $row ??= ['package' => $package->name, 'customers' => 0, 'reported' => 0, 'users' => 0, 'branches' => 0];
                $row['customers']++;

                $snapshot = $latest->get($subscription->customer_id);
                if (! $snapshot) {
                    $silent++;

                    return;
                }

                $row['reported']++;
                $row['users'] += $snapshot->users;
                $row['branches'] += $snapshot->branches;

                $limits = $subscription->limits();
                $closeTo = [];
                foreach (['users' => 'max_users', 'branches' => 'max_branches'] as $what => $limit) {
                    if ($limits[$limit] && $snapshot->{$what} / $limits[$limit] >= self::NEAR_LIMIT) {
                        $closeTo[] = ['what' => $what, 'used' => $snapshot->{$what}, 'limit' => $limits[$limit]];
                    }
                }
                if ($closeTo) {
                    $near[] = [
                        'customer_id' => $subscription->customer_id, 'customer' => $subscription->customer->displayName(),
                        'package' => $package->name, 'close_to' => $closeTo, 'reported_on' => $snapshot->taken_on->toDateString(),
                    ];
                }
            });

        $rows = collect($packages)->map(fn ($row) => $row + [
            'avg_users' => $row['reported'] ? round($row['users'] / $row['reported'], 1) : null,
            'avg_branches' => $row['reported'] ? round($row['branches'] / $row['reported'], 1) : null,
        ])->sortByDesc('customers')->values()->all();

        return ['packages' => $rows, 'near_limit' => $near, 'never_reported' => $silent];
    }

    /** The package names with no customer, so an unused one is noticed. */
    public function unusedPackages(): array
    {
        return Package::query()->where('is_active', true)->doesntHave('subscriptions')->pluck('name')->all();
    }
}
