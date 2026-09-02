<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Module 1, Slice 3 (master plan §9, §31): MY WORK - one queue holding
 * everything that waits on THIS person, so the day starts on one page instead
 * of eight screens. Approvals they can act on, follow-ups assigned to them,
 * their own to-dos, the requests THEY are waiting on others for - and for the
 * roles that hold them, the attendance rows needing review and the standing
 * costs falling due.
 *
 * The law is the search box's law: a section never shows what its own screen
 * would refuse, and someone with no permissions still gets a working page of
 * their own items rather than a 403.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Work Co', 'slug' => 'work-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['hrm.attendance.manage', 'purchasing.expense.record', 'crm.leads.view'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    Role::firstOrCreate(['name' => 'WorkOwner', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo(['hrm.attendance.manage', 'purchasing.expense.record', 'crm.leads.view']);
    Role::firstOrCreate(['name' => 'WorkNobody', 'business_id' => $this->business->id, 'guard_name' => 'web']);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole('WorkOwner');

    $this->nobody = User::factory()->create();
    $this->nobody->businesses()->attach($this->business->id);
    $this->nobody->assignRole('WorkNobody');
});

function visitWork(User $user)
{
    return test()->actingAs($user)
        ->withSession(['current_business_id' => test()->business->id])
        ->get(route('work'));
}

it('opens for a login with no permissions at all, holding only their own items', function () {
    visitWork($this->nobody)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('my-work')
            ->where('todos.count', 0)
            ->where('my_requests.count', 0)
            ->where('approvals.count', 0)
            // The gated sections are ABSENT, not empty - absence is what says
            // "not your job", the same way the sidebar hides the screens.
            ->missing('attendance')
            ->missing('recurring'));
});

it('shows my own open to-dos, soonest due first, and nobody else\'s', function () {
    DB::table('personal_todos')->insert([
        ['business_id' => $this->business->id, 'user_id' => $this->nobody->id, 'title' => 'Later thing', 'due_on' => '2026-09-20', 'created_at' => now(), 'updated_at' => now()],
        ['business_id' => $this->business->id, 'user_id' => $this->nobody->id, 'title' => 'First thing', 'due_on' => '2026-09-03', 'created_at' => now(), 'updated_at' => now()],
        ['business_id' => $this->business->id, 'user_id' => $this->owner->id, 'title' => 'Not yours', 'due_on' => '2026-09-04', 'created_at' => now(), 'updated_at' => now()],
    ]);
    DB::table('personal_todos')->insert([
        'business_id' => $this->business->id, 'user_id' => $this->nobody->id, 'title' => 'Done thing',
        'due_on' => '2026-09-01', 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    visitWork($this->nobody)
        ->assertInertia(fn (Assert $page) => $page
            ->where('todos.count', 2)
            ->where('todos.items.0.title', 'First thing')
            ->where('todos.items.1.title', 'Later thing'));
});

it('shows the follow-ups assigned to me that are due or overdue', function () {
    DB::table('crm_tasks')->insert([
        ['business_id' => $this->business->id, 'subject_type' => 'lead', 'subject_id' => 1, 'title' => 'Call Karim', 'due_at' => now()->subDay(), 'assigned_to_user_id' => $this->owner->id, 'status' => 'open', 'created_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now()],
        ['business_id' => $this->business->id, 'subject_type' => 'lead', 'subject_id' => 2, 'title' => 'Next week', 'due_at' => now()->addDays(9), 'assigned_to_user_id' => $this->owner->id, 'status' => 'open', 'created_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now()],
        ['business_id' => $this->business->id, 'subject_type' => 'lead', 'subject_id' => 3, 'title' => 'Someone else', 'due_at' => now()->subDay(), 'assigned_to_user_id' => $this->nobody->id, 'status' => 'open', 'created_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now()],
    ]);

    visitWork($this->owner)
        ->assertInertia(fn (Assert $page) => $page
            ->where('followups.count', 1)
            ->where('followups.items.0.title', 'Call Karim'));
});

it('shows the requests I am waiting on others for', function () {
    $workflow = DB::table('approval_workflows')->insertGetId([
        'business_id' => $this->business->id,
        'key' => 'work-test', 'name' => 'Work test',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('approval_requests')->insert([
        'business_id' => $this->business->id,
        'workflow_id' => $workflow,
        'current_stage' => 1,
        'approvable_type' => 'App\\Modules\\Hrm\\Models\\Movement',
        'approvable_id' => 999,
        'status' => 'pending',
        'requested_by' => $this->nobody->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    visitWork($this->nobody)
        ->assertInertia(fn (Assert $page) => $page
            ->where('my_requests.count', 1)
            ->where('my_requests.items.0.type', 'Movement'));

    visitWork($this->owner)
        ->assertInertia(fn (Assert $page) => $page->where('my_requests.count', 0));
});

it('gives the attendance review numbers only to attendance eyes', function () {
    visitWork($this->owner)
        ->assertInertia(fn (Assert $page) => $page
            ->has('attendance')
            ->where('attendance.needs_review', 0)
            ->where('attendance.holds_payroll', 0));

    visitWork($this->nobody)
        ->assertInertia(fn (Assert $page) => $page->missing('attendance'));
});

it('warns the expense recorder about standing costs falling due within a week', function () {
    $schedule = DB::table('recurring_expenses')->insertGetId([
        'business_id' => $this->business->id,
        'name' => 'Test Hosting', 'amount' => 1000, 'amount_varies' => 0,
        'frequency' => 'monthly', 'day_of_month' => 15,
        'expense_account_id' => DB::table('accounts')->insertGetId([
            'code' => 'W999', 'name' => 'Work Hosting', 'type' => 'expense', 'is_postable' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]),
        'money_account_id' => null,
        'start_date' => '2026-01-01', 'status' => 'active', 'lead_days' => 5,
        'created_by' => $this->owner->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('recurring_expense_occurrences')->insert([
        ['business_id' => $this->business->id, 'recurring_expense_id' => $schedule, 'due_on' => now()->addDays(3)->toDateString(), 'period_label' => 'Soon', 'expected_amount' => 1000, 'status' => 'due', 'source_key' => 'w:1', 'created_at' => now(), 'updated_at' => now()],
        ['business_id' => $this->business->id, 'recurring_expense_id' => $schedule, 'due_on' => now()->addDays(40)->toDateString(), 'period_label' => 'Far', 'expected_amount' => 1000, 'status' => 'due', 'source_key' => 'w:2', 'created_at' => now(), 'updated_at' => now()],
    ]);

    visitWork($this->owner)
        ->assertInertia(fn (Assert $page) => $page
            ->where('recurring.count', 1)
            ->where('recurring.items.0.label', 'Test Hosting'));

    visitWork($this->nobody)
        ->assertInertia(fn (Assert $page) => $page->missing('recurring'));
});
