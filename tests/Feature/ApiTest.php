<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Instance;
use App\Models\UsageSnapshot;
use App\Services\LicenseService;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesData;
use Tests\TestCase;

class ApiTest extends TestCase
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

    /** @return array{0: Customer, 1: string} the customer and its ERP token */
    private function connected(string $name = 'Kabul Traders', bool $subscribe = true): array
    {
        $customer = $this->customer(['name_en' => $name]);
        $instance = $customer->instance()->create(['erp_company_id' => '01M6DTM02SCYWQ21QAJD5NYKHF']);
        if ($subscribe) {
            app(SubscriptionService::class)->assign($customer, $this->package(['code' => 'p-'.strtolower(str_replace(' ', '', $name))]), now()->startOfDay(), now()->addYear()->startOfDay());
        }

        return [$customer->fresh(), $instance->issueToken()];
    }

    private function asErp(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    public function test_an_erp_gets_its_own_signed_licence(): void
    {
        [$customer, $token] = $this->connected();

        $response = $this->getJson('/api/license', $this->asErp($token))->assertOk();

        $this->assertSame($customer->id, $response->json('payload.customer_id'));
        $this->assertSame(['expenses'], $response->json('payload.modules'));
        $this->assertSame(7, $response->json('grace_days'));
        $this->assertTrue(LicenseService::verify($response->json('payload'), $response->json('signature'), $this->publicKey));
        $this->assertNotNull(Instance::query()->firstOrFail()->last_license_pull_at);
    }

    public function test_without_a_token_or_with_a_wrong_one_nothing_is_given(): void
    {
        $this->connected();

        $this->getJson('/api/license')->assertUnauthorized();
        $this->getJson('/api/license', $this->asErp('not-a-real-token'))->assertUnauthorized();
        $this->postJson('/api/usage', ['users' => 1, 'branches' => 1])->assertUnauthorized();
    }

    public function test_each_erp_sees_only_its_own_customer(): void
    {
        [$kabul, $kabulToken] = $this->connected('Kabul Traders');
        [$herat, $heratToken] = $this->connected('Herat Pharmacy');

        $this->assertSame($kabul->id, $this->getJson('/api/license', $this->asErp($kabulToken))->json('payload.customer_id'));
        $this->assertSame($herat->id, $this->getJson('/api/license', $this->asErp($heratToken))->json('payload.customer_id'));
    }

    public function test_only_the_hash_of_a_token_is_kept(): void
    {
        [, $token] = $this->connected();

        $stored = Instance::query()->firstOrFail();
        $this->assertNotSame($token, $stored->api_token_hash);
        $this->assertSame(hash('sha256', $token), $stored->api_token_hash);
        $this->assertArrayNotHasKey('api_token_hash', $stored->toArray());
    }

    public function test_a_new_token_replaces_the_old_one(): void
    {
        [, $old] = $this->connected();

        $new = Instance::query()->firstOrFail()->issueToken();

        $this->getJson('/api/license', $this->asErp($old))->assertUnauthorized();
        $this->getJson('/api/license', $this->asErp($new))->assertOk();
    }

    public function test_a_customer_without_a_subscription_has_no_licence_to_pull(): void
    {
        [, $token] = $this->connected(subscribe: false);

        $this->getJson('/api/license', $this->asErp($token))->assertNotFound();
    }

    public function test_an_archived_customer_is_refused(): void
    {
        [$customer, $token] = $this->connected();
        $customer->update(['status' => 'archived']);

        $this->getJson('/api/license', $this->asErp($token))->assertForbidden();
    }

    public function test_a_block_reaches_the_erp_on_its_next_pull(): void
    {
        [$customer, $token] = $this->connected();
        $this->assertFalse($this->getJson('/api/license', $this->asErp($token))->json('payload.blocked'));

        app(SubscriptionService::class)->block($customer->subscription, 'Unpaid');

        $payload = $this->getJson('/api/license', $this->asErp($token))->json('payload');
        $this->assertTrue($payload['blocked']);
        $this->assertSame('Unpaid', $payload['block_reason']);
        $this->assertSame(2, $payload['license_version']);
    }

    public function test_usage_is_stored_once_per_day_and_the_latest_wins(): void
    {
        [$customer, $token] = $this->connected();

        $this->postJson('/api/usage', ['users' => 3, 'branches' => 1, 'storage_mb' => 120, 'documents_this_month' => 40, 'erp_version' => '1.4.0'], $this->asErp($token))->assertOk();
        $this->postJson('/api/usage', ['users' => 4, 'branches' => 2], $this->asErp($token))->assertOk();

        $this->assertSame(1, UsageSnapshot::query()->count());
        $snapshot = UsageSnapshot::query()->firstOrFail();
        $this->assertSame($customer->id, $snapshot->customer_id);
        $this->assertSame(4, $snapshot->users);
        $this->assertSame(2, $snapshot->branches);
        $this->assertSame('1.4.0', Instance::query()->firstOrFail()->erp_version, 'a later report without a version does not erase it');
        $this->assertNotNull(Instance::query()->firstOrFail()->last_usage_at);
    }

    public function test_usage_for_one_customer_never_lands_on_another(): void
    {
        [, $kabulToken] = $this->connected('Kabul Traders');
        [$herat] = $this->connected('Herat Pharmacy');

        $this->postJson('/api/usage', ['users' => 9, 'branches' => 9], $this->asErp($kabulToken))->assertOk();

        $this->assertSame(0, UsageSnapshot::query()->where('customer_id', $herat->id)->count());
    }

    public function test_bad_usage_is_rejected(): void
    {
        [, $token] = $this->connected();

        $this->postJson('/api/usage', ['users' => -1, 'branches' => 'many'], $this->asErp($token))
            ->assertStatus(422)->assertJsonValidationErrors(['users', 'branches']);
        $this->postJson('/api/usage', [], $this->asErp($token))->assertStatus(422);
        $this->assertSame(0, UsageSnapshot::query()->count());
    }
}
