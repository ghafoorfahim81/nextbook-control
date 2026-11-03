<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Services\Audit;
use App\Support\BusinessTypes;
use App\Support\ErpModules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('customers.view');

        $search = trim((string) $request->query('search', ''));
        $status = (string) $request->query('status', '');

        $customers = Customer::query()
            ->with('subscription.package:id,name')
            ->when($search !== '', fn ($query) => $query->where(function ($inner) use ($search) {
                $inner->where('name_en', 'ilike', "%{$search}%")
                    ->orWhere('name_fa', 'ilike', "%{$search}%")
                    ->orWhere('name_ps', 'ilike', "%{$search}%");
            }))
            ->when(in_array($status, Customer::STATUSES, true), fn ($query) => $query->where('status', $status))
            ->orderBy('name_en')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Customer $customer) => [
                'id' => $customer->id,
                'name' => $customer->displayName(),
                'status' => $customer->status,
                'province' => $customer->province,
                'package' => $customer->subscription?->package?->name,
                'state' => $customer->subscription?->state() ?? 'none',
                'ends_on' => $customer->subscription?->ends_on?->toDateString(),
            ]);

        return inertia('Customers/Index', [
            'customers' => $customers,
            'filters' => ['search' => $search, 'status' => $status],
            'statuses' => Customer::STATUSES,
        ]);
    }

    public function create()
    {
        Gate::authorize('customers.manage');

        return inertia('Customers/Form', ['customer' => null, 'businessTypes' => BusinessTypes::ALL]);
    }

    public function store(Request $request)
    {
        Gate::authorize('customers.manage');

        $customer = Customer::query()->create($this->validated($request) + ['status' => 'lead', 'created_by' => Auth::id()]);
        Audit::record('customer.created', $customer, null, $customer->only(['name_en', 'business_type']));

        return redirect()->route('customers.show', $customer)->with('success', __('control.saved'));
    }

    public function show(Request $request, Customer $customer)
    {
        Gate::authorize('customers.view');

        $customer->load(['subscription.package', 'instance', 'contacts']);

        $invoices = Invoice::query()->with('payments')->where('customer_id', $customer->id)
            ->orderByDesc('issued_on')->orderByDesc('number')->get()
            ->map(fn (Invoice $invoice) => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'issued_on' => $invoice->issued_on->toDateString(),
                'due_on' => $invoice->due_on?->toDateString(),
                'period_from' => $invoice->period_from?->toDateString(),
                'period_to' => $invoice->period_to?->toDateString(),
                'amount' => (float) $invoice->amount,
                'discount' => (float) $invoice->discount,
                'currency' => $invoice->currency,
                'description' => $invoice->description,
                'state' => $invoice->paymentState(),
                'paid' => (float) $invoice->paidAmount(),
                'balance' => (float) $invoice->balance(),
                'payments' => $invoice->payments->map(fn ($payment) => [
                    'id' => $payment->id,
                    'amount' => (float) $payment->amount,
                    'paid_on' => $payment->paid_on->toDateString(),
                    'method' => $payment->method,
                    'reference' => $payment->reference,
                ])->values(),
            ]);

        return inertia('Customers/Show', [
            // Shown once, right after it was made; the token is never stored in the clear.
            'issuedToken' => $request->session()->get('issued_token'),
            'packages' => Package::query()->where('is_active', true)->orderBy('sort')->orderBy('name')->get()
                ->map(fn (Package $package) => ['id' => $package->id, 'name' => $package->name, 'modules' => $package->modules, 'price' => $package->priceOn(now(), $customer->base_currency)?->amount !== null ? (float) $package->priceOn(now(), $customer->base_currency)->amount : null]),
            'erpModules' => ErpModules::AVAILABLE,
            'invoices' => $invoices,
            'changes' => $customer->subscription
                ? $customer->subscription->changes()->latest('created_at')->limit(10)->get(['type', 'reason', 'ends_on_before', 'ends_on_after', 'created_at'])
                : [],
            'usage' => $customer->usageSnapshots()->latest('taken_on')->first(['taken_on', 'users', 'branches', 'storage_mb', 'documents_this_month']),
            'customer' => $customer->only([
                'id', 'name_en', 'name_fa', 'name_ps', 'business_type', 'province', 'city', 'license_no',
                'base_currency', 'calendar_type', 'locale', 'status', 'notes',
            ]),
            'subscription' => $customer->subscription ? [
                'package' => $customer->subscription->package->name,
                'kind' => $customer->subscription->kind,
                'state' => $customer->subscription->state(),
                'starts_on' => $customer->subscription->starts_on->toDateString(),
                'ends_on' => $customer->subscription->ends_on->toDateString(),
                'days_left' => $customer->subscription->daysLeft(),
                'block_reason' => $customer->subscription->block_reason,
                'limits' => $customer->subscription->limits(),
                'package_limits' => $customer->subscription->package->only(['modules', 'max_users', 'max_branches', 'max_storage_mb', 'max_monthly_documents']),
                'has_overrides' => ! empty($customer->subscription->overrides),
                'license_version' => $customer->subscription->license_version,
            ] : null,
            'instance' => $customer->instance
                ? $customer->instance->only(['erp_company_id', 'base_url', 'erp_version', 'last_license_pull_at', 'last_usage_at']) + ['has_token' => $customer->instance->api_token_hash !== null]
                : null,
            'contacts' => $customer->contacts,
        ]);
    }

    public function edit(Customer $customer)
    {
        Gate::authorize('customers.manage');

        return inertia('Customers/Form', ['customer' => $customer, 'businessTypes' => BusinessTypes::ALL]);
    }

    public function update(Request $request, Customer $customer)
    {
        Gate::authorize('customers.manage');

        $before = $customer->only(array_keys($this->rules()));
        $customer->update($this->validated($request));
        Audit::record('customer.updated', $customer, $before, $customer->only(array_keys($before)));

        return redirect()->route('customers.show', $customer)->with('success', __('control.saved'));
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'name_en' => ['required', 'string', 'max:255'],
            'name_fa' => ['nullable', 'string', 'max:255'],
            'name_ps' => ['nullable', 'string', 'max:255'],
            'business_type' => ['nullable', Rule::in(BusinessTypes::ALL)],
            'province' => ['nullable', 'string', 'max:60'],
            'city' => ['nullable', 'string', 'max:60'],
            'license_no' => ['nullable', 'string', 'max:60'],
            'base_currency' => ['required', 'string', 'size:3'],
            'calendar_type' => ['required', Rule::in(['jalali', 'gregorian'])],
            'locale' => ['required', Rule::in(['fa', 'ps', 'en'])],
            'notes' => ['nullable', 'string'],
        ];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate($this->rules());
        $data['base_currency'] = strtoupper($data['base_currency']);

        return $data;
    }
}
