<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\UsageSnapshot;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesData;
use Tests\TestCase;

/** Packages, one customer's subscription, its money and its ERP connection, through the screens' own routes. */
class ManagementTest extends TestCase
{
    use MakesData;
    use RefreshDatabase;

    private function packageForm(array $overrides = []): array
    {
        return $overrides + [
            'code' => 'pro', 'name' => 'Pro', 'modules' => ['hr', 'expenses'], 'max_users' => 10, 'max_branches' => 3,
            'is_active' => true, 'price' => 5000, 'currency' => 'afn', 'period_months' => 12,
        ];
    }

    private function subscribed(string $endsIn = '+1 year', array $package = []): Subscription
    {
        $this->actingAs($this->staff('owner'));

        return app(SubscriptionService::class)->assign($this->customer(), $this->package($package), now()->startOfDay(), now()->modify($endsIn)->startOfDay());
    }

    // --- Packages ----------------------------------------------------------

    public function test_the_owner_creates_a_package_with_its_first_price(): void
    {
        $this->actingAs($this->staff('owner'))->post('/packages', $this->packageForm())->assertRedirect()->assertSessionHasNoErrors();

        $package = Package::query()->firstOrFail();
        $this->assertSame(['hr', 'expenses'], $package->modules);
        $this->assertSame('5000.0000', $package->priceOn(now())->amount);
        $this->assertSame('AFN', $package->priceOn(now())->currency);
        $this->assertDatabaseHas('audit_logs', ['action' => 'package.created']);
    }

    public function test_only_roles_with_the_ability_may_manage_packages(): void
    {
        foreach (['sales', 'finance', 'support', 'viewer'] as $role) {
            $this->actingAs($this->staff($role))->post('/packages', $this->packageForm(['code' => $role]))->assertForbidden();
        }
        $this->assertSame(0, Package::query()->count());

        // Reading the list is open to them.
        $this->actingAs($this->staff('sales'))->get('/packages')->assertOk();
    }

    public function test_a_package_is_validated(): void
    {
        $owner = $this->actingAs($this->staff('owner'));
        $this->package(['code' => 'taken']);

        $owner->post('/packages', $this->packageForm(['code' => 'taken', 'modules' => ['casino'], 'price' => -1, 'currency' => 'AFGHANI', 'period_months' => 0]))
            ->assertSessionHasErrors(['code', 'modules.0', 'price', 'currency', 'period_months']);
    }

    public function test_changing_a_price_adds_one_and_leaves_the_old_one_in_place(): void
    {
        $owner = $this->actingAs($this->staff('owner'));
        $package = $this->package(['code' => 'pro']);
        $form = $this->packageForm(['code' => 'pro', 'name' => 'Starter', 'price' => 1200]);

        // Same price: nothing is added.
        $owner->put("/packages/{$package->id}", $form)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, $package->prices()->count());

        // New price from a later date: added, and the old one still applies before it.
        $owner->put("/packages/{$package->id}", array_merge($form, ['price' => 1500, 'price_effective_from' => now()->addMonth()->toDateString()]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(2, $package->prices()->count());
        $this->assertSame('1200.0000', $package->priceOn(now())->amount);
        $this->assertSame('1500.0000', $package->priceOn(now()->addMonths(2))->amount);
    }

    public function test_a_package_can_be_switched_off_and_is_then_not_for_sale_but_stays_with_those_who_have_it(): void
    {
        $owner = $this->actingAs($this->staff('owner'));
        $subscription = $this->subscribed();
        $package = $subscription->package;

        $owner->put("/packages/{$package->id}", $this->packageForm(['code' => $package->code, 'is_active' => false, 'price' => 1200]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertFalse($package->fresh()->is_active);

        // Not offered to another customer…
        $other = $this->customer(['name_en' => 'Other']);
        $owner->post("/customers/{$other->id}/subscription/trial", ['package_id' => $package->id])->assertSessionHasErrors('package_id');

        // …but the customer already on it can still be moved within it.
        $owner->post("/customers/{$subscription->customer_id}/subscription/assign", [
            'package_id' => $package->id, 'starts_on' => now()->toDateString(), 'ends_on' => now()->addYear()->toDateString(),
        ])->assertRedirect()->assertSessionHasNoErrors();
    }

    // --- Subscription actions ----------------------------------------------

    public function test_sales_starts_a_trial_and_assigns_a_package(): void
    {
        $sales = $this->actingAs($this->staff('sales'));
        $package = $this->package();
        $customer = $this->customer();

        $sales->post("/customers/{$customer->id}/subscription/trial", ['package_id' => $package->id, 'days' => 10])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('trial', $customer->fresh()->status);
        $this->assertSame(10, $customer->subscription()->first()->daysLeft());

        $sales->post("/customers/{$customer->id}/subscription/assign", [
            'package_id' => $package->id, 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('paid', $customer->subscription()->first()->kind);
        $this->assertSame('active', $customer->fresh()->status);
    }

    public function test_an_end_before_the_start_is_refused_with_a_message_and_nothing_is_saved(): void
    {
        $customer = $this->customer();

        $this->actingAs($this->staff('sales'))
            ->post("/customers/{$customer->id}/subscription/assign", ['package_id' => $this->package()->id, 'starts_on' => '2026-06-01', 'ends_on' => '2026-01-01'])
            ->assertSessionHas('error');

        $this->assertSame(0, Subscription::query()->count());
    }

    public function test_who_may_renew_and_who_may_bill_with_it(): void
    {
        $subscription = $this->subscribed('+10 days');
        $url = "/customers/{$subscription->customer_id}/subscription/renew";

        $this->actingAs($this->staff('support'))->post($url)->assertForbidden();
        $this->actingAs($this->staff('viewer'))->post($url)->assertForbidden();

        // Sales may renew, but not bill: asking for the invoice too is refused, and nothing is renewed.
        $this->actingAs($this->staff('sales'))->post($url, ['invoice' => true])->assertForbidden();
        $this->assertSame(now()->addDays(10)->toDateString(), $subscription->fresh()->ends_on->toDateString());

        $this->actingAs($this->staff('sales'))->post($url)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(now()->addDays(10)->addYear()->toDateString(), $subscription->fresh()->ends_on->toDateString());
        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_finance_renews_with_an_invoice_for_the_new_period_pro_rata(): void
    {
        $subscription = $this->subscribed('+3 days'); // the package costs 1200 for 12 months

        $this->actingAs($this->staff('finance'))
            ->post("/customers/{$subscription->customer_id}/subscription/renew", ['months' => 6, 'invoice' => true])
            ->assertRedirect()->assertSessionHasNoErrors();

        $invoice = Invoice::query()->firstOrFail();
        $this->assertSame('600.0000', $invoice->amount);
        $this->assertSame(now()->addDays(3)->toDateString(), $invoice->period_from->toDateString());
        $this->assertSame($subscription->fresh()->ends_on->toDateString(), $invoice->period_to->toDateString());
        $this->assertMatchesRegularExpression('/^SUB-\d{4}-0001$/', $invoice->number);
    }

    public function test_a_renewal_that_cannot_be_priced_changes_nothing(): void
    {
        $subscription = $this->subscribed('+3 days');

        $this->actingAs($this->staff('finance'))
            ->post("/customers/{$subscription->customer_id}/subscription/renew", ['invoice' => true, 'currency' => 'USD'])
            ->assertSessionHas('error');

        $this->assertSame(now()->addDays(3)->toDateString(), $subscription->fresh()->ends_on->toDateString(), 'not renewed without its invoice');
        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_finance_blocks_and_unblocks_and_sales_cannot(): void
    {
        $subscription = $this->subscribed();
        $base = "/customers/{$subscription->customer_id}/subscription";

        $this->actingAs($this->staff('sales'))->post("$base/block", ['reason' => 'x'])->assertForbidden();
        $this->actingAs($this->staff('support'))->post("$base/block")->assertForbidden();

        $this->actingAs($this->staff('finance'))->post("$base/block", ['reason' => 'Unpaid invoice'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('blocked', $subscription->fresh()->state());
        $this->assertSame('Unpaid invoice', $subscription->fresh()->block_reason);

        $this->actingAs($this->staff('finance'))->post("$base/unblock")->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNotSame('blocked', $subscription->fresh()->state());
    }

    public function test_limits_for_one_customer_keep_only_what_differs_from_the_package_and_need_a_reason(): void
    {
        $subscription = $this->subscribed(package: ['max_users' => 3, 'max_branches' => 1, 'modules' => ['expenses']]);
        $url = "/customers/{$subscription->customer_id}/subscription/override";
        $sales = $this->actingAs($this->staff('sales'));

        $sales->post($url, ['max_users' => 8, 'max_branches' => 1, 'modules' => ['expenses'], 'reason' => ''])->assertSessionHasErrors('reason');

        $sales->post($url, ['max_users' => 8, 'max_branches' => 1, 'modules' => ['expenses', 'hr'], 'reason' => 'Two extra seats and HR'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(['max_users' => 8, 'modules' => ['expenses', 'hr']], $subscription->fresh()->overrides);

        // Back to the package's own values: nothing is left as an override.
        $sales->post($url, ['max_users' => 3, 'max_branches' => 1, 'modules' => ['expenses'], 'reason' => 'Back to standard'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($subscription->fresh()->overrides);

        // Blank means unlimited.
        $sales->post($url, ['max_users' => null, 'max_branches' => 1, 'modules' => ['expenses'], 'reason' => 'Unlimited users'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertArrayHasKey('max_users', $subscription->fresh()->overrides);
        $this->assertNull($subscription->fresh()->limits()['max_users']);

        $this->actingAs($this->staff('viewer'))->post($url, ['modules' => [], 'reason' => 'x'])->assertForbidden();
    }

    public function test_cancelling_leaves_the_customer_read_only_and_is_audited_with_its_reason(): void
    {
        $subscription = $this->subscribed();

        $this->actingAs($this->staff('owner'))->post("/customers/{$subscription->customer_id}/subscription/cancel", ['reason' => 'Closing down'])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('cancelled', $subscription->customer->fresh()->status);
        $this->assertFalse($subscription->fresh()->allowsWrites());
        $this->assertSame('Closing down', AuditLog::query()->where('action', 'customer.cancelled')->firstOrFail()->reason);
    }

    public function test_an_action_on_a_customer_without_a_subscription_says_so(): void
    {
        $customer = $this->customer();

        $this->actingAs($this->staff('finance'))->post("/customers/{$customer->id}/subscription/block", ['reason' => 'x'])->assertSessionHas('error');
        $this->actingAs($this->staff('finance'))->post("/customers/{$customer->id}/invoices", ['issued_on' => '2026-03-01', 'amount' => 10, 'currency' => 'AFN'])->assertSessionHas('error');
    }

    // --- Money -------------------------------------------------------------

    public function test_finance_invoices_and_takes_payment_and_sales_cannot(): void
    {
        $subscription = $this->subscribed();
        $customerId = $subscription->customer_id;
        $form = ['issued_on' => '2026-03-01', 'amount' => 1000, 'discount' => 100, 'currency' => 'afn', 'description' => 'Year 1'];

        $this->actingAs($this->staff('sales'))->post("/customers/{$customerId}/invoices", $form)->assertForbidden();

        $finance = $this->staff('finance');
        $this->actingAs($finance)->post("/customers/{$customerId}/invoices", $form)->assertRedirect()->assertSessionHasNoErrors();
        $invoice = Invoice::query()->firstOrFail();
        $this->assertSame('900.0000', $invoice->payable());
        $this->assertSame('AFN', $invoice->currency);

        $this->actingAs($this->staff('sales'))->post("/invoices/{$invoice->id}/payments", ['amount' => 1, 'paid_on' => '2026-03-02', 'method' => 'cash'])->assertForbidden();

        $this->actingAs($finance)->post("/invoices/{$invoice->id}/payments", ['amount' => 400, 'paid_on' => '2026-03-02', 'method' => 'hawala', 'reference' => 'HW-1'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('partial', $invoice->fresh('payments')->paymentState());

        // More than what is left is refused with a message.
        $this->actingAs($finance)->post("/invoices/{$invoice->id}/payments", ['amount' => 600, 'paid_on' => '2026-03-02', 'method' => 'cash'])->assertSessionHas('error');
        $this->assertSame(1, Payment::query()->count());
    }

    public function test_a_payment_is_validated_and_a_paid_invoice_is_voided_only_after_its_payments_go(): void
    {
        $subscription = $this->subscribed();
        $this->actingAs($this->staff('finance'));
        $invoice = app(SubscriptionService::class)->invoice($subscription, ['issued_on' => '2026-03-01', 'amount' => 100]);

        $this->post("/invoices/{$invoice->id}/payments", ['amount' => 0, 'paid_on' => 'not a date', 'method' => 'bitcoin'])
            ->assertSessionHasErrors(['amount', 'paid_on', 'method']);

        $this->post("/invoices/{$invoice->id}/payments", ['amount' => 100, 'paid_on' => '2026-03-02', 'method' => 'cash'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post("/invoices/{$invoice->id}/void")->assertSessionHas('error');
        $this->assertSame('open', $invoice->fresh()->status);

        $payment = Payment::query()->firstOrFail();
        $this->delete("/payments/{$payment->id}")->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.removed', 'customer_id' => $subscription->customer_id]);

        $this->post("/invoices/{$invoice->id}/void")->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('void', $invoice->fresh()->status);
        $this->post("/invoices/{$invoice->id}/payments", ['amount' => 1, 'paid_on' => '2026-03-02', 'method' => 'cash'])->assertSessionHas('error');
    }

    // --- The ERP connection ------------------------------------------------

    public function test_the_erp_address_is_saved_and_checked(): void
    {
        $customer = $this->customer();
        $sales = $this->actingAs($this->staff('sales'));

        $sales->put("/customers/{$customer->id}/instance", ['erp_company_id' => 'short', 'base_url' => 'not a url'])->assertSessionHasErrors(['erp_company_id', 'base_url']);

        $sales->put("/customers/{$customer->id}/instance", ['erp_company_id' => '01M6DTM02SCYWQ21QAJD5NYKHF', 'base_url' => 'https://erp.example.com'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('01M6DTM02SCYWQ21QAJD5NYKHF', $customer->instance()->first()->erp_company_id);

        $this->actingAs($this->staff('viewer'))->put("/customers/{$customer->id}/instance", ['erp_company_id' => '01M6DTM02SCYWQ21QAJD5NYKHF'])->assertForbidden();
    }

    public function test_the_token_is_shown_once_works_against_the_api_and_the_next_one_replaces_it(): void
    {
        $this->useLicenseKeys();
        $subscription = $this->subscribed();
        $customerId = $subscription->customer_id;
        $sales = $this->staff('sales');

        $this->actingAs($this->staff('viewer'))->post("/customers/{$customerId}/instance/token")->assertForbidden();

        $this->actingAs($sales)->post("/customers/{$customerId}/instance/token")->assertRedirect();
        $token = $this->get("/customers/{$customerId}")->assertInertia(fn ($page) => $page->whereType('issuedToken', 'string'))->viewData('page')['props']['issuedToken'];

        // Once: the next visit does not show it.
        $this->get("/customers/{$customerId}")->assertInertia(fn ($page) => $page->where('issuedToken', null)->where('instance.has_token', true));

        // It is stored as a hash, and the audit trail does not hold it.
        $this->assertNotSame($token, $subscription->customer->instance->api_token_hash);
        $this->assertStringNotContainsString($token, json_encode(AuditLog::query()->get()->toArray()));

        // It opens the ERP API for this customer, and a new token replaces it.
        $this->getJson('/api/license', ['Authorization' => "Bearer {$token}"])->assertOk()->assertJsonPath('payload.customer_id', $customerId);

        $this->actingAs($sales)->post("/customers/{$customerId}/instance/token");
        $this->getJson('/api/license', ['Authorization' => "Bearer {$token}"])->assertUnauthorized();
    }

    // --- The customer page -------------------------------------------------

    public function test_the_customer_page_brings_everything_a_member_of_staff_needs(): void
    {
        $subscription = $this->subscribed('+5 days');
        $service = app(SubscriptionService::class);
        $invoice = $service->invoice($subscription, ['issued_on' => '2026-03-01', 'amount' => 1000]);
        $service->recordPayment($invoice, ['amount' => 250, 'paid_on' => '2026-03-02', 'method' => 'cash']);
        UsageSnapshot::query()->create(['customer_id' => $subscription->customer_id, 'taken_on' => now()->toDateString(), 'users' => 2, 'branches' => 1]);

        $this->get("/customers/{$subscription->customer_id}")->assertInertia(fn ($page) => $page
            ->component('Customers/Show')
            ->where('subscription.state', 'expiring')
            ->where('subscription.package_limits.max_users', 3)
            ->where('subscription.has_overrides', false)
            ->has('packages', 1)
            ->where('invoices.0.state', 'partial')
            ->where('invoices.0.balance', 750)
            ->where('invoices.0.payments.0.method', 'cash')
            ->where('usage.users', 2)
            ->has('changes', 1)
            ->where('instance', null));
    }
}
