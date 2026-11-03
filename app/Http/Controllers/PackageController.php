<?php

namespace App\Http\Controllers;

use App\Models\Package;
use App\Services\Audit;
use App\Support\ErpModules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * What the control center sells. A package is never deleted, only switched
 * off, so a customer who bought it keeps it; and a price is never rewritten,
 * a new one is added from a date, so an old invoice keeps its price.
 */
class PackageController extends Controller
{
    public function index()
    {
        Gate::authorize('packages.view');

        return inertia('Packages/Index', [
            'packages' => Package::query()->withCount('subscriptions')->orderBy('sort')->orderBy('name')->get()->map(fn (Package $package) => $this->row($package)),
            'erpModules' => ErpModules::AVAILABLE,
        ]);
    }

    public function create()
    {
        Gate::authorize('packages.manage');

        return inertia('Packages/Form', ['package' => null, 'erpModules' => ErpModules::AVAILABLE]);
    }

    public function store(Request $request)
    {
        Gate::authorize('packages.manage');

        $data = $request->validate($this->rules());

        $package = Package::query()->create($this->attributes($data));
        $package->prices()->create($this->priceOf($data));
        Audit::record('package.created', $package, null, $package->only(['code', 'name']) + ['price' => $data['price'], 'currency' => $data['currency']]);

        return redirect()->route('packages.index')->with('success', __('control.saved'));
    }

    public function edit(Package $package)
    {
        Gate::authorize('packages.manage');

        return inertia('Packages/Form', ['package' => $this->row($package, true), 'erpModules' => ErpModules::AVAILABLE]);
    }

    public function update(Request $request, Package $package)
    {
        Gate::authorize('packages.manage');

        $data = $request->validate($this->rules($package));
        $before = $package->only(['name', 'modules', 'max_users', 'max_branches', 'max_storage_mb', 'max_monthly_documents', 'is_active']);

        $package->update($this->attributes($data));

        // Changing a price adds one from a date; it never rewrites an old one.
        $current = $package->priceOn(now(), strtoupper($data['currency']));
        if (! $current || bccomp((string) $current->amount, (string) $data['price'], 4) !== 0 || $current->period_months !== (int) $data['period_months']) {
            $package->prices()->create($this->priceOf($data));
        }

        Audit::record('package.updated', $package, $before, $package->only(array_keys($before)));

        return redirect()->route('packages.index')->with('success', __('control.saved'));
    }

    /** @return array<string, mixed> */
    private function rules(?Package $package = null): array
    {
        return [
            'code' => ['required', 'string', 'max:40', 'alpha_dash', Rule::unique('packages', 'code')->ignore($package?->id)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'modules' => ['present', 'array'],
            'modules.*' => ['string', Rule::in(ErpModules::AVAILABLE)],
            'max_users' => ['nullable', 'integer', 'min:1'],
            'max_branches' => ['nullable', 'integer', 'min:1'],
            'max_storage_mb' => ['nullable', 'integer', 'min:1'],
            'max_monthly_documents' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['boolean'],
            'sort' => ['nullable', 'integer', 'min:0'],
            'price' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'period_months' => ['required', 'integer', 'min:1', 'max:60'],
            'price_effective_from' => ['nullable', 'date'],
        ];
    }

    /** @return array<string, mixed> */
    private function attributes(array $data): array
    {
        return [
            'code' => $data['code'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'modules' => array_values($data['modules']),
            'max_users' => $data['max_users'] ?? null,
            'max_branches' => $data['max_branches'] ?? null,
            'max_storage_mb' => $data['max_storage_mb'] ?? null,
            'max_monthly_documents' => $data['max_monthly_documents'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'sort' => $data['sort'] ?? 0,
        ];
    }

    /** @return array<string, mixed> */
    private function priceOf(array $data): array
    {
        return [
            'amount' => $data['price'],
            'currency' => strtoupper($data['currency']),
            'period_months' => (int) $data['period_months'],
            'effective_from' => $data['price_effective_from'] ?? now()->toDateString(),
        ];
    }

    /** @return array<string, mixed> */
    private function row(Package $package, bool $withPrices = false): array
    {
        $price = $package->priceOn(now());

        return $package->only([
            'id', 'code', 'name', 'description', 'modules', 'max_users', 'max_branches', 'max_storage_mb',
            'max_monthly_documents', 'is_active', 'sort',
        ]) + [
            'subscriptions_count' => $package->subscriptions_count ?? $package->subscriptions()->count(),
            'price' => $price ? (float) $price->amount : null,
            'currency' => $price?->currency ?? 'AFN',
            'period_months' => $price?->period_months ?? 12,
        ] + ($withPrices ? [
            'price_history' => $package->prices()->orderByDesc('effective_from')->orderByDesc('created_at')->get()
                ->map(fn ($p) => ['amount' => (float) $p->amount, 'currency' => $p->currency, 'period_months' => $p->period_months, 'effective_from' => $p->effective_from->toDateString()]),
        ] : []);
    }
}
