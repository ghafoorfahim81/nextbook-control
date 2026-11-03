<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Customer;
use App\Services\Audit;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The people at a customer that the control center writes to. Reminders go to
 * the manager, the finance contact and the primary contact, on the channels
 * each of them has agreed to.
 */
class ContactController extends Controller
{
    public function store(Request $request, Customer $customer)
    {
        Gate::authorize('customers.manage');

        $data = $this->validated($request);

        DB::transaction(function () use ($customer, $data) {
            $contact = $customer->contacts()->create($data);
            $this->onlyOnePrimary($customer, $contact);
            Audit::record('contact.created', $contact, null, $contact->only(['name', 'role']), $customer->id);
        });

        return back()->with('success', __('control.saved'));
    }

    public function update(Request $request, Contact $contact)
    {
        Gate::authorize('customers.manage');

        $data = $this->validated($request);
        $fields = ['name', 'role', 'phone', 'email', 'whatsapp', 'is_primary', 'wants_email', 'wants_whatsapp'];
        $before = $contact->only($fields);

        DB::transaction(function () use ($contact, $data) {
            $contact->update($data);
            $this->onlyOnePrimary($contact->customer, $contact);
        });

        Audit::record('contact.updated', $contact, $before, $contact->only($fields), $contact->customer_id);

        return back()->with('success', __('control.saved'));
    }

    public function destroy(Contact $contact)
    {
        Gate::authorize('customers.manage');

        $before = $contact->only(['name', 'role']);
        $customerId = $contact->customer_id;
        $contact->delete();
        Audit::record('contact.removed', $contact, $before, null, $customerId);

        return back()->with('success', __('control.saved'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $number = fn (string $label) => function (string $attribute, mixed $value, \Closure $fail) use ($label) {
            if ($value !== null && $value !== '' && Phone::e164((string) $value, (string) config('control.messaging.default_country_code')) === null) {
                $fail(__('control.contact.bad_number', ['field' => $label]));
            }
        };

        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'role' => ['nullable', Rule::in(Contact::ROLES)],
            'phone' => ['nullable', 'string', 'max:40', $number('phone')],
            'whatsapp' => ['nullable', 'string', 'max:40', $number('whatsapp')],
            'email' => ['nullable', 'email', 'max:255', 'required_without_all:phone,whatsapp'],
            'is_primary' => ['boolean'],
            'wants_email' => ['boolean'],
            'wants_whatsapp' => ['boolean'],
        ]);
    }

    /** At most one primary contact per customer: the one just saved, if it is one. */
    private function onlyOnePrimary(Customer $customer, Contact $saved): void
    {
        if ($saved->is_primary) {
            $customer->contacts()->whereKeyNot($saved->id)->update(['is_primary' => false]);
        }
    }
}
