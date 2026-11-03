<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Where a customer's NextBook runs, and the token that lets it ask for its licence. */
class InstanceController extends Controller
{
    public function update(Request $request, Customer $customer)
    {
        Gate::authorize('customers.manage');

        $data = $request->validate([
            'erp_company_id' => ['nullable', 'string', 'size:26'],
            'base_url' => ['nullable', 'url', 'max:255'],
        ]);

        $instance = $customer->instance()->firstOrNew([]);
        $before = $instance->only(['erp_company_id', 'base_url']);
        $instance->customer_id = $customer->id;
        $instance->fill($data)->save();
        Audit::record('instance.updated', $instance, $before, $instance->only(['erp_company_id', 'base_url']), $customer->id);

        return back()->with('success', __('control.saved'));
    }

    /**
     * Makes a new token, replacing the old one (which stops working at once).
     * The token is shown once, from the session, and never stored in the clear.
     */
    public function issueToken(Customer $customer)
    {
        Gate::authorize('customers.manage');

        $instance = $customer->instance()->firstOrCreate([]);
        $token = $instance->issueToken();
        Audit::record('instance.token_issued', $instance, null, null, $customer->id);

        return back()->with('issued_token', $token);
    }
}
