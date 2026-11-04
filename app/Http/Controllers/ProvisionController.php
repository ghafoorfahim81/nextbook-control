<?php

namespace App\Http\Controllers;

use App\Exceptions\ControlException;
use App\Models\Customer;
use App\Services\Audit;
use App\Services\ErpProvisioner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Creates the customer's company in NextBook. Only the owner may. */
class ProvisionController extends Controller
{
    public function store(Request $request, Customer $customer, ErpProvisioner $provisioner)
    {
        Gate::authorize('customers.provision');

        $data = $request->validate([
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email', 'max:255'],
        ]);

        try {
            $result = $provisioner->provision($customer, $data['admin_name'], $data['admin_email']);
        } catch (ControlException $e) {
            return back()->withErrors(['provision' => $e->getMessage()]);
        }

        // Never the password (there is none), nor the token: only who and where.
        Audit::record('instance.provisioned', $customer->instance, null, [
            'erp_company_id' => $result['company_id'],
            'created' => $result['created'],
            'admin_email' => $data['admin_email'],
            'base_url' => $customer->instance->base_url,
        ], $customer->id);

        return back()->with('success', $result['created'] ? __('control.provisioned') : __('control.provisioned_again'));
    }
}
