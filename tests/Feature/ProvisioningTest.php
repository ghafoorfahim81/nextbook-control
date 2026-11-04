<?php

namespace Tests\Feature;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\MakesData;
use Tests\TestCase;

/**
 * Creating a customer's company in NextBook from here. The request lets
 * someone make an administrator in a customer's system, so what is tested is
 * that only the owner can send it, that it is signed so the ERP can tell, that
 * it carries no password, and that a failure on the other side leaves no
 * half-connected customer here.
 */
class ProvisioningTest extends TestCase
{
    use MakesData;
    use RefreshDatabase;

    private const COMPANY = '01M6DTM02SCYWQ21QAJD5NYKH9';

    private string $publicKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->publicKey = $this->useLicenseKeys();
        config(['control.erp.url' => 'https://erp.test']);
        $this->fakeErp();
    }

    /** A second Http::fake() would not replace the first (the first match wins), so start clean. */
    private function fakeErp(?array $response = null, int $status = 201): void
    {
        Http::swap(new Factory());
        Http::fake(['erp.test/*' => Http::response($response ?? [
            'created' => true, 'company_id' => self::COMPANY, 'branch_id' => '01M6DTM02SCYWQ21QAJD5NYKHB',
            'license_ok' => true, 'license_message' => 'Licence version 1 received.',
        ], $status)]);
    }

    private function ready(array $overrides = []): Customer
    {
        return $this->customer($overrides + [
            'name_fa' => 'تاجران کابل', 'name_ps' => 'د کابل سوداګر', 'city' => 'Kabul',
            'business_type' => 'retail', 'base_currency' => 'AFN', 'calendar_type' => 'jalali', 'locale' => 'fa',
        ]);
    }

    private function form(array $overrides = []): array
    {
        return $overrides + ['admin_name' => 'Ahmad Noori', 'admin_email' => 'ahmad@kabul-traders.test'];
    }

    private function provision(Customer $customer, array $form = [], string $role = 'owner')
    {
        return $this->actingAs($this->staff($role))->post("/customers/{$customer->id}/provision", $this->form($form));
    }

    // --- A good run ---------------------------------------------------------

    public function test_the_owner_creates_the_company_and_the_customer_is_tied_to_it(): void
    {
        $customer = $this->ready();

        $this->provision($customer)->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success');

        $instance = $customer->instance()->firstOrFail();
        $this->assertSame(self::COMPANY, $instance->erp_company_id);
        $this->assertSame('https://erp.test', $instance->base_url);
        $this->assertNotNull($instance->api_token_hash);
    }

    public function test_the_request_is_signed_the_way_the_erp_checks_it_and_carries_what_the_company_needs(): void
    {
        $customer = $this->ready();
        $this->contactFor($customer);

        $this->provision($customer)->assertSessionHasNoErrors();

        Http::assertSent(function (Request $request) use ($customer) {
            $body = $request->body();
            $message = implode("\n", ['provision.v1', $request->header('X-Control-Timestamp')[0], $request->header('X-Control-Nonce')[0], $body]);

            $this->assertSame('https://erp.test/api/control/provision', $request->url());
            $this->assertTrue(
                sodium_crypto_sign_verify_detached(base64_decode($request->header('X-Control-Signature')[0]), $message, base64_decode($this->publicKey)),
                'The ERP would not accept this signature.',
            );
            $this->assertEqualsWithDelta(now()->timestamp, (int) $request->header('X-Control-Timestamp')[0], 5);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $request->header('X-Control-Nonce')[0]);

            $data = json_decode($body, true);
            $this->assertSame($customer->id, $data['customer_id']);
            $this->assertSame(['name' => 'Ahmad Noori', 'email' => 'ahmad@kabul-traders.test'], $data['admin']);
            $this->assertSame('Kabul Traders', $data['company']['name_en']);
            $this->assertSame('د کابل سوداګر', $data['company']['name_pa']);
            $this->assertSame('retail', $data['company']['business_type']);
            $this->assertSame('0700000000', $data['company']['phone']);
            $this->assertArrayNotHasKey('password', $data['admin']);

            // The token sent is the one whose hash is now stored, so the ERP's
            // first licence request is recognised.
            $this->assertSame(hash('sha256', $data['token']), $this->customerInstance($customer)->api_token_hash);

            return true;
        });
    }

    public function test_every_request_has_a_nonce_of_its_own(): void
    {
        $this->provision($this->ready())->assertSessionHasNoErrors();
        $this->provision($this->ready(['name_en' => 'Second']), ['admin_email' => 'second@kabul-traders.test'])->assertSessionHasNoErrors();

        $nonces = collect(Http::recorded())->map(fn ($pair) => $pair[0]->header('X-Control-Nonce')[0]);
        $this->assertCount(2, $nonces);
        $this->assertCount(2, $nonces->unique());
    }

    public function test_a_field_left_empty_is_not_sent_as_empty(): void
    {
        $customer = $this->ready(['name_fa' => null, 'name_ps' => null, 'city' => null]);

        $this->provision($customer)->assertSessionHasNoErrors();

        Http::assertSent(function (Request $request) {
            $company = json_decode($request->body(), true)['company'];
            foreach (['name_fa', 'name_pa', 'city', 'phone'] as $field) {
                $this->assertArrayNotHasKey($field, $company);
            }

            return true;
        });
    }

    public function test_the_audit_trail_says_who_and_where_and_holds_neither_a_token_nor_a_password(): void
    {
        $customer = $this->ready();
        $this->provision($customer);

        $row = \App\Models\AuditLog::query()->where('action', 'instance.provisioned')->firstOrFail();
        $this->assertSame($customer->id, $row->customer_id);
        $this->assertSame(self::COMPANY, $row->after['erp_company_id']);
        $this->assertSame('ahmad@kabul-traders.test', $row->after['admin_email']);

        $stored = json_encode($row->toArray());
        $token = json_decode(Http::recorded()[0][0]->body(), true)['token'];
        $this->assertStringNotContainsString($token, $stored);
        $this->assertStringNotContainsString('password', strtolower($stored));
    }

    public function test_a_customer_with_its_own_address_is_sent_there_instead(): void
    {
        $customer = $this->ready();
        $customer->instance()->create(['base_url' => 'https://erp.kabul-traders.test/']);
        Http::swap(new Factory());
        Http::fake(['erp.kabul-traders.test/*' => Http::response(['created' => true, 'company_id' => self::COMPANY, 'license_ok' => false, 'license_message' => ''], 201)]);

        $this->provision($customer)->assertSessionHasNoErrors();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://erp.kabul-traders.test/api/control/provision');
    }

    public function test_asking_again_after_a_lost_reply_connects_to_the_company_the_erp_already_made(): void
    {
        $customer = $this->ready();
        $this->fakeErp(['created' => false, 'company_id' => self::COMPANY, 'license_ok' => true, 'license_message' => ''], 200);

        $this->provision($customer)->assertSessionHasNoErrors()->assertSessionHas('success', __('control.provisioned_again'));

        $this->assertSame(self::COMPANY, $customer->instance()->firstOrFail()->erp_company_id);
    }

    // --- Who may ----------------------------------------------------------

    public function test_only_the_owner_may_create_a_company(): void
    {
        foreach (['sales', 'finance', 'support', 'viewer'] as $role) {
            $customer = $this->ready(['name_en' => "For {$role}"]);

            $this->provision($customer, role: $role)->assertForbidden();
            $this->assertNull($customer->instance()->first()?->erp_company_id, $role);
        }

        Http::assertNothingSent();
    }

    public function test_the_page_offers_the_button_only_to_the_owner_and_only_until_there_is_a_company(): void
    {
        $customer = $this->ready(['business_type' => null]);

        $this->actingAs($this->staff('owner'))->get("/customers/{$customer->id}")->assertInertia(fn ($page) => $page
            ->where('provision.missing', ['business_type'])
            ->where('provision.target', 'https://erp.test'));

        $this->actingAs($this->staff('sales'))->get("/customers/{$customer->id}")->assertInertia(fn ($page) => $page->where('provision', null));

        $customer->instance()->create(['erp_company_id' => self::COMPANY]);
        $this->actingAs($this->staff('owner'))->get("/customers/{$customer->id}")->assertInertia(fn ($page) => $page->where('provision', null));
    }

    // --- Refusals before anything is sent -------------------------------------

    public function test_an_incomplete_customer_is_told_what_is_missing_and_nothing_is_sent(): void
    {
        $customer = $this->ready(['business_type' => null]);

        $this->provision($customer)->assertSessionHasErrors('provision');

        $this->assertStringContainsString('business_type', session('errors')->first('provision'));
        Http::assertNothingSent();
        $this->assertNull($customer->instance()->first()?->api_token_hash);
    }

    public function test_the_administrator_must_be_named_with_a_real_email(): void
    {
        $customer = $this->ready();

        $this->provision($customer, ['admin_email' => 'not-an-email', 'admin_name' => ''])->assertSessionHasErrors(['admin_email', 'admin_name']);

        Http::assertNothingSent();
    }

    public function test_without_an_address_nothing_is_sent(): void
    {
        config(['control.erp.url' => null]);

        $this->provision($this->ready())->assertSessionHasErrors('provision');

        Http::assertNothingSent();
    }

    public function test_production_refuses_a_plain_http_address_because_the_token_would_travel_with_it(): void
    {
        config(['control.erp.url' => 'http://erp.test']);
        $this->app['env'] = 'production';

        // Straight at the service: in production the whole site is https-only,
        // so a test request over http would never reach it.
        try {
            app(\App\Services\ErpProvisioner::class)->provision($this->ready(), 'Ahmad', 'ahmad@kabul-traders.test');
            $this->fail('A plain http address should be refused in production.');
        } catch (\App\Exceptions\ControlException $e) {
            $this->assertStringContainsString('https://', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_a_customer_that_already_has_a_company_is_not_asked_for_another(): void
    {
        $customer = $this->ready();
        $customer->instance()->create(['erp_company_id' => self::COMPANY]);

        $this->provision($customer)->assertSessionHasErrors('provision');

        Http::assertNothingSent();
    }

    public function test_it_cannot_be_hammered(): void
    {
        $customer = $this->ready(['business_type' => null]);
        $owner = $this->staff('owner');

        foreach (range(1, 3) as $ignored) {
            $this->actingAs($owner)->post("/customers/{$customer->id}/provision", $this->form())->assertSessionHasErrors('provision');
        }
        $this->actingAs($owner)->post("/customers/{$customer->id}/provision", $this->form())->assertStatus(429);
    }

    // --- When the ERP says no -----------------------------------------------

    public function test_a_refusal_from_the_erp_is_explained_and_leaves_the_customer_unconnected(): void
    {
        $customer = $this->ready();
        $this->fakeErp(['message' => 'The request is not valid.', 'errors' => ['admin.email' => ['That e-mail already belongs to a user.']]], 422);

        $this->provision($customer)->assertSessionHasErrors('provision');

        $this->assertStringContainsString('already belongs to a user', session('errors')->first('provision'));
        $this->assertNull($customer->instance()->firstOrFail()->erp_company_id);
    }

    public function test_the_common_failures_each_get_their_own_explanation(): void
    {
        $says = [];
        foreach ([404 => 'CONTROL_PROVISIONING', 401 => 'CONTROL_PUBLIC_KEY', 403 => 'https', 429 => 'too often', 500 => '500'] as $status => $contains) {
            $customer = $this->ready(['name_en' => "Status {$status}"]);
            $this->fakeErp(['message' => 'x'], $status);

            // The throttle is per owner; a new one each time keeps this about the messages.
            $this->provision($customer)->assertSessionHasErrors('provision');
            $this->assertStringContainsString($contains, session('errors')->first('provision'), (string) $status);
            $says[] = session('errors')->first('provision');
        }

        $this->assertCount(5, array_unique($says));
    }

    public function test_an_erp_that_cannot_be_reached_leaves_nothing_connected(): void
    {
        $customer = $this->ready();
        Http::swap(new Factory());
        Http::fake(fn () => throw new ConnectionException('down'));

        $this->provision($customer)->assertSessionHasErrors('provision');

        $this->assertNull($customer->instance()->firstOrFail()->erp_company_id);
    }

    public function test_an_answer_without_a_company_id_is_not_believed(): void
    {
        $customer = $this->ready();
        $this->fakeErp(['created' => true, 'company_id' => 'short'], 201);

        $this->provision($customer)->assertSessionHasErrors('provision');

        $this->assertNull($customer->instance()->firstOrFail()->erp_company_id);
    }

    public function test_a_redirect_is_not_followed_with_the_token_in_the_body(): void
    {
        $customer = $this->ready();
        Http::swap(new Factory());
        Http::fake([
            'erp.test/*' => Http::response('', 302, ['Location' => 'https://elsewhere.test/steal']),
            'elsewhere.test/*' => Http::response('', 200),
        ]);

        $this->provision($customer)->assertSessionHasErrors('provision');

        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'elsewhere'));
    }

    public function test_without_a_signing_key_nothing_is_sent(): void
    {
        config(['control.license.private_key' => null]);

        $this->provision($this->ready())->assertSessionHasErrors('provision');

        Http::assertNothingSent();
    }

    private function customerInstance(Customer $customer)
    {
        return $customer->instance()->firstOrFail();
    }

    private function contactFor(Customer $customer): void
    {
        $customer->contacts()->create(['name' => 'Ahmad Noori', 'phone' => '0700000000', 'email' => 'ahmad@kabul-traders.test', 'is_primary' => true]);
    }
}
