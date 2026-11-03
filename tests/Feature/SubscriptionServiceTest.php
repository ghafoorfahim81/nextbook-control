<?php

namespace Tests\Feature;

use App\Exceptions\ControlException;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\SubscriptionChange;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesData;
use Tests\TestCase;

class SubscriptionServiceTest extends TestCase
{
    use MakesData;
    use RefreshDatabase;

    private SubscriptionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SubscriptionService::class);
        $this->actingAs($this->staff());
    }

    private function subscribed(string $endsIn = '+1 year'): Subscription
    {
        return $this->service->assign($this->customer(), $this->package(), now()->startOfDay(), now()->modify($endsIn)->startOfDay());
    }

    public function test_a_trial_puts_the_customer_on_trial_for_the_given_days(): void
    {
        $customer = $this->customer();

        $subscription = $this->service->startTrial($customer, $this->package());

        $this->assertSame('trial', $subscription->kind);
        $this->assertSame('trial', $customer->fresh()->status);
        $this->assertSame(14, $subscription->daysLeft());
    }

    public function test_assigning_a_paid_package_makes_the_customer_active_and_logs_it(): void
    {
        $customer = $this->customer();

        $subscription = $this->service->assign($customer, $this->package(), now()->startOfDay(), now()->addYear()->startOfDay());

        $this->assertSame('active', $customer->fresh()->status);
        $this->assertSame(1, $subscription->license_version);
        $this->assertSame('started', SubscriptionChange::query()->firstOrFail()->type);
        $this->assertDatabaseHas('audit_logs', ['action' => 'subscription.assigned', 'customer_id' => $customer->id]);
    }

    public function test_an_end_before_the_start_is_refused(): void
    {
        $this->expectException(ControlException::class);

        $this->service->assign($this->customer(), $this->package(), now(), now()->subDay());
    }

    public function test_changing_the_package_is_logged_as_such_and_bumps_the_licence_version(): void
    {
        $subscription = $this->subscribed();
        $pro = $this->package(['code' => 'pro', 'name' => 'Pro', 'max_users' => 10]);

        $changed = $this->service->assign($subscription->customer, $pro, $subscription->starts_on, $subscription->ends_on);

        $this->assertSame(2, $changed->license_version);
        $this->assertSame(['started', 'package_changed'], SubscriptionChange::query()->orderBy('created_at')->orderBy('id')->pluck('type')->all());
        $this->assertSame(1, Subscription::query()->count(), 'one subscription per customer');
    }

    public function test_the_state_follows_the_dates_and_the_block(): void
    {
        $this->assertSame('active', $this->subscribed('+60 days')->state());
        $this->assertSame('expiring', Subscription::query()->firstOrFail()->forceFill(['ends_on' => now()->addDays(5)])->state());
        $this->assertSame('expired', Subscription::query()->firstOrFail()->forceFill(['ends_on' => now()->subDay()])->state());

        $blocked = $this->service->block(Subscription::query()->firstOrFail()->forceFill(['ends_on' => now()->addYear()]), 'Unpaid');
        $this->assertSame('blocked', $blocked->state());
        $this->assertFalse($blocked->allowsWrites());

        $this->assertTrue($this->service->unblock($blocked)->allowsWrites());
    }

    public function test_renewing_counts_from_the_end_when_ahead_and_from_today_when_it_has_passed(): void
    {
        $early = $this->subscribed('+10 days');
        $this->service->renew($early);
        $this->assertSame(now()->addDays(10)->addYear()->toDateString(), $early->fresh()->ends_on->toDateString());

        $early->forceFill(['ends_on' => now()->subDays(40)->toDateString()])->save();
        $this->service->renew($early->fresh(), 6);
        $this->assertSame(now()->startOfDay()->addMonthsNoOverflow(6)->toDateString(), $early->fresh()->ends_on->toDateString());
    }

    public function test_renewing_a_trial_turns_it_into_a_paid_subscription(): void
    {
        $customer = $this->customer();
        $trial = $this->service->startTrial($customer, $this->package());

        $this->service->renew($trial);

        $this->assertSame('paid', $trial->fresh()->kind);
        $this->assertSame('active', $customer->fresh()->status);
    }

    public function test_a_limit_changed_for_one_customer_needs_a_reason_and_lays_over_the_package(): void
    {
        $subscription = $this->subscribed();
        $this->assertSame(3, $subscription->limits()['max_users']);

        try {
            $this->service->override($subscription, ['max_users' => 5], '  ');
            $this->fail('A reason should be required.');
        } catch (ControlException) {
        }

        $this->service->override($subscription, ['max_users' => 5, 'nonsense' => 1], 'Sold two extra seats');

        $limits = $subscription->fresh('package')->limits();
        $this->assertSame(5, $limits['max_users']);
        $this->assertSame(1, $limits['max_branches'], 'the rest still comes from the package');
        $this->assertArrayNotHasKey('nonsense', $subscription->fresh()->overrides);
        $this->assertSame('Sold two extra seats', AuditLog::query()->where('action', 'subscription.overridden')->firstOrFail()->reason);
    }

    public function test_cancelling_leaves_the_customer_able_to_read_but_not_write(): void
    {
        $subscription = $this->subscribed();

        $customer = $this->service->cancel($subscription->customer, 'Closing down');

        $this->assertSame('cancelled', $customer->status);
        $fresh = $subscription->fresh();
        $this->assertSame('expired', $fresh->state());
        $this->assertFalse($fresh->allowsWrites());
        $this->assertNull($fresh->blocked_at, 'it is closed by date, not by a block');
    }

    public function test_a_price_change_leaves_the_old_price_for_old_dates(): void
    {
        $package = $this->package();
        $package->prices()->create(['amount' => 1500, 'currency' => 'AFN', 'period_months' => 12, 'effective_from' => now()->addMonth()->toDateString()]);

        $this->assertSame('1200.0000', $package->priceOn(now())->amount);
        $this->assertSame('1500.0000', $package->priceOn(now()->addMonths(2))->amount);
        $this->assertSame('600.0000', $this->service->priceForMonths($package, 6, now()));
    }

    public function test_a_package_without_a_price_in_the_currency_cannot_be_priced(): void
    {
        $this->expectException(ControlException::class);

        $this->service->priceForMonths($this->package(), 12, now(), 'USD');
    }

    public function test_invoice_numbers_run_on_across_customers_and_restart_each_year(): void
    {
        $a = $this->subscribed();
        $b = $this->service->assign($this->customer(['name_en' => 'Herat Pharmacy']), $this->package(['code' => 'p2']), now(), now()->addYear());

        $first = $this->service->invoice($a, ['issued_on' => '2026-03-01', 'amount' => 100]);
        $second = $this->service->invoice($b, ['issued_on' => '2026-03-02', 'amount' => 100]);
        $nextYear = $this->service->invoice($a, ['issued_on' => '2027-01-05', 'amount' => 100]);

        $this->assertSame(['SUB-2026-0001', 'SUB-2026-0002', 'SUB-2027-0001'], [$first->number, $second->number, $nextYear->number]);
    }

    public function test_a_discount_cannot_exceed_the_amount_and_reduces_what_is_payable(): void
    {
        $subscription = $this->subscribed();

        $invoice = $this->service->invoice($subscription, ['issued_on' => '2026-03-01', 'amount' => 1000, 'discount' => 100]);
        $this->assertSame('900.0000', $invoice->payable());

        $this->expectException(ControlException::class);
        $this->service->invoice($subscription, ['issued_on' => '2026-03-01', 'amount' => 100, 'discount' => 200]);
    }

    public function test_payments_settle_an_invoice_and_never_go_past_what_is_owed(): void
    {
        $invoice = $this->service->invoice($this->subscribed(), ['issued_on' => '2026-03-01', 'amount' => 1000, 'discount' => 100]);
        $pay = fn (float $amount) => $this->service->recordPayment($invoice, ['amount' => $amount, 'paid_on' => '2026-03-05', 'method' => 'hawala']);

        $pay(400);
        $this->assertSame('partial', $invoice->fresh('payments')->paymentState());

        try {
            $pay(600);
            $this->fail('600 is more than the 500 left.');
        } catch (ControlException) {
        }

        $pay(500);
        $this->assertSame('paid', $invoice->fresh('payments')->paymentState());
    }

    public function test_a_void_invoice_takes_no_payment_and_a_paid_one_cannot_be_voided(): void
    {
        $subscription = $this->subscribed();
        $void = $this->service->invoice($subscription, ['issued_on' => '2026-03-01', 'amount' => 100]);
        $this->service->voidInvoice($void);

        try {
            $this->service->recordPayment($void, ['amount' => 10, 'paid_on' => '2026-03-05', 'method' => 'cash']);
            $this->fail('A void invoice takes no payment.');
        } catch (ControlException) {
        }

        $paid = $this->service->invoice($subscription, ['issued_on' => '2026-03-01', 'amount' => 100]);
        $this->service->recordPayment($paid, ['amount' => 10, 'paid_on' => '2026-03-05', 'method' => 'cash']);

        $this->expectException(ControlException::class);
        $this->service->voidInvoice($paid);
    }

    public function test_an_unknown_payment_method_is_refused(): void
    {
        $invoice = $this->service->invoice($this->subscribed(), ['issued_on' => '2026-03-01', 'amount' => 100]);

        $this->expectException(ControlException::class);
        $this->service->recordPayment($invoice, ['amount' => 10, 'paid_on' => '2026-03-05', 'method' => 'bitcoin']);
    }

    public function test_a_failed_change_leaves_nothing_behind(): void
    {
        $customer = $this->customer();

        try {
            $this->service->assign($customer, $this->package(), now(), now()->subDay());
        } catch (ControlException) {
        }

        $this->assertSame(0, Subscription::query()->count());
        $this->assertSame(0, Invoice::query()->count());
        $this->assertSame('lead', $customer->fresh()->status);
    }
}
