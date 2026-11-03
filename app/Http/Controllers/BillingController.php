<?php

namespace App\Http\Controllers;

use App\Exceptions\ControlException;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Invoices and payments of one customer. Finance and the owner only (billing.manage). */
class BillingController extends Controller
{
    public function __construct(private readonly SubscriptionService $subscriptions)
    {
    }

    public function storeInvoice(Request $request, Customer $customer)
    {
        Gate::authorize('billing.manage');

        $data = $request->validate([
            'issued_on' => ['required', 'date'],
            'due_on' => ['nullable', 'date'],
            'period_from' => ['nullable', 'date'],
            'period_to' => ['nullable', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $subscription = $customer->subscription ?? throw new ControlException(__('control.no_subscription'));
        $this->subscriptions->invoice($subscription, $data);

        return back()->with('success', __('control.saved'));
    }

    public function voidInvoice(Invoice $invoice)
    {
        Gate::authorize('billing.manage');

        $this->subscriptions->voidInvoice($invoice);

        return back()->with('success', __('control.saved'));
    }

    public function storePayment(Request $request, Invoice $invoice)
    {
        Gate::authorize('billing.manage');

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'paid_on' => ['required', 'date'],
            'method' => ['required', Rule::in(Payment::METHODS)],
            'reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $this->subscriptions->recordPayment($invoice, $data);

        return back()->with('success', __('control.saved'));
    }

    public function destroyPayment(Payment $payment)
    {
        Gate::authorize('billing.manage');

        $this->subscriptions->removePayment($payment);

        return back()->with('success', __('control.saved'));
    }
}
