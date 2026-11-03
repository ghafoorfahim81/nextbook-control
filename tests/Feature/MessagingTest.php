<?php

namespace Tests\Feature;

use App\Jobs\SendMessageJob;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Subscription;
use App\Services\Messaging\MessageComposer;
use App\Services\Messaging\Notifier;
use App\Services\Messaging\ReminderService;
use App\Services\SubscriptionService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Support\MakesData;
use Tests\TestCase;

class MessagingTest extends TestCase
{
    use MakesData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->staff('owner'));
        // 2026-11-03 is the 12th of Aban 1405.
        Carbon::setTestNow(Carbon::parse('2026-11-03 06:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function contact(Customer $customer, array $overrides = []): Contact
    {
        return $customer->contacts()->create($overrides + [
            'name' => 'Manager', 'role' => 'manager', 'email' => 'manager@example.test', 'whatsapp' => '0791234567',
            'is_primary' => false, 'wants_email' => true, 'wants_whatsapp' => true,
        ]);
    }

    /** A customer on a package whose subscription ends in $days days from the (Kabul) today. */
    private function endingIn(int $days, array $customer = [], array $contact = []): Subscription
    {
        $c = $this->customer($customer + ['name_en' => 'Kabul Traders', 'name_fa' => 'کابل ټریډرز', 'locale' => 'fa', 'status' => 'active']);
        $this->contact($c, $contact);

        return Subscription::query()->create([
            'customer_id' => $c->id, 'package_id' => $this->package(['code' => 'p'.Customer::query()->count()])->id,
            'starts_on' => '2026-01-01', 'ends_on' => ReminderService::today()->addDays($days)->toDateString(),
        ]);
    }

    private function sentMail()
    {
        return Mail::mailer('array')->getSymfonyTransport()->messages();
    }

    // --- Who is written to, and on what ------------------------------------

    public function test_the_manager_the_finance_contact_and_the_primary_are_written_to_and_no_one_else(): void
    {
        $customer = $this->customer();
        $manager = $this->contact($customer, ['name' => 'M', 'role' => 'manager']);
        $finance = $this->contact($customer, ['name' => 'F', 'role' => 'finance']);
        $primary = $this->contact($customer, ['name' => 'P', 'role' => 'technical', 'is_primary' => true]);
        $this->contact($customer, ['name' => 'T', 'role' => 'technical']);
        $this->contact($customer, ['name' => 'O', 'role' => null]);

        $names = app(Notifier::class)->recipients($customer)->pluck('name')->sort()->values()->all();

        $this->assertSame(['F', 'M', 'P'], $names);
    }

    public function test_each_contact_is_written_on_the_channels_they_allow_and_only_with_a_usable_address(): void
    {
        $customer = $this->customer(['locale' => 'fa']);
        $this->contact($customer, ['name' => 'Both', 'email' => 'both@example.test', 'whatsapp' => '0791111111']);
        $this->contact($customer, ['name' => 'NoEmail', 'email' => 'noemail@example.test', 'whatsapp' => '0792222222', 'wants_email' => false, 'role' => 'finance']);
        $this->contact($customer, ['name' => 'NoWhatsapp', 'email' => 'nowa@example.test', 'whatsapp' => '0793333333', 'wants_whatsapp' => false, 'role' => 'finance']);
        $this->contact($customer, ['name' => 'BadNumber', 'email' => 'bad@example.test', 'whatsapp' => 'not a number', 'phone' => null, 'role' => 'finance']);
        $this->contact($customer, ['name' => 'PhoneOnly', 'email' => null, 'whatsapp' => null, 'phone' => '+93 79 444 4444', 'role' => 'finance']);

        $result = app(Notifier::class)->notify('unblocked', $customer, [], 'x');

        $this->assertSame(6, $result['queued']);
        $rows = Message::query()->orderBy('to')->get(['channel', 'to'])->map(fn ($m) => "{$m->channel}:{$m->to}")->all();
        $this->assertEqualsCanonicalizing([
            'email:both@example.test', 'whatsapp:93791111111',
            'whatsapp:93792222222',
            'email:nowa@example.test',
            'email:bad@example.test',
            'whatsapp:93794444444',
        ], $rows);
    }

    public function test_with_nobody_to_write_to_that_is_recorded_once_and_visible(): void
    {
        $customer = $this->customer();
        $this->contact($customer, ['role' => 'technical']); // not a recipient

        $first = app(Notifier::class)->notify('expiring_7', $customer, [], '2026-11-10');
        $again = app(Notifier::class)->notify('expiring_7', $customer, [], '2026-11-10');

        $this->assertSame(1, $first['skipped']);
        $this->assertSame(1, $again['duplicates']);
        $row = Message::query()->firstOrFail();
        $this->assertSame('skipped', $row->status);
        $this->assertSame('none', $row->channel);
        $this->assertStringContainsString('No contact', $row->error);
    }

    // --- The words ---------------------------------------------------------

    public function test_the_text_is_in_the_customers_language_with_jalali_dates_and_persian_digits(): void
    {
        $customer = $this->customer(['name_en' => 'Kabul Traders', 'name_fa' => 'کابل ټریډرز', 'locale' => 'fa']);
        $data = ['package' => 'Starter', 'date' => Carbon::parse('2026-11-10'), 'days' => 7];

        $fa = app(MessageComposer::class)->compose('expiring_7', $customer, $data);
        $this->assertStringContainsString('۱۴۰۵/۰۸/۱۹', $fa['body']);   // 10 Nov 2026 = 19 Aban 1405
        $this->assertStringContainsString('۷ روز', $fa['body']);
        $this->assertStringContainsString('کابل ټریډرز', $fa['body']);
        $this->assertStringNotContainsString('2026', $fa['body']);
        $this->assertSame('اشتراک نکست‌بوک شما به‌زودی تمام می‌شود', $fa['subject']);

        $customer->locale = 'en';
        $en = app(MessageComposer::class)->compose('expiring_7', $customer, $data);
        $this->assertStringContainsString('2026-11-10', $en['body']);
        $this->assertStringContainsString('7 days left', $en['body']);
        $this->assertSame('Your NextBook subscription is ending soon', $en['subject']);

        $customer->locale = 'ps';
        $ps = app(MessageComposer::class)->compose('expiring_7', $customer, $data);
        $this->assertStringContainsString('۱۴۰۵/۰۸/۱۹', $ps['body']);
        $this->assertStringContainsString('ګډون', $ps['body']);
    }

    public function test_a_whatsapp_template_is_filled_in_the_order_the_templates_expect(): void
    {
        $customer = $this->customer(['name_en' => 'Kabul Traders', 'name_fa' => null, 'locale' => 'en']);
        $composer = app(MessageComposer::class);

        $this->assertSame(['Kabul Traders', 'Starter', '2026-11-10', '7'], $composer->compose('expiring_7', $customer, ['package' => 'Starter', 'date' => Carbon::parse('2026-11-10'), 'days' => 7])['params']);
        $this->assertSame(['Kabul Traders', 'Starter', '2026-11-10'], $composer->compose('expired', $customer, ['package' => 'Starter', 'date' => Carbon::parse('2026-11-10')])['params']);
        $this->assertSame(['Kabul Traders', 'Unpaid'], $composer->compose('blocked', $customer, ['reason' => 'Unpaid'])['params']);
        $this->assertSame(['Kabul Traders'], $composer->compose('unblocked', $customer)['params']);
        $this->assertSame(['Kabul Traders', '600 AFN', 'SUB-2026-0001'], $composer->compose('payment_received', $customer, ['amount' => '600 AFN', 'number' => 'SUB-2026-0001'])['params']);
        $this->assertSame('expiring', MessageComposer::group('expiring_14'));
        $this->assertSame('expired', MessageComposer::group('expired'));
    }

    public function test_every_kind_has_text_in_every_language(): void
    {
        foreach (['fa', 'ps', 'en'] as $locale) {
            foreach (array_keys(MessageComposer::PARAMS) as $group) {
                $this->assertNotSame("messaging.{$group}.subject", __("messaging.{$group}.subject", [], $locale), "{$locale} {$group} subject");
                $this->assertNotSame("messaging.{$group}.body", __("messaging.{$group}.body", [], $locale), "{$locale} {$group} body");
            }
        }
    }

    // --- Sending -----------------------------------------------------------

    public function test_an_email_is_sent_right_to_left_for_dari_and_records_that_it_was(): void
    {
        $customer = $this->customer(['locale' => 'fa']);
        $this->contact($customer, ['whatsapp' => null, 'wants_whatsapp' => false]);

        app(Notifier::class)->notify('unblocked', $customer, [], 'x');

        $message = Message::query()->firstOrFail();
        $this->assertSame('sent', $message->status);
        $this->assertNotNull($message->sent_at);
        $sent = $this->sentMail();
        $this->assertCount(1, $sent);
        $email = $sent->first()->getOriginalMessage();
        $this->assertSame('manager@example.test', $email->getTo()[0]->getAddress());
        $this->assertSame('بلاک حساب نکست‌بوک شما برداشته شد', $email->getSubject());
        $this->assertStringContainsString('dir="rtl"', $email->getHtmlBody());
        $this->assertStringContainsString('بلاک حساب شما برداشته شد', $email->getTextBody());
    }

    public function test_an_english_email_is_left_to_right(): void
    {
        $customer = $this->customer(['locale' => 'en']);
        $this->contact($customer, ['whatsapp' => null, 'wants_whatsapp' => false]);

        app(Notifier::class)->notify('unblocked', $customer, [], 'x');

        $this->assertStringContainsString('dir="ltr"', $this->sentMail()->first()->getOriginalMessage()->getHtmlBody());
    }

    public function test_with_the_log_driver_a_whatsapp_message_is_recorded_as_sent_but_nothing_leaves(): void
    {
        Http::fake();
        $customer = $this->customer();
        $this->contact($customer, ['email' => null, 'wants_email' => false]);

        app(Notifier::class)->notify('unblocked', $customer, [], 'x');

        $message = Message::query()->firstOrFail();
        $this->assertSame('sent', $message->status);
        $this->assertStringStartsWith('log-', $message->provider_id);
        Http::assertNothingSent();
    }

    private function cloud(): void
    {
        config(['control.messaging.whatsapp.driver' => 'cloud', 'control.messaging.whatsapp.token' => 'wa-token', 'control.messaging.whatsapp.phone_number_id' => '1234567890']);
    }

    public function test_through_the_cloud_api_a_template_is_sent_with_its_values_in_order(): void
    {
        $this->cloud();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.ABC']]])]);
        $customer = $this->customer(['name_en' => 'Kabul Traders', 'name_fa' => null, 'locale' => 'en']);
        $this->contact($customer, ['email' => null, 'wants_email' => false]);

        app(Notifier::class)->notify('expiring_7', $customer, ['package' => 'Starter', 'date' => Carbon::parse('2026-11-10'), 'days' => 7], '2026-11-10');

        $message = Message::query()->firstOrFail();
        $this->assertSame('sent', $message->status);
        $this->assertSame('wamid.ABC', $message->provider_id);
        Http::assertSent(function ($request) {
            $template = $request['template'];

            return $request->url() === 'https://graph.facebook.com/v21.0/1234567890/messages'
                && $request->hasHeader('Authorization', 'Bearer wa-token')
                && $request['messaging_product'] === 'whatsapp'
                && $request['to'] === '93791234567'
                && $request['type'] === 'template'
                && $template['name'] === 'nextbook_expiring'
                && $template['language']['code'] === 'en'
                && array_column($template['components'][0]['parameters'], 'text') === ['Kabul Traders', 'Starter', '2026-11-10', '7'];
        });
    }

    public function test_a_wrong_number_or_an_unapproved_template_fails_at_once_with_the_providers_reason(): void
    {
        $this->cloud();
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Template name does not exist in the translation']], 400)]);
        $customer = $this->customer();
        $this->contact($customer, ['email' => null, 'wants_email' => false]);

        app(Notifier::class)->notify('unblocked', $customer, [], 'x');

        $message = Message::query()->firstOrFail();
        $this->assertSame('failed', $message->status);
        $this->assertSame('Template name does not exist in the translation', $message->error);
        $this->assertSame(1, $message->attempts, 'a permanent problem is not retried');
        Http::assertSentCount(1);
    }

    public function test_a_failure_of_one_channel_does_not_stop_the_other(): void
    {
        $this->cloud();
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'bad']], 400)]);
        $customer = $this->customer(['locale' => 'en']);
        $this->contact($customer);

        app(Notifier::class)->notify('unblocked', $customer, [], 'x');

        $this->assertSame('failed', Message::query()->where('channel', 'whatsapp')->firstOrFail()->status);
        $this->assertSame('sent', Message::query()->where('channel', 'email')->firstOrFail()->status);
    }

    public function test_cloud_whatsapp_without_credentials_or_a_template_fails_with_a_clear_message(): void
    {
        config(['control.messaging.whatsapp.driver' => 'cloud']);
        Http::fake();
        $customer = $this->customer();
        $this->contact($customer, ['email' => null, 'wants_email' => false]);

        app(Notifier::class)->notify('unblocked', $customer, [], 'a');
        $this->assertStringContainsString('WHATSAPP_TOKEN', Message::query()->firstOrFail()->error);

        $this->cloud();
        config(['control.messaging.whatsapp.templates.unblocked' => null]);
        app(Notifier::class)->notify('unblocked', $customer, [], 'b');
        $this->assertStringContainsString('No WhatsApp template', Message::query()->latest('id')->firstOrFail()->error);
        Http::assertNothingSent();
    }

    public function test_a_server_error_is_retryable_but_only_on_a_real_queue(): void
    {
        $this->cloud();
        Http::fake(['graph.facebook.com/*' => Http::response([], 503)]);
        $customer = $this->customer();
        $this->contact($customer, ['email' => null, 'wants_email' => false]);

        // Run inline (as in these tests) there is nothing to retry later, so it is recorded as failed.
        app(Notifier::class)->notify('unblocked', $customer, [], 'x');

        $this->assertSame('failed', Message::query()->firstOrFail()->status);
    }

    public function test_a_message_that_was_already_sent_is_not_sent_again_if_the_job_runs_twice(): void
    {
        $customer = $this->customer(['locale' => 'en']);
        $this->contact($customer, ['whatsapp' => null, 'wants_whatsapp' => false]);
        app(Notifier::class)->notify('unblocked', $customer, [], 'x');
        $id = Message::query()->firstOrFail()->id;

        (new SendMessageJob($id))->handle(app(\App\Services\Messaging\Channels\EmailChannel::class), app(\App\Services\Messaging\Channels\WhatsAppChannel::class));

        $this->assertCount(1, $this->sentMail());
    }

    public function test_the_same_message_about_the_same_thing_is_never_written_twice(): void
    {
        $customer = $this->customer();
        $this->contact($customer, ['whatsapp' => null, 'wants_whatsapp' => false]);
        $notifier = app(Notifier::class);

        $this->assertSame(1, $notifier->notify('expiring_7', $customer, [], '2026-11-10')['queued']);
        $this->assertSame(1, $notifier->notify('expiring_7', $customer, [], '2026-11-10')['duplicates']);
        $this->assertSame(1, $notifier->notify('expiring_7', $customer, [], '2027-11-10')['queued'], 'a new period is a new message');
        $this->assertSame(1, $notifier->notify('expiring_1', $customer, [], '2026-11-10')['queued'], 'a different kind is a different message');
        $this->assertSame(3, Message::query()->count());
    }

    // --- Which reminder is due ---------------------------------------------

    public function test_the_thresholds_and_what_a_missed_day_does(): void
    {
        $service = app(ReminderService::class);
        $kind = fn (int $days) => $service->kindFor($this->endingIn($days, ['name_en' => 'C'.$days])->fresh(), ReminderService::today());

        $this->assertNull($kind(40));
        $this->assertSame('expiring_30', $kind(30));
        $this->assertSame('expiring_30', $kind(20), 'between the first and the second: the first is still owed');
        $this->assertSame('expiring_14', $kind(14));
        $this->assertSame('expiring_14', $kind(8));
        $this->assertSame('expiring_7', $kind(7));
        $this->assertSame('expiring_1', $kind(1));
        $this->assertSame('expiring_1', $kind(0), 'the day it ends');
        $this->assertSame('expired', $kind(-1));
        $this->assertSame('expired', $kind(-3));
        $this->assertNull($kind(-4), 'a lapsed customer is a matter for a person');
        $this->assertNull($kind(-60));
    }

    public function test_a_blocked_subscription_is_not_reminded(): void
    {
        $subscription = $this->endingIn(7);
        $subscription->forceFill(['blocked_at' => now()])->save();

        $this->assertNull(app(ReminderService::class)->kindFor($subscription->fresh(), ReminderService::today()));
    }

    public function test_today_is_the_date_in_kabul_not_in_utc(): void
    {
        // 21:00 UTC on the 3rd is already 01:30 on the 4th in Kabul (UTC+4:30).
        Carbon::setTestNow(Carbon::parse('2026-11-03 21:00:00', 'UTC'));

        $this->assertSame('2026-11-04', ReminderService::today()->toDateString());
    }

    public function test_the_run_writes_to_those_who_are_due_and_skips_those_who_are_not_in_play(): void
    {
        $this->endingIn(7, ['name_en' => 'Due Soon']);
        $this->endingIn(90, ['name_en' => 'Far Away']);
        $this->endingIn(7, ['name_en' => 'Cancelled', 'status' => 'cancelled']);
        $this->endingIn(7, ['name_en' => 'Archived', 'status' => 'archived']);
        $blocked = $this->endingIn(7, ['name_en' => 'Blocked']);
        $blocked->forceFill(['blocked_at' => now()])->save();
        $this->endingIn(-2, ['name_en' => 'Just Ended']);

        $summary = app(ReminderService::class)->run();

        // Cancelled and archived are not even looked at: of the six, four are.
        $this->assertSame(4, $summary['checked']);
        $this->assertSame(2, $summary['due']);
        $this->assertEqualsCanonicalizing(['expiring_7', 'expired'], Message::query()->pluck('kind')->unique()->all());
        $this->assertSame(['Due Soon', 'Just Ended'], Customer::query()->whereHas('messages')->orderBy('name_en')->pluck('name_en')->all());
    }

    public function test_running_twice_the_same_day_sends_nothing_more(): void
    {
        $this->endingIn(7);

        $first = app(ReminderService::class)->run();
        $second = app(ReminderService::class)->run();

        $this->assertSame(2, $first['queued']);   // email and whatsapp of the one contact
        $this->assertSame(0, $second['queued']);
        $this->assertSame(2, $second['duplicates']);
        $this->assertSame(2, Message::query()->count());
    }

    public function test_after_a_renewal_the_next_period_gets_its_own_reminders(): void
    {
        $subscription = $this->endingIn(7);
        app(ReminderService::class)->run();
        $this->assertSame(2, Message::query()->count());

        // Renewed for a year, then a year later it is ending again.
        $subscription->forceFill(['ends_on' => ReminderService::today()->addYear()->addDays(7)])->save();
        Carbon::setTestNow(Carbon::parse('2027-11-03 06:00:00', 'UTC'));
        app(ReminderService::class)->run();

        $this->assertSame(4, Message::query()->count());
    }

    public function test_a_dry_run_says_what_is_due_and_sends_nothing(): void
    {
        $this->endingIn(7, ['name_en' => 'Due Soon']);

        $summary = app(ReminderService::class)->run(null, dryRun: true);

        $this->assertSame(1, $summary['due']);
        $this->assertStringContainsString('expiring_7', $summary['lines'][0]);
        $this->assertSame(0, Message::query()->count());
    }

    public function test_the_command_runs_for_a_given_day_and_can_be_dry(): void
    {
        $this->endingIn(7, ['name_en' => 'Due Soon']);

        $this->artisan('control:reminders --dry-run')->expectsOutputToContain('1 due')->assertSuccessful();
        $this->assertSame(0, Message::query()->count());

        // As if it were 8 days later, it has ended.
        $this->artisan('control:reminders --date=2026-11-12')->expectsOutputToContain('expired')->assertSuccessful();
        $this->assertTrue(Message::query()->where('kind', 'expired')->exists());
    }

    public function test_the_reminders_are_scheduled_every_morning_in_kabul(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'control:reminders'));

        $this->assertNotNull($event);
        $this->assertSame('0 8 * * *', $event->expression);
        $this->assertSame('Asia/Kabul', (string) $event->timezone);
    }

    // --- Things that happen ------------------------------------------------

    private function subscribed(array $contact = []): Subscription
    {
        $customer = $this->customer(['name_en' => 'Kabul Traders', 'name_fa' => null, 'locale' => 'en']);
        $this->contact($customer, $contact + ['whatsapp' => null, 'wants_whatsapp' => false]);

        return app(SubscriptionService::class)->assign($customer, $this->package(), now()->startOfDay(), now()->addYear()->startOfDay());
    }

    public function test_blocking_and_unblocking_tell_the_customer(): void
    {
        $subscription = $this->subscribed();
        $service = app(SubscriptionService::class);

        $service->block($subscription, 'Unpaid invoice SUB-2026-0001');
        $service->unblock($subscription->fresh());

        $this->assertSame(['blocked', 'unblocked'], Message::query()->orderBy('created_at')->orderBy('id')->pluck('kind')->all());
        $first = $this->sentMail()->first()->getOriginalMessage();
        $this->assertStringContainsString('Unpaid invoice SUB-2026-0001', $first->getTextBody());
    }

    public function test_a_payment_is_acknowledged_with_its_amount_and_invoice_number(): void
    {
        $subscription = $this->subscribed();
        $service = app(SubscriptionService::class);
        $invoice = $service->invoice($subscription, ['issued_on' => '2026-11-03', 'amount' => 1000, 'currency' => 'AFN']);

        $service->recordPayment($invoice, ['amount' => 250.5, 'paid_on' => '2026-11-03', 'method' => 'cash']);

        $message = Message::query()->where('kind', 'payment_received')->firstOrFail();
        $this->assertStringContainsString('250.5 AFN', $message->body);
        $this->assertStringContainsString($invoice->number, $message->body);
    }

    public function test_a_failure_in_messaging_never_stops_the_action_it_follows(): void
    {
        $subscription = $this->subscribed();
        $this->mock(Notifier::class)->shouldReceive('notify')->andThrow(new \RuntimeException('mail server on fire'));

        $blocked = app(SubscriptionService::class)->block($subscription, 'x');

        $this->assertTrue($blocked->isBlocked(), 'the block happened regardless');
    }

    public function test_an_event_that_is_switched_off_sends_nothing(): void
    {
        config(['control.messaging.events' => ['payment_received']]);
        $subscription = $this->subscribed();

        app(SubscriptionService::class)->block($subscription, 'x');

        $this->assertSame(0, Message::query()->count());
    }

    // --- Contacts ----------------------------------------------------------

    private function contactForm(array $overrides = []): array
    {
        return $overrides + ['name' => 'Ahmad', 'role' => 'finance', 'email' => 'ahmad@example.test', 'whatsapp' => '0791234567', 'is_primary' => false, 'wants_email' => true, 'wants_whatsapp' => true];
    }

    public function test_a_contact_is_added_edited_and_removed_with_an_audit_trail(): void
    {
        $customer = $this->customer();

        $this->post("/customers/{$customer->id}/contacts", $this->contactForm())->assertRedirect()->assertSessionHasNoErrors();
        $contact = $customer->contacts()->firstOrFail();
        $this->assertSame('finance', $contact->role);

        $this->put("/contacts/{$contact->id}", $this->contactForm(['name' => 'Ahmad Zai', 'wants_whatsapp' => false]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertFalse($contact->fresh()->wants_whatsapp);
        $log = \App\Models\AuditLog::query()->where('action', 'contact.updated')->firstOrFail();
        $this->assertSame('Ahmad', $log->before['name']);
        $this->assertSame('Ahmad Zai', $log->after['name']);

        $this->delete("/contacts/{$contact->id}")->assertRedirect();
        $this->assertSame(0, $customer->contacts()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'contact.removed', 'customer_id' => $customer->id]);
    }

    public function test_a_contact_needs_some_way_to_be_reached_and_a_real_number(): void
    {
        $customer = $this->customer();

        $this->post("/customers/{$customer->id}/contacts", $this->contactForm(['email' => null, 'whatsapp' => null, 'phone' => null]))->assertSessionHasErrors('email');
        $this->post("/customers/{$customer->id}/contacts", $this->contactForm(['whatsapp' => 'call me']))->assertSessionHasErrors('whatsapp');
        $this->post("/customers/{$customer->id}/contacts", $this->contactForm(['email' => 'not an email']))->assertSessionHasErrors('email');
        $this->post("/customers/{$customer->id}/contacts", $this->contactForm(['role' => 'boss']))->assertSessionHasErrors('role');
        $this->post("/customers/{$customer->id}/contacts", ['name' => '', 'email' => 'a@example.test'])->assertSessionHasErrors('name');
        $this->assertSame(0, $customer->contacts()->count());

        // A phone alone is enough.
        $this->post("/customers/{$customer->id}/contacts", ['name' => 'P', 'phone' => '0791234567'])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_only_one_contact_is_primary_at_a_time(): void
    {
        $customer = $this->customer();
        $first = $this->contact($customer, ['name' => 'A', 'is_primary' => true]);

        $this->post("/customers/{$customer->id}/contacts", $this->contactForm(['name' => 'B', 'is_primary' => true]))->assertRedirect();

        $this->assertFalse($first->fresh()->is_primary);
        $this->assertSame(1, $customer->contacts()->where('is_primary', true)->count());
    }

    public function test_contacts_and_retries_are_for_those_who_manage_customers(): void
    {
        $customer = $this->customer();
        $contact = $this->contact($customer);
        $message = Message::query()->create(['customer_id' => $customer->id, 'kind' => 'unblocked', 'channel' => 'email', 'to' => 'a@b.c', 'body' => 'x', 'status' => 'failed']);

        foreach (['support', 'viewer', 'finance'] as $role) {
            $user = $this->staff($role);
            $this->actingAs($user)->post("/customers/{$customer->id}/contacts", $this->contactForm())->assertForbidden();
            $this->actingAs($user)->put("/contacts/{$contact->id}", $this->contactForm())->assertForbidden();
            $this->actingAs($user)->delete("/contacts/{$contact->id}")->assertForbidden();
            $this->actingAs($user)->post("/messages/{$message->id}/retry")->assertForbidden();
        }

        $this->actingAs($this->staff('sales'))->post("/customers/{$customer->id}/contacts", $this->contactForm(['name' => 'By sales']))->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_a_failed_message_can_be_tried_again_and_only_a_failed_one(): void
    {
        $customer = $this->customer(['locale' => 'en']);
        $failed = Message::query()->create(['customer_id' => $customer->id, 'kind' => 'unblocked', 'channel' => 'email', 'to' => 'a@example.test', 'locale' => 'en', 'subject' => 'S', 'body' => 'B', 'status' => 'failed', 'error' => 'boom', 'attempts' => 3]);
        $sent = Message::query()->create(['customer_id' => $customer->id, 'kind' => 'unblocked', 'channel' => 'email', 'to' => 'a@example.test', 'locale' => 'en', 'subject' => 'S', 'body' => 'B', 'status' => 'sent']);

        $this->post("/messages/{$sent->id}/retry")->assertSessionHas('error');
        $this->assertCount(0, $this->sentMail());

        $this->post("/messages/{$failed->id}/retry")->assertRedirect()->assertSessionMissing('error');
        $this->assertSame('sent', $failed->fresh()->status);
        $this->assertNull($failed->fresh()->error);
        $this->assertCount(1, $this->sentMail());
    }

    // --- What the screens get ----------------------------------------------

    public function test_the_customer_page_carries_contacts_with_whether_whatsapp_could_reach_them_and_the_latest_messages(): void
    {
        $customer = $this->customer();
        $this->contact($customer, ['name' => 'Reachable', 'is_primary' => true]);
        $this->contact($customer, ['name' => 'Bad', 'whatsapp' => 'nope', 'phone' => null, 'role' => 'finance']);
        Message::query()->create(['customer_id' => $customer->id, 'kind' => 'expiring_7', 'channel' => 'email', 'to' => 'a@b.c', 'body' => 'x', 'status' => 'failed', 'error' => 'boom']);

        $this->get("/customers/{$customer->id}")->assertInertia(fn ($page) => $page
            ->where('contacts.0.name', 'Reachable')
            ->where('contacts.0.whatsapp_ok', true)
            ->where('contacts.1.whatsapp_ok', false)
            ->where('messages.0.status', 'failed')
            ->where('messages.0.error', 'boom'));
    }

    public function test_the_dashboard_counts_messages_that_failed(): void
    {
        $customer = $this->customer();
        foreach (['failed', 'failed', 'sent'] as $status) {
            Message::query()->create(['customer_id' => $customer->id, 'kind' => 'unblocked', 'channel' => 'email', 'to' => 'a@b.c', 'body' => 'x', 'status' => $status]);
        }

        $this->get('/dashboard')->assertInertia(fn ($page) => $page->where('counts.messages_failed', 2));
    }

    public function test_the_artisan_command_is_registered(): void
    {
        $this->assertArrayHasKey('control:reminders', Artisan::all());
    }
}
