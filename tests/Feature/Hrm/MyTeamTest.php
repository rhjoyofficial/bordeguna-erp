<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\LeaveRequest;
use App\Modules\Hrm\Models\LeaveType;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Module 5, Slice 1: the manager's team view.
 *
 * Built ON the Module-1 hierarchy (employee_assignments.supervisor_id):
 * whoever supervises anyone sees their direct reports, code-ordered, with
 * today's attendance and any pending leave. READ-ONLY on purpose - approving
 * stays with the Approvals inbox, the single approval surface, because a
 * second approve button is how the ninth dead engine happened.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Team Co', 'slug' => 'team-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    Carbon::setTestNow('2026-09-03 11:00:00');

    $makeEmployee = function (string $code, string $first, ?int $supervisorId = null) {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        $employee = Employee::create([
            'business_id' => $this->business->id,
            'user_id' => $user->id,
            'employee_code' => $code,
            'first_name' => $first,
            'last_name' => 'Islam',
            'join_date' => '2026-07-01',
            'status' => 'active',
            'employment_type' => 'permanent',
        ]);
        EmployeeAssignment::create([
            'business_id' => $this->business->id,
            'employee_id' => $employee->id,
            'supervisor_id' => $supervisorId,
            'effective_from' => '2026-07-01',
        ]);

        return $employee;
    };

    $this->boss = $makeEmployee('BDG-000001', 'Boss');
    // Deliberately out of code order to prove the ordering.
    $this->second = $makeEmployee('BDG-000003', 'Second', $this->boss->id);
    $this->first = $makeEmployee('BDG-000002', 'First', $this->boss->id);
    $this->outsider = $makeEmployee('BDG-000004', 'Outsider');
});

afterEach(fn () => Carbon::setTestNow());

it('shows the supervisor their reports, code-ordered, with today and pending leave', function () {
    DailyAttendance::create([
        'business_id' => $this->business->id,
        'employee_id' => $this->first->id,
        'attendance_date' => '2026-09-03',
        'status' => 'present',
    ]);

    $type = LeaveType::create([
        'business_id' => $this->business->id, 'name' => 'Casual', 'code' => 'CL',
        'is_paid' => true, 'annual_quota' => 10, 'is_active' => true,
    ]);
    LeaveRequest::create([
        'business_id' => $this->business->id,
        'employee_id' => $this->second->id,
        'leave_type_id' => $type->id,
        'start_date' => '2026-09-07', 'end_date' => '2026-09-08',
        'day_portion' => 'full_day', 'days' => 2, 'unpaid_days' => 0,
        'reason' => 'Family visit',
        'requested_by' => $this->second->user_id,
    ]);

    $this->actingAs($this->boss->user)->withSession(['current_business_id' => $this->business->id])
        ->get(route('hrm.my-team'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('hrm/my-team')
            ->has('team', 2)
            // Code order, whatever order they were created in.
            ->where('team.0.employee_code', 'BDG-000002')
            ->where('team.1.employee_code', 'BDG-000003')
            ->where('team.0.today', 'present')
            ->where('team.1.today', null)
            ->where('team.1.pending_leave.0.days', fn ($value) => (float) $value === 2.0));
});

it('shows an empty state to somebody who supervises no one', function () {
    $this->actingAs($this->outsider->user)->withSession(['current_business_id' => $this->business->id])
        ->get(route('hrm.my-team'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('hrm/my-team')
            ->has('team', 0));
});
