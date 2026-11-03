<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\MakesData;
use Tests\TestCase;

class DoctorTest extends TestCase
{
    use MakesData;
    use RefreshDatabase;

    /** A server set up the way it should be. Each test spoils one thing. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->detectEnvironment(fn () => 'production');
        config([
            'app.debug' => false,
            'app.url' => 'https://control.example.com',
            'session.secure' => true,
            'trustedproxy.proxies' => ['10.0.0.1'],
            'mail.default' => 'smtp',
            'queue.default' => 'database',
            'control.messaging.whatsapp.driver' => 'cloud',
            'control.messaging.whatsapp.token' => 'wa-token',
            'control.messaging.whatsapp.phone_number_id' => '1234567890',
        ]);
        $this->useLicenseKeys();
        $this->staff('owner');
        Cache::put('control:scheduler-heartbeat', now()->timestamp, 3600);
    }

    /** @return array{0: int, 1: string} */
    private function doctor(): array
    {
        $code = Artisan::call('control:doctor');

        return [$code, Artisan::output()];
    }

    private function assertFails(string $contains): void
    {
        [$code, $out] = $this->doctor();
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString($contains, $out);
        $this->assertMatchesRegularExpression('/FAIL/', $out);
    }

    private function assertWarns(string $contains): void
    {
        [$code, $out] = $this->doctor();
        $this->assertSame(0, $code, "a warning is not a failure:\n".$out);
        $this->assertStringContainsString($contains, $out);
        $this->assertStringContainsString('WARN', $out);
    }

    public function test_a_server_set_up_properly_has_nothing_to_complain_about(): void
    {
        [$code, $out] = $this->doctor();

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('Everything is in order.', $out);
        $this->assertStringNotContainsString('WARN', $out);
        $this->assertStringNotContainsString('FAIL', $out);
    }

    // --- The application ---------------------------------------------------

    public function test_debug_on_in_production_is_a_failure_but_only_a_note_elsewhere(): void
    {
        config(['app.debug' => true]);
        $this->assertFails('APP_DEBUG is on in production');

        $this->app->detectEnvironment(fn () => 'local');
        $this->assertWarns('APP_DEBUG is on (fine here');
    }

    public function test_no_app_key_fails(): void
    {
        config(['app.key' => '']);

        $this->assertFails('APP_KEY is not set');
    }

    public function test_plain_http_and_an_unsecured_cookie_are_warned_about(): void
    {
        config(['app.url' => 'http://control.example.com']);
        $this->assertWarns('APP_URL is not HTTPS');

        config(['app.url' => 'https://control.example.com', 'session.secure' => null]);
        $this->assertWarns('SESSION_SECURE_COOKIE is not true');
    }

    public function test_a_bad_timezone_fails(): void
    {
        config(['control.messaging.timezone' => 'Mars/Olympus']);

        $this->assertFails('MESSAGING_TIMEZONE is not a real timezone');
    }

    // --- The database ------------------------------------------------------

    public function test_a_migration_that_has_not_run_fails_and_says_how_many(): void
    {
        DB::table('migrations')->where('migration', 'like', '%create_messages_table')->delete();

        $this->assertFails('1 migration(s) have not run');
    }

    public function test_with_no_active_owner_it_fails_and_an_owner_without_two_factor_is_warned_about(): void
    {
        \App\Models\User::query()->delete();
        $this->assertFails('There is no active owner');

        $this->staff('owner', active: false);
        $this->assertFails('There is no active owner');

        $this->staff('owner', twoFactor: false);
        $this->assertWarns('No owner has set up two-factor yet');
    }

    public function test_messages_that_failed_are_counted(): void
    {
        $customer = Customer::query()->create(['name_en' => 'C']);
        Message::query()->create(['customer_id' => $customer->id, 'kind' => 'unblocked', 'channel' => 'email', 'to' => 'a@b.c', 'body' => 'x', 'status' => 'failed']);
        Message::query()->create(['customer_id' => $customer->id, 'kind' => 'unblocked', 'channel' => 'email', 'to' => 'a@b.c', 'body' => 'x', 'status' => 'failed']);

        $this->assertWarns('2 message(s) failed');
    }

    // --- Queue and scheduler -----------------------------------------------

    public function test_a_job_that_has_waited_too_long_means_the_worker_is_not_running(): void
    {
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => now()->subHour()->timestamp, 'created_at' => now()->subHour()->timestamp]);

        $this->assertWarns('waiting more than 10 minutes: is php artisan queue:work running?');
    }

    public function test_a_job_that_was_just_queued_is_fine(): void
    {
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp]);

        [$code, $out] = $this->doctor();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('1 job(s) waiting', $out);
        $this->assertStringNotContainsString('WARN', $out);
    }

    public function test_failed_jobs_and_the_sync_queue_are_warned_about(): void
    {
        DB::table('failed_jobs')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'boom', 'failed_at' => now()]);
        $this->assertWarns('1 job(s) failed');

        DB::table('failed_jobs')->delete();
        config(['queue.default' => 'sync']);
        $this->assertWarns('QUEUE_CONNECTION is sync');
    }

    public function test_a_scheduler_that_never_ran_or_stopped_is_warned_about(): void
    {
        Cache::forget('control:scheduler-heartbeat');
        $this->assertWarns('The scheduler has never run');

        Cache::put('control:scheduler-heartbeat', now()->subMinutes(20)->timestamp, 3600);
        $this->assertWarns('The scheduler last ran 20 minutes ago');
    }

    public function test_the_heartbeat_really_is_scheduled_every_minute(): void
    {
        $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->first(fn ($e) => $e->description === 'control:heartbeat');

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);

        Cache::forget('control:scheduler-heartbeat');
        $event->run($this->app);
        $this->assertNotNull(Cache::get('control:scheduler-heartbeat'));
    }

    // --- Licence keys ------------------------------------------------------

    public function test_a_public_key_that_is_not_the_other_half_of_the_private_one_fails_and_names_the_right_one(): void
    {
        $right = base64_encode(sodium_crypto_sign_publickey_from_secretkey(base64_decode(config('control.license.private_key'))));
        config(['control.license.public_key' => base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()))]);

        [$code, $out] = $this->doctor();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('every NextBook would refuse the licences', $out);
        $this->assertStringContainsString($right, $out, 'it says which key to give the NextBooks');
    }

    public function test_a_missing_or_damaged_private_key_fails(): void
    {
        config(['control.license.private_key' => null]);
        $this->assertFails('LICENSE_PRIVATE_KEY is missing or damaged');

        config(['control.license.private_key' => base64_encode('too short')]);
        $this->assertFails('LICENSE_PRIVATE_KEY is missing or damaged');
    }

    // --- Messages ----------------------------------------------------------

    public function test_mail_and_whatsapp_that_only_log_are_warned_about_in_production(): void
    {
        config(['mail.default' => 'log']);
        $this->assertWarns('MAIL_MAILER is log');

        config(['mail.default' => 'smtp', 'control.messaging.whatsapp.driver' => 'log']);
        $this->assertWarns('WHATSAPP_DRIVER is log');
    }

    public function test_whatsapp_on_the_cloud_api_without_what_it_needs_fails(): void
    {
        config(['control.messaging.whatsapp.token' => null]);
        $this->assertFails('WHATSAPP_TOKEN is empty');

        config(['control.messaging.whatsapp.token' => 'x', 'control.messaging.whatsapp.templates.expiring' => '']);
        $this->assertFails('no template name for: expiring');
    }

    // --- Behind a proxy ----------------------------------------------------

    public function test_a_proxy_that_is_trusted_tells_the_truth_about_the_visitor_and_one_that_is_not_does_not(): void
    {
        Route::middleware('web')->get('/_who', fn (Request $r) => ['ip' => $r->ip(), 'secure' => $r->isSecure()]);
        $headers = ['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Proto' => 'https'];

        // The caller is the proxy we trust: believe what it forwards.
        // Plain http:// on purpose: the test client would otherwise build https requests from APP_URL.
        $trusted = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])->withHeaders($headers)->getJson('http://control.example.com/_who')->json();
        $this->assertSame('203.0.113.9', $trusted['ip']);
        $this->assertTrue($trusted['secure']);

        // Anyone else cannot claim to be someone: the header is ignored.
        $stranger = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->withHeaders($headers)->getJson('http://control.example.com/_who')->json();
        $this->assertSame('198.51.100.7', $stranger['ip']);
        $this->assertFalse($stranger['secure']);
    }

    public function test_the_doctor_notes_when_no_proxy_is_trusted(): void
    {
        config(['trustedproxy.proxies' => null]);

        [$code, $out] = $this->doctor();

        $this->assertSame(0, $code, 'only a note: not every server is behind a proxy');
        $this->assertStringContainsString('TRUSTED_PROXIES is not set', $out);
    }
}
