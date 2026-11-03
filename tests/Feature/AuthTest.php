<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\LoginController;
use App\Models\User;
use App\Services\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\MakesData;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use MakesData;
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery';

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('x');
    }

    private function member(array $state = [], bool $twoFactor = true): User
    {
        $user = $this->staff('owner', true, $twoFactor);
        $user->forceFill(['password' => self::PASSWORD] + $state)->save();

        return $user;
    }

    private function logIn(User $user, string $password = self::PASSWORD)
    {
        return $this->post('/login', ['email' => $user->email, 'password' => $password]);
    }

    // --- Password ----------------------------------------------------------

    public function test_the_pages_are_closed_to_a_guest_and_the_login_page_is_open(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/customers')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertInertia(fn ($page) => $page->component('Auth/Login'));
    }

    public function test_a_wrong_password_an_unknown_email_and_a_switched_off_account_look_the_same(): void
    {
        $user = $this->member();
        $off = $this->member(['is_active' => false]);

        $messages = [
            $this->logIn($user, 'wrong-password')->assertSessionHasErrors('email')->baseResponse->getSession()->get('errors')->first('email'),
            $this->post('/login', ['email' => 'nobody@example.test', 'password' => 'whatever-it-is'])->baseResponse->getSession()->get('errors')->first('email'),
            $this->logIn($off)->baseResponse->getSession()->get('errors')->first('email'),
        ];

        $this->assertCount(1, array_unique($messages), 'the three cases must not be told apart');
        $this->assertGuest();
    }

    public function test_after_too_many_wrong_passwords_even_the_right_one_is_refused_for_a_while(): void
    {
        $user = $this->member();

        foreach (range(1, LoginController::MAX_ATTEMPTS) as $_) {
            $this->logIn($user, 'wrong-password');
        }

        $this->logIn($user)->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    // --- Two-factor is required --------------------------------------------

    public function test_an_account_without_two_factor_is_signed_in_but_held_at_set_up(): void
    {
        $user = $this->member(twoFactor: false);

        $this->logIn($user)->assertRedirect('/two-factor-setup');
        $this->assertAuthenticatedAs($user);

        // Nothing else opens until it is done.
        $this->get('/dashboard')->assertRedirect('/two-factor-setup');
        $this->get('/customers')->assertRedirect('/two-factor-setup');
    }

    public function test_set_up_shows_a_key_and_only_a_right_code_turns_it_on(): void
    {
        $user = $this->member(twoFactor: false);
        $this->actingAs($user);

        $this->get('/two-factor-setup')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Auth/TwoFactorSetup')
            ->where('step', 'scan')
            ->has('secret')
            ->where('otpauthUri', fn ($uri) => str_starts_with($uri, 'otpauth://totp/')));

        $this->post('/two-factor-setup', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertFalse($user->fresh()->hasTwoFactor());

        $this->post('/two-factor-setup', ['code' => $this->otp($user->fresh())])->assertRedirect('/two-factor-setup');
        $this->assertTrue($user->fresh()->hasTwoFactor());
    }

    public function test_the_secret_stays_the_same_while_set_up_is_reloaded(): void
    {
        $user = $this->member(twoFactor: false);
        $this->actingAs($user);

        $first = $this->get('/two-factor-setup')->viewData('page')['props']['secret'];
        $second = $this->get('/two-factor-setup')->viewData('page')['props']['secret'];

        $this->assertSame($first, $second, 'the QR on screen must stay the one in the app');
    }

    public function test_confirming_shows_recovery_codes_once_and_keeps_only_their_hashes(): void
    {
        $user = $this->member(twoFactor: false);
        $this->actingAs($user);
        $this->get('/two-factor-setup');

        $this->post('/two-factor-setup', ['code' => $this->otp($user->fresh())]);
        $codes = $this->get('/two-factor-setup')->assertInertia(fn ($page) => $page->where('step', 'recovery')->has('recoveryCodes', TwoFactor::RECOVERY_CODES))
            ->viewData('page')['props']['recoveryCodes'];

        $stored = $user->fresh()->two_factor_recovery_codes;
        $this->assertCount(TwoFactor::RECOVERY_CODES, $stored);
        $this->assertEmpty(array_intersect($codes, $stored), 'the codes themselves are not stored');

        // Shown once: a reload goes on to the dashboard.
        $this->get('/two-factor-setup')->assertRedirect('/dashboard');
    }

    public function test_a_secret_and_recovery_codes_are_encrypted_and_never_sent_to_the_browser(): void
    {
        $user = $this->member();
        $user->forceFill(['two_factor_recovery_codes' => ['abc']])->save();

        $raw = \DB::table('users')->where('id', $user->id)->first();
        $this->assertNotSame($user->two_factor_secret, $raw->two_factor_secret);
        $this->assertStringNotContainsString('abc', (string) $raw->two_factor_recovery_codes);

        $this->assertArrayNotHasKey('two_factor_secret', $user->toArray());
        $this->assertArrayNotHasKey('two_factor_recovery_codes', $user->toArray());
    }

    // --- The challenge -----------------------------------------------------

    public function test_with_two_factor_on_the_password_alone_does_not_sign_in(): void
    {
        $user = $this->member();

        $this->logIn($user)->assertRedirect('/two-factor-challenge');

        $this->assertGuest();
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_the_right_code_signs_in(): void
    {
        $user = $this->member();
        $this->logIn($user);

        $this->post('/two-factor-challenge', ['code' => $this->otp($user)])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        $this->get('/dashboard')->assertOk();
    }

    public function test_a_wrong_code_does_not(): void
    {
        $user = $this->member();
        $this->logIn($user);

        $this->post('/two-factor-challenge', ['code' => '123456'])->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_a_code_works_once_and_cannot_be_replayed(): void
    {
        $user = $this->member();
        $code = $this->otp($user);

        $this->logIn($user);
        $this->post('/two-factor-challenge', ['code' => $code])->assertRedirect('/dashboard');
        $this->post('/logout');

        // Same code, seconds later, as if seen over a shoulder.
        $this->logIn($user);
        $this->post('/two-factor-challenge', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_a_recovery_code_works_once(): void
    {
        $user = $this->member();
        $recovery = 'abcde-fghij';
        $user->forceFill(['two_factor_recovery_codes' => [hash('sha256', $recovery), hash('sha256', 'zzzzz-yyyyy')]])->save();

        $this->logIn($user);
        $this->post('/two-factor-challenge', ['recovery_code' => $recovery])->assertRedirect('/dashboard');
        $this->assertCount(1, $user->fresh()->two_factor_recovery_codes);
        $this->post('/logout');

        $this->logIn($user);
        $this->post('/two-factor-challenge', ['recovery_code' => $recovery])->assertSessionHasErrors('recovery_code');
        $this->assertGuest();
    }

    public function test_guessing_codes_is_limited(): void
    {
        $user = $this->member();
        $this->logIn($user);

        foreach (range(1, LoginController::MAX_ATTEMPTS) as $_) {
            $this->post('/two-factor-challenge', ['code' => '111111']);
        }

        // Now even the right code is refused.
        $this->post('/two-factor-challenge', ['code' => $this->otp($user)])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_the_challenge_cannot_be_reached_without_a_password_first(): void
    {
        $this->get('/two-factor-challenge')->assertRedirect('/login');
        $this->post('/two-factor-challenge', ['code' => '123456'])->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_an_account_switched_off_between_the_password_and_the_code_is_turned_away(): void
    {
        $user = $this->member();
        $this->logIn($user);
        $user->forceFill(['is_active' => false])->save();

        $this->post('/two-factor-challenge', ['code' => $this->otp($user)])->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_signing_out_ends_the_session(): void
    {
        $user = $this->staff();

        $this->actingAs($user)->post('/logout')->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_a_signed_in_user_is_sent_on_from_the_login_page(): void
    {
        $this->actingAs($this->staff())->get('/login')->assertRedirect('/dashboard');
    }
}
