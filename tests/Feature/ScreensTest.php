<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesData;
use Tests\TestCase;

class ScreensTest extends TestCase
{
    use MakesData;
    use RefreshDatabase;

    private function valid(array $overrides = []): array
    {
        return $overrides + [
            'name_en' => 'Kabul Traders', 'name_fa' => 'کابل ټریډرز', 'business_type' => 'retail',
            'province' => 'Kabul', 'base_currency' => 'afn', 'calendar_type' => 'jalali', 'locale' => 'fa',
        ];
    }

    public function test_the_pages_know_who_is_signed_in_and_what_they_may_do(): void
    {
        $this->actingAs($this->staff('sales'))->get('/customers')->assertInertia(fn ($page) => $page
            ->where('auth.user.role', 'sales')
            ->where('auth.user.abilities', fn ($abilities) => collect($abilities)->contains('customers.manage') && ! collect($abilities)->contains('billing.manage'))
            ->where('locale', 'en'));
    }

    public function test_a_customer_is_created_as_a_lead_and_audited(): void
    {
        $staff = $this->staff('sales');

        $this->actingAs($staff)->post('/customers', $this->valid())->assertSessionHasNoErrors();

        $customer = Customer::query()->firstOrFail();
        $this->assertSame('lead', $customer->status);
        $this->assertSame('AFN', $customer->base_currency);
        $this->assertSame($staff->id, $customer->created_by);
        $this->assertSame($customer->id, AuditLog::query()->where('action', 'customer.created')->firstOrFail()->customer_id);
    }

    public function test_the_form_is_validated(): void
    {
        $this->actingAs($this->staff('sales'))
            ->post('/customers', ['name_en' => '', 'business_type' => 'casino', 'base_currency' => 'AFGHANI', 'calendar_type' => 'lunar', 'locale' => 'de'])
            ->assertSessionHasErrors(['name_en', 'business_type', 'base_currency', 'calendar_type', 'locale']);

        $this->assertSame(0, Customer::query()->count());
    }

    public function test_an_update_records_the_values_before_and_after(): void
    {
        $customer = $this->customer(['name_en' => 'Old name']);

        $this->actingAs($this->staff('sales'))->put("/customers/{$customer->id}", $this->valid(['name_en' => 'New name']))->assertSessionHasNoErrors();

        $log = AuditLog::query()->where('action', 'customer.updated')->firstOrFail();
        $this->assertSame('Old name', $log->before['name_en']);
        $this->assertSame('New name', $log->after['name_en']);
    }

    public function test_each_role_may_open_only_what_it_is_given(): void
    {
        $customer = $this->customer();

        // A viewer reads but changes nothing.
        $viewer = $this->staff('viewer');
        $this->actingAs($viewer)->get('/customers')->assertOk();
        $this->actingAs($viewer)->get("/customers/{$customer->id}")->assertOk();
        $this->actingAs($viewer)->get('/customers/create')->assertForbidden();
        $this->actingAs($viewer)->post('/customers', $this->valid())->assertForbidden();
        $this->actingAs($viewer)->put("/customers/{$customer->id}", $this->valid())->assertForbidden();
        $this->assertSame(1, Customer::query()->count());

        // Support looks at customers but the money and the dashboard are not theirs.
        $support = $this->staff('support');
        $this->actingAs($support)->get('/customers')->assertOk();
        $this->actingAs($support)->get('/dashboard')->assertForbidden();
        $this->actingAs($support)->get('/customers/create')->assertForbidden();
    }

    public function test_a_switched_off_account_can_open_nothing(): void
    {
        $this->actingAs($this->staff('owner', active: false))->get('/customers')->assertForbidden();
    }

    public function test_the_list_filters_by_name_in_any_language_and_by_status(): void
    {
        $this->customer(['name_en' => 'Kabul Traders', 'name_fa' => 'کابل', 'status' => 'active']);
        $this->customer(['name_en' => 'Herat Pharmacy', 'status' => 'trial']);

        $viewer = $this->actingAs($this->staff('viewer'));

        $viewer->get('/customers?search=herat')->assertInertia(fn ($page) => $page->has('customers.data', 1)->where('customers.data.0.name', 'Herat Pharmacy'));
        $viewer->get('/customers?search='.urlencode('کابل'))->assertInertia(fn ($page) => $page->has('customers.data', 1));
        $viewer->get('/customers?status=trial')->assertInertia(fn ($page) => $page->has('customers.data', 1)->where('customers.data.0.status', 'trial'));
        $viewer->get('/customers')->assertInertia(fn ($page) => $page->has('customers.data', 2));
    }

    public function test_the_list_and_the_page_show_the_subscription_state(): void
    {
        $customer = $this->customer();
        $this->actingAs($this->staff('owner'));
        app(SubscriptionService::class)->assign($customer, $this->package(), now()->startOfDay(), now()->addDays(5)->startOfDay());

        $this->get('/customers')->assertInertia(fn ($page) => $page
            ->where('customers.data.0.state', 'expiring')->where('customers.data.0.package', 'Starter'));
        $this->get("/customers/{$customer->id}")->assertInertia(fn ($page) => $page
            ->component('Customers/Show')->where('subscription.state', 'expiring')->where('subscription.limits.max_users', 3));
    }

    public function test_the_dashboard_counts_customers_subscriptions_and_what_is_owed(): void
    {
        $owner = $this->staff('owner');
        $this->actingAs($owner);
        $service = app(SubscriptionService::class);
        $package = $this->package();

        $soon = $service->assign($this->customer(['name_en' => 'Soon']), $package, now()->startOfDay(), now()->addDays(10)->startOfDay());
        $service->assign($this->customer(['name_en' => 'Fine']), $package, now()->startOfDay(), now()->addYear()->startOfDay());
        $service->block($service->assign($this->customer(['name_en' => 'Blocked']), $package, now()->startOfDay(), now()->addYear()->startOfDay()), 'Unpaid');
        $service->startTrial($this->customer(['name_en' => 'Trialling']), $package);

        $invoice = $service->invoice($soon, ['issued_on' => now()->toDateString(), 'amount' => 1000, 'discount' => 100]);
        $service->recordPayment($invoice, ['amount' => 300, 'paid_on' => now()->toDateString(), 'method' => 'cash']);

        $this->get('/dashboard')->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->where('counts.customers', 4)
            ->where('counts.active', 3)
            ->where('counts.trial', 1)
            ->where('counts.expiring', 2)  // Soon, and the trial that ends in 14 days
            ->where('counts.blocked', 1)
            ->where('counts.unpaid', 1)
            ->where('receivable.0.currency', 'AFN')
            ->where('receivable.0.amount', 600)
            ->has('ending'));
    }
}
