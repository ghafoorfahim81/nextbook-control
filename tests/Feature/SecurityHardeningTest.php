<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\LoginController;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\PasswordVerifier;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Tests\Support\MakesData;
use Tests\TestCase;

/**
 * What a security review of the control center found, each as a test of what
 * must now be true.
 */
class SecurityHardeningTest extends TestCase
{
    use MakesData;
    use RefreshDatabase;

    private const PASSWORD = 'the-old-password-12';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(array $state = []): User
    {
        $user = $this->staff('owner');
        $user->forceFill(['password' => self::PASSWORD] + $state)->save();

        return $user;
    }

    // --- The clock must not say which emails are staff ---------------------

    public function test_a_password_is_hashed_for_an_unknown_email_and_a_switched_off_account_too(): void
    {
        $hasher = Mockery::mock(Hasher::class);
        $hasher->shouldReceive('make')->andReturn('a-hash-that-matches-nothing');
        $checked = [];
        $hasher->shouldReceive('check')->andReturnUsing(function ($plain, $hash) use (&$checked) {
            $checked[] = $hash;

            return true;     // would match, if it were ever used: it must not be trusted for these two
        });
        $verifier = new PasswordVerifier($hasher);

        $off = $this->staff('owner', active: false);
        $on = $this->staff('owner');

        $this->assertFalse($verifier->verify(null, 'pw'), 'no such account');
        $this->assertFalse($verifier->verify($off, 'pw'), 'switched off, even with a matching hash');
        $this->assertTrue($verifier->verify($on, 'pw'));

        // Every one of the three paid for a hash check; the first two against the stand-in.
        $this->assertCount(3, $checked);
        $this->assertSame('a-hash-that-matches-nothing', $checked[0]);
        $this->assertSame('a-hash-that-matches-nothing', $checked[1]);
        $this->assertSame($on->password, $checked[2]);
    }

    public function test_a_real_login_still_works_through_the_verifier(): void
    {
        $user = $this->member();

        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertRedirect('/two-factor-challenge');
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password-123'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => 'nobody@example.test', 'password' => self::PASSWORD])->assertSessionHasErrors('email');
    }

    // --- One machine cannot walk a list of emails --------------------------

    public function test_failed_logins_are_limited_per_address_whatever_the_email(): void
    {
        $user = $this->member();

        foreach (range(1, LoginController::MAX_ATTEMPTS_PER_ADDRESS) as $i) {
            $this->post('/login', ['email' => "guess{$i}@example.test", 'password' => 'wrong-password-123']);
        }

        // From this address even the right password is now refused for a while.
        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_the_limit_on_one_address_does_not_lock_everyone_else_out(): void
    {
        $user = $this->member();
        foreach (range(1, LoginController::MAX_ATTEMPTS_PER_ADDRESS) as $i) {
            $this->post('/login', ['email' => "guess{$i}@example.test", 'password' => 'wrong-password-123']);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertRedirect('/two-factor-challenge');
    }

    // --- A half-finished sign-in does not wait forever ---------------------

    public function test_the_code_step_is_good_for_ten_minutes_and_no_longer(): void
    {
        Carbon::setTestNow('2026-11-03 09:00:00');
        $user = $this->member();
        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertRedirect('/two-factor-challenge');

        Carbon::setTestNow('2026-11-03 09:09:00');
        $this->get('/two-factor-challenge')->assertOk();

        Carbon::setTestNow('2026-11-03 09:11:00');
        $this->get('/two-factor-challenge')->assertRedirect('/login');
        $this->post('/two-factor-challenge', ['code' => $this->otp($user)])->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_after_it_expires_the_password_has_to_be_given_again(): void
    {
        Carbon::setTestNow('2026-11-03 09:00:00');
        $user = $this->member();
        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD]);

        Carbon::setTestNow('2026-11-03 09:30:00');
        $this->post('/two-factor-challenge', ['code' => $this->otp($user)]);

        // Not signed in, and the old pending state is gone, not merely ignored.
        $this->assertGuest();
        $this->assertNull(session('two_factor.user_id'));
    }

    // --- The API counts a wrong token ---------------------------------------

    public function test_wrong_tokens_count_against_the_limit_so_a_token_cannot_be_guessed_at_full_speed(): void
    {
        $statuses = [];
        foreach (range(1, 125) as $_) {
            $statuses[] = $this->getJson('/api/license', ['Authorization' => 'Bearer not-a-real-token'])->status();
        }

        $this->assertSame(401, $statuses[0]);
        $this->assertSame(401, $statuses[119]);
        $this->assertSame(429, $statuses[120], 'the 121st attempt in a minute is refused, right token or not');
        $this->assertSame(429, $statuses[124]);
    }

    // --- Your own password --------------------------------------------------

    public function test_a_password_someone_else_saw_must_be_replaced_before_anything_else(): void
    {
        $user = $this->member(['must_change_password' => true]);
        $this->actingAs($user);

        foreach (['/dashboard', '/customers', '/packages', '/reports/usage', '/audit', '/staff'] as $url) {
            $this->get($url)->assertRedirect('/account/password');
        }

        $this->get('/account/password')->assertOk()->assertInertia(fn ($page) => $page->component('Account/Password')->where('forced', true));
        $this->post('/logout')->assertRedirect('/login');
    }

    public function test_choosing_your_own_clears_it_and_opens_the_rest(): void
    {
        $user = $this->member(['must_change_password' => true]);
        $this->actingAs($user);

        $this->put('/account/password', ['current_password' => self::PASSWORD, 'password' => 'a-brand-new-secret-9', 'password_confirmation' => 'a-brand-new-secret-9'])
            ->assertRedirect('/dashboard')->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertNotNull($user->password_changed_at);
        $this->assertTrue(Hash::check('a-brand-new-secret-9', $user->password));
        $this->get('/dashboard')->assertOk();
    }

    public function test_the_new_password_is_checked(): void
    {
        $this->actingAs($this->member());
        $good = ['current_password' => self::PASSWORD, 'password' => 'a-brand-new-secret-9', 'password_confirmation' => 'a-brand-new-secret-9'];

        $this->put('/account/password', ['current_password' => 'not-my-password-1'] + $good)->assertSessionHasErrors('current_password');
        $this->put('/account/password', ['password' => 'short', 'password_confirmation' => 'short'] + $good)->assertSessionHasErrors('password');
        $this->put('/account/password', ['password_confirmation' => 'something-different-9'] + $good)->assertSessionHasErrors('password');
        $this->put('/account/password', ['password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD] + $good)->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check(self::PASSWORD, User::query()->firstOrFail()->password), 'nothing changed');
    }

    public function test_changing_it_signs_out_every_other_session_and_is_audited_without_the_password(): void
    {
        $user = $this->member();
        DB::table('sessions')->insert(['id' => 'elsewhere', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $this->actingAs($user);

        $this->put('/account/password', ['current_password' => self::PASSWORD, 'password' => 'a-brand-new-secret-9', 'password_confirmation' => 'a-brand-new-secret-9'])->assertRedirect();

        $this->assertSame(0, DB::table('sessions')->where('id', 'elsewhere')->count());
        $log = AuditLog::query()->where('action', 'staff.password_changed')->firstOrFail();
        $this->assertSame($user->id, $log->staff_id);
        $this->assertStringNotContainsString('a-brand-new-secret-9', json_encode(AuditLog::query()->get()->toArray()));
        $this->assertStringNotContainsString(self::PASSWORD, json_encode(AuditLog::query()->get()->toArray()));
    }

    public function test_guessing_the_current_password_is_limited(): void
    {
        $this->actingAs($this->member());
        $attempt = ['current_password' => 'wrong-password-123', 'password' => 'a-brand-new-secret-9', 'password_confirmation' => 'a-brand-new-secret-9'];

        foreach (range(1, 6) as $_) {
            $this->put('/account/password', $attempt)->assertSessionHasErrors('current_password');
        }

        $this->put('/account/password', ['current_password' => self::PASSWORD] + $attempt)->assertStatus(429);
    }

    public function test_every_role_may_change_its_own_password_and_a_guest_may_not(): void
    {
        foreach (['owner', 'sales', 'finance', 'support', 'viewer'] as $role) {
            $this->actingAs($this->staff($role))->get('/account/password')->assertOk();
        }

        $this->post('/logout');
        $this->get('/account/password')->assertRedirect('/login');
        $this->put('/account/password', [])->assertRedirect('/login');
    }

    public function test_a_new_member_a_reset_and_the_command_all_make_the_owner_choose_their_own(): void
    {
        $owner = $this->staff('owner');

        $this->actingAs($owner)->post('/staff', ['name' => 'New', 'email' => 'new@example.test', 'role' => 'sales'])->assertRedirect('/staff');
        $this->assertTrue(User::query()->where('email', 'new@example.test')->firstOrFail()->must_change_password);

        $other = $this->staff('sales');
        $this->assertFalse($other->fresh()->must_change_password);
        $this->actingAs($owner)->post("/staff/{$other->id}/password")->assertRedirect();
        $this->assertTrue($other->fresh()->must_change_password);

        $this->artisan('control:staff made@example.test sales --name=Made')->assertSuccessful();
        $this->assertTrue(User::query()->where('email', 'made@example.test')->firstOrFail()->must_change_password);
    }

    public function test_changing_a_role_with_the_command_does_not_force_a_password_change(): void
    {
        $user = $this->staff('sales');

        $this->artisan("control:staff {$user->email} finance")->assertSuccessful();

        $this->assertSame('finance', $user->fresh()->role);
        $this->assertFalse($user->fresh()->must_change_password, 'no password was touched');
    }
}
