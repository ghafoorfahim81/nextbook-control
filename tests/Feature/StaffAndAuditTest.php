<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\MakesData;
use Tests\TestCase;

class StaffAndAuditTest extends TestCase
{
    use MakesData;
    use RefreshDatabase;

    private function form(array $overrides = []): array
    {
        return $overrides + ['name' => 'New Person', 'email' => 'new@example.test', 'role' => 'sales'];
    }

    // --- Who may open them -------------------------------------------------

    public function test_only_the_owner_manages_staff_and_reads_the_audit_trail(): void
    {
        foreach (['sales', 'finance', 'support', 'viewer'] as $role) {
            $user = $this->staff($role);
            $this->actingAs($user)->get('/staff')->assertForbidden();
            $this->actingAs($user)->get('/staff/create')->assertForbidden();
            $this->actingAs($user)->post('/staff', $this->form(['email' => "$role@example.test"]))->assertForbidden();
            $this->actingAs($user)->get('/audit')->assertForbidden();
        }

        $owner = $this->staff('owner');
        $this->actingAs($owner)->get('/staff')->assertOk()->assertInertia(fn ($page) => $page->component('Staff/Index'));
        $this->actingAs($owner)->get('/audit')->assertOk()->assertInertia(fn ($page) => $page->component('Audit/Index'));
    }

    // --- Making and changing staff -----------------------------------------

    public function test_a_new_member_gets_a_generated_password_shown_once_and_never_stored_in_the_trail(): void
    {
        $owner = $this->staff('owner');

        $this->actingAs($owner)->post('/staff', $this->form())->assertRedirect('/staff');

        $password = $this->get('/staff')->assertInertia(fn ($page) => $page->whereType('generatedPassword', 'string')->where('generatedFor', 'new@example.test'))
            ->viewData('page')['props']['generatedPassword'];
        $this->assertGreaterThanOrEqual(16, strlen($password));

        // Once.
        $this->get('/staff')->assertInertia(fn ($page) => $page->where('generatedPassword', null));

        $user = User::query()->where('email', 'new@example.test')->firstOrFail();
        $this->assertSame('sales', $user->role);
        $this->assertTrue($user->is_active);
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertFalse($user->hasTwoFactor(), 'they set two-factor up at first sign-in');

        $this->assertStringNotContainsString($password, json_encode(AuditLog::query()->get()->toArray()));
        $this->assertDatabaseHas('audit_logs', ['action' => 'staff.created', 'staff_id' => $owner->id]);
    }

    public function test_the_new_member_can_sign_in_and_is_held_at_two_factor_set_up(): void
    {
        $this->actingAs($this->staff('owner'))->post('/staff', $this->form());
        $password = $this->get('/staff')->viewData('page')['props']['generatedPassword'];
        $this->post('/logout');

        $this->post('/login', ['email' => 'new@example.test', 'password' => $password])->assertRedirect('/two-factor-setup');
    }

    public function test_the_form_is_validated(): void
    {
        $owner = $this->actingAs($this->staff('owner'));
        $this->staff('viewer')->forceFill(['email' => 'taken@example.test'])->save();

        $owner->post('/staff', $this->form(['email' => 'taken@example.test', 'role' => 'god']))->assertSessionHasErrors(['email', 'role']);
        $owner->post('/staff', ['name' => '', 'email' => 'not-an-email', 'role' => 'sales'])->assertSessionHasErrors(['name', 'email']);
        $this->assertSame(2, User::query()->count());
    }

    public function test_changing_a_role_is_audited_and_ends_that_persons_sessions(): void
    {
        $owner = $this->staff('owner');
        $other = $this->staff('sales');
        DB::table('sessions')->insert(['id' => 's1', 'user_id' => $other->id, 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($owner)->put("/staff/{$other->id}", ['name' => 'Renamed', 'role' => 'finance', 'is_active' => true])->assertRedirect('/staff');

        $this->assertSame('finance', $other->fresh()->role);
        $log = AuditLog::query()->where('action', 'staff.updated')->firstOrFail();
        $this->assertSame('sales', $log->before['role']);
        $this->assertSame('finance', $log->after['role']);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $other->id)->count(), 'a different role takes effect now');
    }

    public function test_renaming_alone_leaves_the_sessions(): void
    {
        $other = $this->staff('sales');
        DB::table('sessions')->insert(['id' => 's1', 'user_id' => $other->id, 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($this->staff('owner'))->put("/staff/{$other->id}", ['name' => 'Renamed', 'role' => 'sales', 'is_active' => true])->assertRedirect('/staff');

        $this->assertSame(1, DB::table('sessions')->where('user_id', $other->id)->count());
    }

    // --- The rules that keep someone in charge -----------------------------

    public function test_you_cannot_switch_yourself_off(): void
    {
        $owner = $this->staff('owner');
        $this->staff('owner'); // another owner exists, so it is the self rule that stops this

        $this->actingAs($owner)->put("/staff/{$owner->id}", ['name' => 'Me', 'role' => 'owner', 'is_active' => false])->assertSessionHas('error');

        $this->assertTrue($owner->fresh()->is_active);
    }

    public function test_the_last_active_owner_cannot_be_demoted_or_switched_off_by_anyone(): void
    {
        $owner = $this->staff('owner');

        // Demoting oneself while being the only owner.
        $this->actingAs($owner)->put("/staff/{$owner->id}", ['name' => 'Me', 'role' => 'viewer', 'is_active' => true])->assertSessionHas('error');
        $this->assertSame('owner', $owner->fresh()->role);

        // A second owner who is switched off does not count as one.
        $off = $this->staff('owner', active: false);
        $this->actingAs($owner)->put("/staff/{$owner->id}", ['name' => 'Me', 'role' => 'sales', 'is_active' => true])->assertSessionHas('error');
        $this->assertSame('owner', $owner->fresh()->role);

        // With another active owner it is allowed.
        $off->forceFill(['is_active' => true])->save();
        $this->actingAs($owner)->put("/staff/{$owner->id}", ['name' => 'Me', 'role' => 'sales', 'is_active' => true])->assertRedirect('/staff')->assertSessionMissing('error');
        $this->assertSame('sales', $owner->fresh()->role);
    }

    public function test_the_other_owner_cannot_be_switched_off_if_they_are_the_only_one_left(): void
    {
        $a = $this->staff('owner');
        $b = $this->staff('owner');

        $this->actingAs($a)->put("/staff/{$b->id}", ['name' => 'B', 'role' => 'owner', 'is_active' => false])->assertRedirect('/staff')->assertSessionMissing('error');
        $this->assertFalse($b->fresh()->is_active);

        // Now a is the last one.
        $this->actingAs($a)->put("/staff/{$a->id}", ['name' => 'A', 'role' => 'owner', 'is_active' => false])->assertSessionHas('error');
    }

    public function test_a_switched_off_member_is_signed_out_on_their_next_request(): void
    {
        $member = $this->staff('sales');
        $this->actingAs($this->staff('owner'))->put("/staff/{$member->id}", ['name' => 'X', 'role' => 'sales', 'is_active' => false])->assertRedirect('/staff');

        $this->actingAs($member->fresh())->get('/customers')->assertRedirect('/login');
        $this->assertGuest();
    }

    // --- Passwords and two-factor ------------------------------------------

    public function test_resetting_a_password_replaces_it_shows_the_new_one_once_and_ends_sessions(): void
    {
        $member = $this->staff('sales');
        $member->forceFill(['password' => 'the-old-password-12'])->save();
        DB::table('sessions')->insert(['id' => 's1', 'user_id' => $member->id, 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($this->staff('owner'))->post("/staff/{$member->id}/password")->assertRedirect();

        $new = $this->get('/staff')->viewData('page')['props']['generatedPassword'];
        $this->assertNotNull($new);
        $this->get('/staff')->assertInertia(fn ($page) => $page->where('generatedPassword', null));

        $member->refresh();
        $this->assertFalse(Hash::check('the-old-password-12', $member->password));
        $this->assertTrue(Hash::check($new, $member->password));
        $this->assertSame(0, DB::table('sessions')->where('user_id', $member->id)->count());
        $this->assertStringNotContainsString($new, json_encode(AuditLog::query()->get()->toArray()));
        $this->assertDatabaseHas('audit_logs', ['action' => 'staff.password_reset']);
    }

    public function test_resetting_two_factor_sends_them_back_to_set_it_up(): void
    {
        $member = $this->staff('sales');
        $member->forceFill(['password' => 'the-old-password-12'])->save();
        $this->assertTrue($member->hasTwoFactor());
        RateLimiter::clear('x');

        $this->actingAs($this->staff('owner'))->post("/staff/{$member->id}/two-factor-reset")->assertRedirect();
        $this->post('/logout');

        $member->refresh();
        $this->assertFalse($member->hasTwoFactor());
        $this->assertNull($member->two_factor_secret);
        $this->post('/login', ['email' => $member->email, 'password' => 'the-old-password-12'])->assertRedirect('/two-factor-setup');
        $this->assertDatabaseHas('audit_logs', ['action' => 'staff.two_factor_reset']);
    }

    public function test_only_the_owner_may_reset_passwords_or_two_factor(): void
    {
        $member = $this->staff('sales');

        foreach (['finance', 'support', 'viewer'] as $role) {
            $this->actingAs($this->staff($role))->post("/staff/{$member->id}/password")->assertForbidden();
            $this->actingAs($this->staff($role))->post("/staff/{$member->id}/two-factor-reset")->assertForbidden();
        }
    }

    // --- The audit screen --------------------------------------------------

    public function test_the_audit_screen_lists_the_latest_first_with_who_what_and_the_values(): void
    {
        $owner = $this->staff('owner');
        $this->actingAs($owner);
        $customer = $this->customer(['name_en' => 'Kabul Traders']);
        Audit::record('customer.updated', $customer, ['name_en' => 'Old'], ['name_en' => 'New'], reason: 'Typo');
        Audit::record('invoice.created', null, null, ['number' => 'SUB-2026-0001'], $customer->id);

        $this->get('/audit')->assertInertia(fn ($page) => $page
            ->has('logs.data', 2)
            ->where('logs.data.0.action', 'invoice.created')   // the latest first
            ->where('logs.data.0.customer', 'Kabul Traders')
            ->where('logs.data.1.staff', $owner->name)
            ->where('logs.data.1.before.name_en', 'Old')
            ->where('logs.data.1.after.name_en', 'New')
            ->where('logs.data.1.reason', 'Typo')
            ->where('groups', ['customer', 'invoice']));
    }

    public function test_the_audit_screen_filters(): void
    {
        $this->actingAs($this->staff('owner'));
        $kabul = $this->customer(['name_en' => 'Kabul Traders']);
        $herat = $this->customer(['name_en' => 'Herat Pharmacy']);
        Audit::record('customer.created', $kabul);
        Audit::record('invoice.created', $herat);
        Audit::record('payment.recorded', $herat);

        $this->get('/audit?action=invoice')->assertInertia(fn ($page) => $page->has('logs.data', 1)->where('logs.data.0.action', 'invoice.created'));
        $this->get('/audit?customer=herat')->assertInertia(fn ($page) => $page->has('logs.data', 2));
        $this->get('/audit?customer=kabul')->assertInertia(fn ($page) => $page->has('logs.data', 1));
        $this->get('/audit?from='.now()->addDay()->toDateString())->assertInertia(fn ($page) => $page->has('logs.data', 0));
        $this->get('/audit?to='.now()->toDateString())->assertInertia(fn ($page) => $page->has('logs.data', 3));
    }

    public function test_the_screens_and_the_menu_know_the_new_abilities(): void
    {
        $this->actingAs($this->staff('owner'))->get('/staff')->assertInertia(fn ($page) => $page
            ->where('auth.user.abilities', fn ($a) => collect($a)->contains('staff.manage') && collect($a)->contains('audit.view')));
        $this->assertContains('staff.manage', Roles::ABILITIES);
    }
}
