<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Services\Audit;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use LogicException;
use Tests\Support\MakesData;
use Tests\TestCase;

class AuditAndRolesTest extends TestCase
{
    use MakesData;
    use RefreshDatabase;

    public function test_the_audit_trail_records_who_what_and_the_values_before_and_after(): void
    {
        $staff = $this->staff('finance');
        $this->actingAs($staff);
        $customer = $this->customer();

        Audit::record('customer.renamed', $customer, ['name_en' => 'Old'], ['name_en' => 'New'], reason: 'Typo');

        $row = AuditLog::query()->firstOrFail();
        $this->assertSame($staff->id, $row->staff_id);
        $this->assertSame('Customer', $row->subject_type);
        $this->assertSame($customer->id, $row->customer_id);
        $this->assertSame(['name_en' => 'Old'], $row->before);
        $this->assertSame(['name_en' => 'New'], $row->after);
        $this->assertSame('Typo', $row->reason);
    }

    public function test_an_audit_row_can_be_neither_changed_nor_deleted(): void
    {
        $this->actingAs($this->staff());
        $row = Audit::record('x.y');

        try {
            $row->update(['action' => 'something else']);
            $this->fail('Updating should be refused.');
        } catch (LogicException) {
        }

        try {
            $row->delete();
            $this->fail('Deleting should be refused.');
        } catch (LogicException) {
        }

        $this->assertSame('x.y', $row->fresh()->action);
    }

    public function test_every_ability_belongs_to_the_owner_and_no_role_has_one_that_does_not_exist(): void
    {
        $this->assertEqualsCanonicalizing(Roles::ABILITIES, Roles::map()['owner']);

        foreach (Roles::map() as $role => $abilities) {
            $this->assertSame([], array_diff($abilities, Roles::ABILITIES), "{$role} lists an unknown ability");
        }
    }

    public function test_each_role_can_do_what_the_plan_gives_it_and_nothing_more(): void
    {
        $can = fn (string $role, string $ability) => Gate::forUser($this->staff($role))->allows($ability);

        // Sales sells: customers, trials and packages, but takes no money and blocks no one.
        $this->assertTrue($can('sales', 'customers.manage'));
        $this->assertTrue($can('sales', 'subscriptions.assign'));
        $this->assertFalse($can('sales', 'billing.manage'));
        $this->assertFalse($can('sales', 'subscriptions.block'));

        // Finance bills and collects, and may block over a debt, but does not change packages.
        $this->assertTrue($can('finance', 'billing.manage'));
        $this->assertTrue($can('finance', 'subscriptions.block'));
        $this->assertFalse($can('finance', 'subscriptions.assign'));
        $this->assertFalse($can('finance', 'support.impersonate'));

        // Support looks and helps, and nothing to do with money or packages.
        $this->assertTrue($can('support', 'support.impersonate'));
        $this->assertTrue($can('support', 'customers.view'));
        $this->assertFalse($can('support', 'billing.view'));
        $this->assertFalse($can('support', 'customers.manage'));

        // A viewer only reads.
        $this->assertTrue($can('viewer', 'reports.view'));
        foreach (['customers.manage', 'packages.manage', 'subscriptions.assign', 'billing.manage', 'support.impersonate', 'staff.manage', 'audit.view'] as $ability) {
            $this->assertFalse($can('viewer', $ability), "viewer must not have {$ability}");
        }

        // Only the owner runs staff and reads the audit trail.
        $this->assertTrue($can('owner', 'staff.manage'));
        $this->assertTrue($can('owner', 'audit.view'));
        foreach (['sales', 'finance', 'support'] as $role) {
            $this->assertFalse($can($role, 'staff.manage'), "{$role} must not manage staff");
            $this->assertFalse($can($role, 'audit.view'), "{$role} must not read the audit trail");
        }
    }

    public function test_a_staff_member_who_is_switched_off_can_do_nothing_whatever_the_role(): void
    {
        $owner = $this->staff('owner', active: false);

        foreach (Roles::ABILITIES as $ability) {
            $this->assertFalse(Gate::forUser($owner)->allows($ability), $ability);
        }
    }

    public function test_an_unknown_role_has_no_abilities(): void
    {
        $this->assertFalse(Roles::allows('intern', 'customers.view'));
        $this->assertFalse(Roles::allows(null, 'customers.view'));
    }
}
