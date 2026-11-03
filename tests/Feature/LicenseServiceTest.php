<?php

namespace Tests\Feature;

use App\Exceptions\ControlException;
use App\Services\LicenseService;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesData;
use Tests\TestCase;

class LicenseServiceTest extends TestCase
{
    use MakesData;
    use RefreshDatabase;

    private string $publicKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->publicKey = $this->useLicenseKeys();
        $this->actingAs($this->staff());
    }

    private function customerOnPackage(): \App\Models\Customer
    {
        $customer = $this->customer();
        $customer->instance()->create(['erp_company_id' => '01M6DTM02SCYWQ21QAJD5NYKHF']);
        app(SubscriptionService::class)->assign($customer, $this->package(), now()->startOfDay(), now()->addYear()->startOfDay());

        return $customer->fresh();
    }

    public function test_the_licence_says_what_the_customer_may_do(): void
    {
        $payload = app(LicenseService::class)->payload($this->customerOnPackage());

        $this->assertSame('active', $payload['state']);
        $this->assertTrue($payload['writes_allowed']);
        $this->assertSame(['expenses'], $payload['modules']);
        $this->assertSame(3, $payload['max_users']);
        $this->assertSame(1, $payload['max_branches']);
        $this->assertNull($payload['max_storage_mb'], 'null means unlimited');
        $this->assertSame('01M6DTM02SCYWQ21QAJD5NYKHF', $payload['erp_company_id']);
        $this->assertSame(now()->addYear()->toDateString(), $payload['ends_on']);
    }

    public function test_a_block_and_a_per_customer_limit_show_in_the_licence(): void
    {
        $customer = $this->customerOnPackage();
        $subscription = $customer->subscription;
        app(SubscriptionService::class)->block($subscription, 'Unpaid invoice');
        app(SubscriptionService::class)->override($subscription->fresh(), ['max_users' => 8], 'Two extra seats');

        $payload = app(LicenseService::class)->payload($customer->fresh());

        $this->assertSame('blocked', $payload['state']);
        $this->assertFalse($payload['writes_allowed']);
        $this->assertSame('Unpaid invoice', $payload['block_reason']);
        $this->assertSame(8, $payload['max_users']);
        $this->assertSame(3, $payload['license_version']);
    }

    public function test_a_genuine_licence_verifies_with_the_public_key_alone(): void
    {
        $license = app(LicenseService::class)->issue($this->customerOnPackage());

        $this->assertTrue(LicenseService::verify($license['payload'], $license['signature'], $this->publicKey));
    }

    public function test_changing_any_part_of_the_licence_breaks_the_signature(): void
    {
        $license = app(LicenseService::class)->issue($this->customerOnPackage());

        foreach ([['max_users', 999], ['modules', ['expenses', 'hr']], ['ends_on', '2099-01-01'], ['writes_allowed', true], ['state', 'active']] as [$field, $value]) {
            $forged = $license['payload'];
            $forged[$field] = $value;
            if ($forged === $license['payload']) {
                continue; // set to what it already was: not a change
            }
            $this->assertFalse(LicenseService::verify($forged, $license['signature'], $this->publicKey), "changing {$field}");
        }
    }

    public function test_a_licence_signed_with_another_key_is_rejected(): void
    {
        $license = app(LicenseService::class)->issue($this->customerOnPackage());
        $otherPublic = base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));

        $this->assertFalse(LicenseService::verify($license['payload'], $license['signature'], $otherPublic));
    }

    public function test_garbage_is_not_a_valid_signature_and_does_not_throw(): void
    {
        $payload = app(LicenseService::class)->payload($this->customerOnPackage());

        $this->assertFalse(LicenseService::verify($payload, 'not-base64!!', $this->publicKey));
        $this->assertFalse(LicenseService::verify($payload, base64_encode('short'), $this->publicKey));
        $this->assertFalse(LicenseService::verify($payload, base64_encode(str_repeat('a', 64)), 'also-not-a-key'));
    }

    public function test_the_signature_does_not_depend_on_the_order_of_the_keys(): void
    {
        $payload = ['b' => 1, 'a' => ['y' => 2, 'x' => 3], 'modules' => ['z', 'a']];
        $reordered = ['modules' => ['z', 'a'], 'a' => ['x' => 3, 'y' => 2], 'b' => 1];

        $this->assertSame(LicenseService::canonical($payload), LicenseService::canonical($reordered));
        $this->assertNotSame(LicenseService::canonical($payload), LicenseService::canonical(['b' => 1, 'a' => ['y' => 2, 'x' => 3], 'modules' => ['a', 'z']]), 'the order of a list does matter');
    }

    public function test_a_customer_without_a_subscription_gets_no_licence(): void
    {
        $this->expectException(ControlException::class);

        app(LicenseService::class)->issue($this->customer());
    }

    public function test_signing_without_a_key_fails_clearly(): void
    {
        config(['control.license.private_key' => null]);

        $this->expectException(ControlException::class);
        app(LicenseService::class)->sign(['a' => 1]);
    }
}
