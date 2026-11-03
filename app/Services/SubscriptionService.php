<?php

namespace App\Services;

use App\Exceptions\ControlException;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionChange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Everything staff do to a customer's subscription: start it, renew it, change
 * the package, block it, bill it and take payment. The rules are the ones the
 * ERP already enforces on its side; here they decide, there they execute.
 *
 * Each change to a subscription bumps its licence version, writes a line in
 * its history and one in the audit trail, in one transaction.
 */
class SubscriptionService
{
    public const DEFAULT_TRIAL_DAYS = 14;

    /** A free trial: a package for a few days, the customer marked as on trial. */
    public function startTrial(Customer $customer, Package $package, int $days = self::DEFAULT_TRIAL_DAYS): Subscription
    {
        return $this->assign($customer, $package, now()->startOfDay(), now()->startOfDay()->addDays($days), 'trial');
    }

    /** Puts a customer on a package for a period, or changes its package or dates. */
    public function assign(Customer $customer, Package $package, Carbon $startsOn, Carbon $endsOn, string $kind = 'paid', ?string $notes = null): Subscription
    {
        if ($endsOn->lt($startsOn)) {
            throw new ControlException(__('control.ends_before_start'));
        }
        if (! in_array($kind, ['trial', 'paid'], true)) {
            throw new ControlException('Unknown subscription kind.');
        }

        return DB::transaction(function () use ($customer, $package, $startsOn, $endsOn, $kind, $notes) {
            $existing = Subscription::query()->where('customer_id', $customer->id)->first();
            $before = $existing?->only(['package_id', 'kind', 'starts_on', 'ends_on']);

            $subscription = $existing ?? new Subscription(['customer_id' => $customer->id, 'license_version' => 0]);
            $fromPackage = $existing?->package_id;
            $fromEnd = $existing?->ends_on;

            $subscription->fill([
                'package_id' => $package->id,
                'kind' => $kind,
                'starts_on' => $startsOn->toDateString(),
                'ends_on' => $endsOn->toDateString(),
                'notes' => $notes ?? $existing?->notes,
            ]);
            $subscription->license_version = $subscription->license_version + 1;
            $subscription->save();

            $customer->update(['status' => $kind === 'trial' ? 'trial' : 'active', 'cancelled_on' => null]);

            $this->log($subscription, $existing && $fromPackage !== $package->id ? 'package_changed' : 'started', $fromPackage, $package->id, $fromEnd, $subscription->ends_on);
            Audit::record('subscription.assigned', $subscription, $before, $subscription->only(['package_id', 'kind', 'starts_on', 'ends_on']));

            return $subscription->load('package');
        });
    }

    /**
     * Extends by the package's period, or by a number of months. Counts from
     * the end when it is still ahead, from today when it already passed: paying
     * early loses nothing, paying late does not give the gap back. A trial that
     * is renewed becomes a paid subscription.
     */
    public function renew(Subscription $subscription, ?int $months = null): Subscription
    {
        return DB::transaction(function () use ($subscription, $months) {
            $period = $months ?? $this->periodMonths($subscription->package);
            $from = $subscription->ends_on->isFuture() ? $subscription->ends_on->copy() : now()->startOfDay();
            $before = $subscription->ends_on;

            $subscription->forceFill([
                'ends_on' => $from->addMonthsNoOverflow($period)->toDateString(),
                'kind' => 'paid',
                'license_version' => $subscription->license_version + 1,
            ])->save();
            $subscription->customer()->update(['status' => 'active', 'cancelled_on' => null]);

            $this->log($subscription, 'renewed', null, null, $before, $subscription->ends_on);
            Audit::record('subscription.renewed', $subscription, ['ends_on' => $before->toDateString()], ['ends_on' => $subscription->ends_on->toDateString()]);

            return $subscription->refresh();
        });
    }

    public function block(Subscription $subscription, ?string $reason = null): Subscription
    {
        return DB::transaction(function () use ($subscription, $reason) {
            $subscription->forceFill([
                'blocked_at' => now(),
                'block_reason' => $reason,
                'license_version' => $subscription->license_version + 1,
            ])->save();

            $this->log($subscription, 'blocked', null, null, null, null, $reason);
            Audit::record('subscription.blocked', $subscription, null, ['reason' => $reason], reason: $reason);

            return $subscription->refresh();
        });
    }

    public function unblock(Subscription $subscription): Subscription
    {
        return DB::transaction(function () use ($subscription) {
            $subscription->forceFill([
                'blocked_at' => null,
                'block_reason' => null,
                'license_version' => $subscription->license_version + 1,
            ])->save();

            $this->log($subscription, 'unblocked');
            Audit::record('subscription.unblocked', $subscription);

            return $subscription->refresh();
        });
    }

    /**
     * Changes a limit for this customer only, on top of its package. A reason
     * is required: a customer whose limits differ from the package they bought
     * should always be explainable.
     *
     * @param  array<string, mixed>  $overrides  max_users, max_branches, max_storage_mb, max_monthly_documents, modules
     */
    public function override(Subscription $subscription, array $overrides, string $reason): Subscription
    {
        if (trim($reason) === '') {
            throw new ControlException(__('control.reason_required'));
        }

        $allowed = ['max_users', 'max_branches', 'max_storage_mb', 'max_monthly_documents', 'modules'];
        $overrides = array_intersect_key($overrides, array_flip($allowed));

        return DB::transaction(function () use ($subscription, $overrides, $reason) {
            $before = $subscription->overrides ?? [];
            $after = array_merge($before, $overrides);

            $subscription->forceFill([
                'overrides' => $after ?: null,
                'license_version' => $subscription->license_version + 1,
            ])->save();

            $this->log($subscription, 'overridden', null, null, null, null, $reason);
            Audit::record('subscription.overridden', $subscription, $before, $after, reason: $reason);

            return $subscription->refresh();
        });
    }

    /**
     * Ends the relationship. The subscription is closed as of yesterday, so the
     * customer is left able to read and export its data, never locked out of
     * it; archiving, and deleting later, are separate decisions.
     */
    public function cancel(Customer $customer, ?string $reason = null): Customer
    {
        return DB::transaction(function () use ($customer, $reason) {
            $subscription = Subscription::query()->where('customer_id', $customer->id)->first();
            if ($subscription) {
                $before = $subscription->ends_on;
                $yesterday = now()->startOfDay()->subDay();
                $subscription->forceFill([
                    'ends_on' => $before->gt($yesterday) ? $yesterday->toDateString() : $before->toDateString(),
                    'license_version' => $subscription->license_version + 1,
                ])->save();
                $this->log($subscription, 'cancelled', null, null, $before, $subscription->ends_on, $reason);
            }

            $customer->update(['status' => 'cancelled', 'cancelled_on' => now()->toDateString()]);
            Audit::record('customer.cancelled', $customer, null, ['reason' => $reason], reason: $reason);

            return $customer->refresh();
        });
    }

    // --- Money --------------------------------------------------------------

    /**
     * The package's price for a number of months, pro rata from the price of
     * one period: a six month renewal of a twelve month price is half of it.
     */
    public function priceForMonths(Package $package, int $months, ?Carbon $day = null, string $currency = 'AFN'): string
    {
        $price = $package->priceOn($day, $currency);
        if (! $price) {
            throw new ControlException(__('control.no_price', ['package' => $package->name, 'currency' => $currency]));
        }

        return bcmul((string) $price->amount, bcdiv((string) $months, (string) max(1, $price->period_months), 8), 4);
    }

    /** @param  array<string, mixed>  $data  issued_on, amount, and optionally due_on, period_from, period_to, discount, currency, description */
    public function invoice(Subscription $subscription, array $data): Invoice
    {
        return DB::transaction(function () use ($subscription, $data) {
            // One sequence across every customer: take a lock for the length
            // of the transaction so two invoices never read the same last number.
            DB::select('select pg_advisory_xact_lock(?)', [7001]);

            $issuedOn = Carbon::parse($data['issued_on']);
            $discount = (string) ($data['discount'] ?? 0);
            if (bccomp($discount, (string) $data['amount'], 4) > 0) {
                throw new ControlException(__('control.discount_too_big'));
            }

            $invoice = Invoice::query()->create([
                'number' => $this->nextNumber($issuedOn->year),
                'customer_id' => $subscription->customer_id,
                'subscription_id' => $subscription->id,
                'issued_on' => $issuedOn->toDateString(),
                'due_on' => $data['due_on'] ?? null,
                'period_from' => $data['period_from'] ?? null,
                'period_to' => $data['period_to'] ?? null,
                'amount' => $data['amount'],
                'discount' => $discount,
                'currency' => strtoupper($data['currency'] ?? 'AFN'),
                'description' => $data['description'] ?? null,
                'created_by' => Auth::id(),
            ]);

            Audit::record('invoice.created', $invoice, null, $invoice->only(['number', 'amount', 'discount', 'currency']));

            return $invoice;
        });
    }

    /** A wrong invoice is voided, never deleted; one that has been paid on must have its payments removed first. */
    public function voidInvoice(Invoice $invoice): Invoice
    {
        if ($invoice->payments()->exists()) {
            throw new ControlException(__('control.invoice_has_payments'));
        }

        $invoice->update(['status' => 'void']);
        Audit::record('invoice.voided', $invoice);

        return $invoice;
    }

    /** @param  array<string, mixed>  $data  amount, paid_on, method, and optionally reference, note */
    public function recordPayment(Invoice $invoice, array $data): Payment
    {
        return DB::transaction(function () use ($invoice, $data) {
            // Lock the invoice so two payments recorded together cannot
            // together go past what is owed.
            $invoice = Invoice::query()->with('payments')->lockForUpdate()->findOrFail($invoice->id);

            if ($invoice->status === 'void') {
                throw new ControlException(__('control.invoice_void'));
            }
            if (! in_array($data['method'], Payment::METHODS, true)) {
                throw new ControlException('Unknown payment method.');
            }
            if (bccomp((string) $data['amount'], '0', 4) <= 0) {
                throw new ControlException(__('control.payment_positive'));
            }
            if (bccomp((string) $data['amount'], $invoice->balance(), 4) > 0) {
                throw new ControlException(__('control.overpayment', ['balance' => $invoice->balance()]));
            }

            $payment = Payment::query()->create([
                'invoice_id' => $invoice->id,
                'customer_id' => $invoice->customer_id,
                'amount' => $data['amount'],
                'paid_on' => $data['paid_on'],
                'method' => $data['method'],
                'reference' => $data['reference'] ?? null,
                'note' => $data['note'] ?? null,
                'received_by' => Auth::id(),
            ]);

            Audit::record('payment.recorded', $payment, null, $payment->only(['amount', 'method', 'reference']));

            return $payment;
        });
    }

    // --- Internals ----------------------------------------------------------

    private function nextNumber(int $year): string
    {
        $prefix = "SUB-{$year}-";
        $last = Invoice::query()->where('number', 'like', $prefix.'%')->orderByDesc('number')->value('number');
        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    private function periodMonths(Package $package): int
    {
        return (int) ($package->prices()->orderByDesc('effective_from')->orderByDesc('created_at')->value('period_months') ?? 12);
    }

    private function log(Subscription $subscription, string $type, ?string $fromPackage = null, ?string $toPackage = null, $endsBefore = null, $endsAfter = null, ?string $reason = null): void
    {
        SubscriptionChange::query()->create([
            'subscription_id' => $subscription->id,
            'customer_id' => $subscription->customer_id,
            'type' => $type,
            'from_package_id' => $fromPackage,
            'to_package_id' => $toPackage,
            'ends_on_before' => $endsBefore,
            'ends_on_after' => $endsAfter,
            'reason' => $reason,
            'staff_id' => Auth::id(),
        ]);
    }
}
