<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\UsageSnapshot;
use App\Services\ReportService;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\MakesData;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use MakesData;
    use RefreshDatabase;

    private ReportService $reports;

    private SubscriptionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        // 2026-11-03 is the 12th of Aban 1405.
        Carbon::setTestNow(Carbon::parse('2026-11-03 06:00:00', 'UTC'));
        $this->reports = app(ReportService::class);
        $this->service = app(SubscriptionService::class);
        $this->actingAs($this->staff('owner'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A customer on a (new, or the given) package, ending on a date; paid unless said. */
    private function sub(array $customer = [], string $endsOn = '2027-06-01', string $kind = 'paid', ?Package $package = null): Subscription
    {
        $c = $this->customer($customer + ['name_en' => 'Customer '.(Customer::query()->count() + 1), 'status' => 'active']);
        $subscription = $this->service->assign($c, $package ?? $this->package(['code' => 'p'.Package::query()->count()]), Carbon::parse('2026-01-01'), Carbon::parse($endsOn), $kind);

        return $subscription;
    }

    // --- Months ------------------------------------------------------------

    public function test_english_months_are_calendar_months_and_the_last_is_this_one(): void
    {
        $months = $this->reports->months(12, 'en');

        $this->assertCount(12, $months);
        $this->assertSame('2025-12', $months[0]['key']);
        $this->assertSame('2026-11', $months[11]['key']);
        $this->assertSame(['2026-11-01', '2026-11-30'], [$months[11]['from'], $months[11]['to']]);
        $this->assertSame('2026-02-28', $months[2]['to'], 'a short month ends on its own last day');
    }

    public function test_jalali_months_follow_the_solar_hijri_calendar_and_leave_no_gap(): void
    {
        $months = $this->reports->months(14, 'fa');

        $this->assertSame('1405-08', $months[13]['key']);
        $this->assertSame(['2026-10-23', '2026-11-21'], [$months[13]['from'], $months[13]['to']]);   // Aban has 30 days
        $this->assertSame(['2026-09-23', '2026-10-22'], [$months[12]['from'], $months[12]['to']]);   // Mehr has 30
        // Counting back from index 13 (1405-08): 6 is 1405-01, and 5 is the last month of 1404.
        $this->assertSame('1405-01', $months[6]['key']);
        $this->assertSame(['2026-03-21', '2026-04-20'], [$months[6]['from'], $months[6]['to']]);     // Farvardin has 31 days
        $this->assertSame('1404-12', $months[5]['key']);                                              // across the year boundary
        $this->assertSame('2026-03-20', $months[5]['to']);

        for ($i = 1; $i < count($months); $i++) {
            $this->assertSame(Carbon::parse($months[$i - 1]['to'])->addDay()->toDateString(), $months[$i]['from'], "month {$i} follows the one before");
        }
        $this->assertSame(['jy' => 1405, 'jm' => 8], ['jy' => $months[13]['jy'], 'jm' => $months[13]['jm']]);
    }

    // --- Revenue -----------------------------------------------------------

    public function test_revenue_is_kept_per_currency_and_months_are_the_readers_months(): void
    {
        $afn = $this->sub(['base_currency' => 'AFN']);
        $usd = $this->sub(['base_currency' => 'USD']);

        // Aban 1405 starts on 2026-10-23; the day before is still Mehr.
        $a = $this->service->invoice($afn, ['issued_on' => '2026-10-23', 'amount' => 1000, 'discount' => 100, 'currency' => 'AFN']);
        $b = $this->service->invoice($afn, ['issued_on' => '2026-10-22', 'amount' => 500, 'currency' => 'AFN']);
        $c = $this->service->invoice($usd, ['issued_on' => '2026-10-25', 'amount' => 30, 'currency' => 'USD']);
        $this->service->recordPayment($a, ['amount' => 400, 'paid_on' => '2026-10-23', 'method' => 'cash']);
        $this->service->recordPayment($b, ['amount' => 500, 'paid_on' => '2026-10-22', 'method' => 'bank']);
        $this->service->recordPayment($c, ['amount' => 30, 'paid_on' => '2026-10-26', 'method' => 'hawala']);

        $data = $this->reports->revenue('fa');
        $aban = collect($data['months'])->firstWhere('key', '1405-08');
        $mehr = collect($data['months'])->firstWhere('key', '1405-07');

        $this->assertSame(['AFN', 'USD'], $data['currencies']);
        $this->assertEquals(900, $aban['invoiced']['AFN'], 'the amount less the discount');
        $this->assertEquals(30, $aban['invoiced']['USD']);
        $this->assertEquals(400, $aban['collected']['AFN']);
        $this->assertEquals(30, $aban['collected']['USD']);
        $this->assertEquals(500, $mehr['invoiced']['AFN'], 'the 22nd is still Mehr');
        $this->assertEquals(500, $mehr['collected']['AFN']);
        $this->assertArrayNotHasKey('USD', $mehr['invoiced']);
    }

    public function test_a_voided_invoice_is_neither_invoiced_nor_collected(): void
    {
        $sub = $this->sub();
        $void = $this->service->invoice($sub, ['issued_on' => '2026-10-25', 'amount' => 777]);
        $this->service->voidInvoice($void);

        $month = collect($this->reports->revenue('fa')['months'])->firstWhere('key', '1405-08');

        $this->assertSame([], $month['invoiced']);
        $this->assertSame([], $month['collected']);
    }

    public function test_recurring_revenue_counts_only_paid_running_subscriptions_at_their_monthly_share(): void
    {
        $package = $this->package(['code' => 'starter']);           // 1200 AFN a year
        $usdPackage = $this->package(['code' => 'usd']);
        $usdPackage->prices()->create(['amount' => 240, 'currency' => 'USD', 'period_months' => 12, 'effective_from' => '2025-01-01']);

        $this->sub(['base_currency' => 'AFN', 'province' => 'Kabul'], package: $package);
        $this->sub(['base_currency' => 'AFN', 'province' => 'Kabul'], package: $package);
        $this->sub(['base_currency' => 'AFN', 'province' => 'Herat'], package: $package);
        $this->sub(['base_currency' => 'USD', 'province' => 'Herat'], package: $usdPackage);

        // None of these count:
        $this->sub(['name_en' => 'Trial'], kind: 'trial');
        $this->service->block($this->sub(['name_en' => 'Blocked']), 'Unpaid');
        $this->sub(['name_en' => 'Expired'], endsOn: '2026-10-01');
        $cancelled = $this->sub(['name_en' => 'Cancelled']);
        $cancelled->customer->update(['status' => 'cancelled']);
        $this->sub(['name_en' => 'NoUsdPrice', 'base_currency' => 'USD'], package: $package);   // priced in AFN only

        $mrr = $this->reports->recurring();

        $this->assertEquals(300, $mrr['by_currency']['AFN']['monthly']);   // 3 × 100
        $this->assertEquals(3600, $mrr['by_currency']['AFN']['annual']);
        $this->assertSame(3, $mrr['by_currency']['AFN']['customers']);
        $this->assertEquals(20, $mrr['by_currency']['USD']['monthly']);    // 240 / 12
        $this->assertSame(1, $mrr['unpriced']);
        $this->assertSame('Kabul', $mrr['by_province'][0]['province']);
        $this->assertSame(2, $mrr['by_province'][0]['customers']);
        $this->assertEquals(200, $mrr['by_province'][0]['monthly']['AFN']);
    }

    // --- Receivables -------------------------------------------------------

    public function test_receivables_are_bucketed_by_how_late_and_the_edges_fall_where_they_should(): void
    {
        $sub = $this->sub();
        // today = 2026-11-03
        $due = fn (string $date, float $amount) => $this->service->invoice($sub, ['issued_on' => '2026-01-01', 'due_on' => $date, 'amount' => $amount, 'currency' => 'AFN']);
        $due('2026-11-03', 1);     // due today: not late
        $due('2026-11-10', 2);     // due next week: not late
        $due('2026-11-02', 10);    // 1 day late
        $due('2026-10-04', 20);    // 30 days late
        $due('2026-10-03', 40);    // 31
        $due('2026-09-04', 80);    // 60
        $due('2026-09-03', 160);   // 61

        $data = $this->reports->receivables();
        $row = $data['rows'][0];

        $this->assertEquals(3, $row['current']);
        $this->assertEquals(30, $row['d30']);
        $this->assertEquals(120, $row['d60']);
        $this->assertEquals(160, $row['d60plus']);
        $this->assertEquals(313, $row['total']);
        $this->assertSame(61, $row['oldest_days']);
        $this->assertSame(7, $row['invoices']);
        $this->assertEquals(313, $data['totals']['AFN']['total']);
    }

    public function test_an_invoice_without_a_due_date_is_late_from_the_day_it_was_issued(): void
    {
        $sub = $this->sub();
        $this->service->invoice($sub, ['issued_on' => '2026-10-04', 'amount' => 100, 'currency' => 'AFN']);   // 30 days ago

        $this->assertEquals(100, $this->reports->receivables()['rows'][0]['d30']);
    }

    public function test_what_is_paid_voided_or_in_another_currency_is_handled_correctly(): void
    {
        $sub = $this->sub();
        $partly = $this->service->invoice($sub, ['issued_on' => '2026-10-20', 'due_on' => '2026-10-20', 'amount' => 1000, 'discount' => 200, 'currency' => 'AFN']);
        $this->service->recordPayment($partly, ['amount' => 300, 'paid_on' => '2026-10-21', 'method' => 'cash']);
        $paid = $this->service->invoice($sub, ['issued_on' => '2026-10-20', 'amount' => 50, 'currency' => 'AFN']);
        $this->service->recordPayment($paid, ['amount' => 50, 'paid_on' => '2026-10-21', 'method' => 'cash']);
        $this->service->voidInvoice($this->service->invoice($sub, ['issued_on' => '2026-10-20', 'amount' => 9999, 'currency' => 'AFN']));
        $this->service->invoice($sub, ['issued_on' => '2026-10-20', 'due_on' => '2026-10-20', 'amount' => 25, 'currency' => 'USD']);

        $data = $this->reports->receivables();

        $this->assertCount(2, $data['rows'], 'one row per customer and currency');
        $this->assertEquals(500, $data['totals']['AFN']['total'], '800 payable less 300 paid; the paid and the void are out');
        $this->assertEquals(25, $data['totals']['USD']['total']);
        $this->assertArrayHasKey('USD', $data['totals']);
    }

    public function test_the_longest_overdue_is_listed_first(): void
    {
        $a = $this->sub(['name_en' => 'Recent']);
        $b = $this->sub(['name_en' => 'Old']);
        $this->service->invoice($a, ['issued_on' => '2026-10-30', 'due_on' => '2026-10-30', 'amount' => 999, 'currency' => 'AFN']);
        $this->service->invoice($b, ['issued_on' => '2026-08-01', 'due_on' => '2026-08-01', 'amount' => 5, 'currency' => 'AFN']);

        $this->assertSame(['Old', 'Recent'], array_column($this->reports->receivables()['rows'], 'customer'));
    }

    // --- Renewals ----------------------------------------------------------

    public function test_renewals_count_the_periods_that_ended_and_how_many_were_renewed(): void
    {
        // Ended this Jalali month (Aban, from 2026-10-23), before today:
        $renewed1 = $this->sub(['name_en' => 'Renews A'], endsOn: '2026-10-30');
        $renewed2 = $this->sub(['name_en' => 'Renews B'], endsOn: '2026-11-01');
        $this->service->renew($renewed1->fresh());
        $this->service->renew($renewed2->fresh());
        $this->sub(['name_en' => 'Lapsed A'], endsOn: '2026-10-25');
        $this->sub(['name_en' => 'Lapsed B'], endsOn: '2026-11-02');
        // Not counted:
        $this->sub(['name_en' => 'Trial'], endsOn: '2026-10-28', kind: 'trial');
        $this->sub(['name_en' => 'Still running'], endsOn: '2026-11-30');
        $this->sub(['name_en' => 'Ends today'], endsOn: '2026-11-03');   // has not ended yet

        $data = $this->reports->renewals('fa', 6);
        $aban = collect($data['months'])->firstWhere('key', '1405-08');

        $this->assertSame(2, $aban['renewed']);
        $this->assertSame(2, $aban['lapsed']);
        $this->assertEquals(50, $aban['rate']);
        $this->assertEquals(50, $data['overall_rate']);
    }

    public function test_a_month_with_nothing_that_ended_has_no_rate_rather_than_a_zero(): void
    {
        $data = $this->reports->renewals('fa', 3);

        $this->assertNull($data['months'][0]['rate']);
        $this->assertNull($data['overall_rate']);
    }

    public function test_the_lapsed_list_is_who_to_call_with_how_to_reach_them(): void
    {
        $recent = $this->sub(['name_en' => 'Recent'], endsOn: '2026-10-25');
        $recent->customer->contacts()->create(['name' => 'Ahmad', 'role' => 'manager', 'whatsapp' => '0791234567', 'is_primary' => true]);
        $this->sub(['name_en' => 'Long ago'], endsOn: '2026-07-01');            // more than 60 days: not on the list
        $renewed = $this->sub(['name_en' => 'Renewed'], endsOn: '2026-10-28');
        $this->service->renew($renewed->fresh());                                // moved on: not on the list

        $lapsed = $this->reports->renewals('fa')['lapsed'];

        $this->assertCount(1, $lapsed);
        $this->assertSame('Recent', $lapsed[0]['customer']);
        $this->assertSame(9, $lapsed[0]['days_ago']);
        $this->assertSame('Ahmad 0791234567', $lapsed[0]['contact']);
        $this->assertSame('expired', $lapsed[0]['state']);
    }

    // --- Usage -------------------------------------------------------------

    public function test_usage_is_averaged_per_package_from_the_latest_report_and_flags_those_close_to_a_limit(): void
    {
        $package = $this->package(['code' => 'starter', 'max_users' => 5, 'max_branches' => 2]);
        $close = $this->sub(['name_en' => 'Close'], package: $package);
        $roomy = $this->sub(['name_en' => 'Roomy'], package: $package);
        $silent = $this->sub(['name_en' => 'Silent'], package: $package);
        $unlimited = $this->sub(['name_en' => 'Unlimited'], package: $this->package(['code' => 'big', 'max_users' => null, 'max_branches' => null]));

        $snap = fn (Customer $c, string $day, int $users, int $branches) => UsageSnapshot::query()->create(['customer_id' => $c->id, 'taken_on' => $day, 'users' => $users, 'branches' => $branches]);
        $snap($close->customer, '2026-10-01', 1, 1);
        $snap($close->customer, '2026-11-02', 4, 2);        // the latest wins: 4 of 5 is 80%, 2 of 2 is 100%
        $snap($roomy->customer, '2026-11-02', 2, 1);
        $snap($unlimited->customer, '2026-11-02', 500, 50);  // no limit, nothing to be close to

        $data = $this->reports->usage();
        $starter = collect($data['packages'])->firstWhere('package', 'Starter');

        $this->assertSame(3, $starter['customers']);
        $this->assertSame(2, $starter['reported']);
        $this->assertEquals(3, $starter['avg_users']);       // (4 + 2) / 2
        $this->assertEquals(1.5, $starter['avg_branches']);
        $this->assertSame(1, $data['never_reported'], 'only Silent has never reported');

        $this->assertCount(1, $data['near_limit']);
        $this->assertSame('Close', $data['near_limit'][0]['customer']);
        $this->assertEqualsCanonicalizing(['users', 'branches'], array_column($data['near_limit'][0]['close_to'], 'what'));
        $this->assertSame(5, collect($data['near_limit'][0]['close_to'])->firstWhere('what', 'users')['limit']);
    }

    public function test_just_under_the_threshold_is_not_close_and_exactly_on_it_is(): void
    {
        $package = $this->package(['code' => 's', 'max_users' => 10, 'max_branches' => null]);
        $under = $this->sub(['name_en' => 'Under'], package: $package);
        $on = $this->sub(['name_en' => 'On'], package: $package);
        UsageSnapshot::query()->create(['customer_id' => $under->customer_id, 'taken_on' => '2026-11-02', 'users' => 7, 'branches' => 0]);
        UsageSnapshot::query()->create(['customer_id' => $on->customer_id, 'taken_on' => '2026-11-02', 'users' => 8, 'branches' => 0]);

        $near = $this->reports->usage()['near_limit'];

        $this->assertSame(['On'], array_column($near, 'customer'));
    }

    public function test_a_package_nobody_has_is_noticed(): void
    {
        $this->package(['code' => 'lonely', 'name' => 'Lonely']);
        $this->sub();

        $this->assertContains('Lonely', $this->reports->unusedPackages());
    }

    // --- Who may open them -------------------------------------------------

    public function test_the_money_reports_need_billing_and_the_rest_need_only_reports(): void
    {
        $money = ['/reports/revenue', '/reports/receivables'];
        $others = ['/reports/renewals', '/reports/usage'];

        // Sales reads reports but not the books.
        $sales = $this->staff('sales');
        foreach ($money as $url) {
            $this->actingAs($sales)->get($url)->assertForbidden();
        }
        foreach ($others as $url) {
            $this->actingAs($sales)->get($url)->assertOk();
        }

        foreach (['finance', 'viewer', 'owner'] as $role) {
            foreach (array_merge($money, $others) as $url) {
                $this->actingAs($this->staff($role))->get($url)->assertOk();
            }
        }

        // Support has neither.
        foreach (array_merge($money, $others) as $url) {
            $this->actingAs($this->staff('support'))->get($url)->assertForbidden();
        }
    }

    public function test_the_reports_entry_leads_each_role_to_a_page_it_may_open(): void
    {
        $this->actingAs($this->staff('finance'))->get('/reports')->assertRedirect('/reports/revenue');
        $this->actingAs($this->staff('sales'))->get('/reports')->assertRedirect('/reports/renewals');
    }

    public function test_the_pages_carry_what_the_screens_need(): void
    {
        $this->sub();

        $this->actingAs($this->staff('owner'));
        $this->get('/reports/revenue')->assertInertia(fn ($page) => $page->component('Reports/Revenue')->has('months')->has('currencies')->has('recurring.by_currency'));
        $this->get('/reports/receivables')->assertInertia(fn ($page) => $page->component('Reports/Receivables')->has('rows')->has('totals'));
        $this->get('/reports/renewals')->assertInertia(fn ($page) => $page->component('Reports/Renewals')->has('months')->has('lapsed'));
        $this->get('/reports/usage')->assertInertia(fn ($page) => $page->component('Reports/Usage')->has('packages')->has('near_limit')->has('unused'));
    }

    // --- Excel -------------------------------------------------------------

    private function csvOf(string $url): array
    {
        $response = $this->get($url)->assertOk();
        $this->assertStringStartsWith('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('.csv', $response->headers->get('Content-Disposition'));
        $content = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content, 'a byte order mark, so Excel reads Dari and Pashto as UTF-8');

        return array_map('str_getcsv', array_filter(explode("\n", substr($content, 3))));
    }

    public function test_each_report_can_be_taken_to_excel(): void
    {
        $sub = $this->sub(['name_en' => 'کابل ټریډرز']);
        $this->service->invoice($sub, ['issued_on' => '2026-10-25', 'due_on' => '2026-10-25', 'amount' => 100, 'currency' => 'AFN']);

        $revenue = $this->csvOf('/reports/revenue?export=csv');
        $this->assertSame(['from', 'to', 'currency', 'invoiced', 'collected'], $revenue[0]);

        $receivables = $this->csvOf('/reports/receivables?export=csv');
        $this->assertSame('کابل ټریډرز', $receivables[1][0], 'Persian and Pashto survive');
        $this->assertSame('AFN', $receivables[1][1]);

        $this->assertSame('renewed', $this->csvOf('/reports/renewals?export=csv')[0][2]);
        $this->assertSame('package', $this->csvOf('/reports/usage?export=csv')[0][0]);
    }

    public function test_a_name_that_looks_like_a_formula_is_not_run_by_excel(): void
    {
        $sub = $this->sub(['name_en' => '=HYPERLINK("http://evil.example","click")']);
        $this->service->invoice($sub, ['issued_on' => '2026-10-25', 'due_on' => '2026-10-25', 'amount' => 100, 'currency' => 'AFN']);

        $rows = $this->csvOf('/reports/receivables?export=csv');

        $this->assertStringStartsWith("'=", $rows[1][0]);
    }

    public function test_an_export_needs_the_same_permission_as_the_page(): void
    {
        $this->actingAs($this->staff('sales'))->get('/reports/revenue?export=csv')->assertForbidden();
        $this->actingAs($this->staff('sales'))->get('/reports/usage?export=csv')->assertOk();
        $this->actingAs($this->staff('support'))->get('/reports/usage?export=csv')->assertForbidden();
    }
}
