<?php

namespace App\Http\Controllers;

use App\Exceptions\ControlException;
use App\Models\Customer;
use App\Models\Package;
use App\Services\SubscriptionService;
use App\Support\ErpModules;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * What staff do to one customer's subscription. Each action asks for its own
 * ability (sales assigns, finance blocks and renews, the owner everything),
 * and the rules themselves live in SubscriptionService.
 */
class CustomerSubscriptionController extends Controller
{
    public function __construct(private readonly SubscriptionService $subscriptions)
    {
    }

    /** A package that may be sold: active, or the one the customer is already on. */
    private function packageRule(Customer $customer): array
    {
        $current = $customer->subscription?->package_id;

        return ['required', Rule::exists('packages', 'id')->where(fn ($query) => $query->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('is_active', true)->when($current, fn ($inner) => $inner->orWhere('id', $current))))];
    }

    public function trial(Request $request, Customer $customer)
    {
        Gate::authorize('subscriptions.assign');

        $data = $request->validate(['package_id' => $this->packageRule($customer), 'days' => ['nullable', 'integer', 'min:1', 'max:90']]);

        $this->subscriptions->startTrial($customer, Package::query()->findOrFail($data['package_id']), (int) ($data['days'] ?? SubscriptionService::DEFAULT_TRIAL_DAYS));

        return back()->with('success', __('control.saved'));
    }

    public function assign(Request $request, Customer $customer)
    {
        Gate::authorize('subscriptions.assign');

        $data = $request->validate([
            'package_id' => $this->packageRule($customer),
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $this->subscriptions->assign(
            $customer,
            Package::query()->findOrFail($data['package_id']),
            Carbon::parse($data['starts_on']),
            Carbon::parse($data['ends_on']),
            'paid',
            $data['notes'] ?? null,
        );

        return back()->with('success', __('control.saved'));
    }

    /**
     * Extends the subscription. A renewal is money, so it comes with its
     * invoice: whoever may bill (finance, the owner) renews and the invoice is
     * made with it. Extending with no invoice at all, a free period, is the
     * owner's alone.
     */
    public function renew(Request $request, Customer $customer)
    {
        $data = $request->validate(['months' => ['nullable', 'integer', 'min:1', 'max:60'], 'invoice' => ['boolean'], 'currency' => ['nullable', 'string', 'size:3']]);

        Gate::authorize(! empty($data['invoice']) ? 'billing.manage' : 'subscriptions.renew_free');

        $subscription = $this->subscriptionOf($customer);

        $previousEnd = $subscription->ends_on->isFuture() ? $subscription->ends_on->copy() : now()->startOfDay();
        $months = $data['months'] ?? null;

        // Price first: a package with no price in the currency fails the whole
        // renewal rather than renewing without the invoice that was asked for.
        $amount = null;
        if (! empty($data['invoice'])) {
            $amount = $this->subscriptions->priceForMonths(
                $subscription->package,
                $months ?? (int) ($subscription->package->priceOn(now(), strtoupper($data['currency'] ?? $customer->base_currency))?->period_months ?? 12),
                now(),
                strtoupper($data['currency'] ?? $customer->base_currency),
            );
        }

        $renewed = $this->subscriptions->renew($subscription, $months);

        if ($amount !== null) {
            $this->subscriptions->invoice($renewed, [
                'issued_on' => now()->toDateString(),
                'due_on' => now()->addDays(14)->toDateString(),
                'period_from' => $previousEnd->toDateString(),
                'period_to' => $renewed->ends_on->toDateString(),
                'amount' => $amount,
                'currency' => strtoupper($data['currency'] ?? $customer->base_currency),
                'description' => $renewed->package->name,
            ]);
        }

        return back()->with('success', __('control.saved'));
    }

    public function block(Request $request, Customer $customer)
    {
        Gate::authorize('subscriptions.block');

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $this->subscriptions->block($this->subscriptionOf($customer), $data['reason'] ?? null);

        return back()->with('success', __('control.saved'));
    }

    public function unblock(Customer $customer)
    {
        Gate::authorize('subscriptions.block');

        $this->subscriptions->unblock($this->subscriptionOf($customer));

        return back()->with('success', __('control.saved'));
    }

    /**
     * Sets this customer's limits. The form shows the effective ones; only
     * those that differ from the package are kept as overrides, so a limit set
     * back to the package's own value stops being one.
     */
    public function override(Request $request, Customer $customer)
    {
        Gate::authorize('subscriptions.assign');

        $data = $request->validate([
            'max_users' => ['nullable', 'integer', 'min:1'],
            'max_branches' => ['nullable', 'integer', 'min:1'],
            'max_storage_mb' => ['nullable', 'integer', 'min:1'],
            'max_monthly_documents' => ['nullable', 'integer', 'min:1'],
            'modules' => ['present', 'array'],
            'modules.*' => ['string', Rule::in(ErpModules::AVAILABLE)],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $subscription = $this->subscriptionOf($customer);
        $package = $subscription->package;

        $overrides = [];
        foreach (['max_users', 'max_branches', 'max_storage_mb', 'max_monthly_documents'] as $key) {
            $value = $data[$key] ?? null;
            if ($value !== $package->{$key}) {
                $overrides[$key] = $value;
            }
        }
        $modules = array_values($data['modules']);
        if (array_diff($modules, $package->modules ?? []) || array_diff($package->modules ?? [], $modules)) {
            $overrides['modules'] = $modules;
        }

        $this->subscriptions->override($subscription, $overrides, $data['reason'], replace: true);

        return back()->with('success', __('control.saved'));
    }

    public function cancel(Request $request, Customer $customer)
    {
        Gate::authorize('subscriptions.assign');

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $this->subscriptions->cancel($customer, $data['reason'] ?? null);

        return back()->with('success', __('control.saved'));
    }

    private function subscriptionOf(Customer $customer)
    {
        return $customer->subscription()->with('package')->first() ?? throw new ControlException(__('control.no_subscription'));
    }
}
