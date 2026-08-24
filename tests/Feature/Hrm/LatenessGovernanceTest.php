<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\AssignSchedule;
use App\Modules\Hrm\Actions\ProcessAttendance;
use App\Modules\Hrm\Actions\RecordPunch;
use App\Modules\Hrm\database\seeders\AttendancePolicySeeder;
use App\Modules\Hrm\Enums\PunchDirection;
use App\Modules\Hrm\Enums\PunchSource;
use App\Modules\Hrm\Enums\ScheduleScope;
use App\Modules\Hrm\Models\AttendancePolicy;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\HolidayCalendar;
use App\Modules\Hrm\Models\LatenessReviewAlert;
use App\Modules\Hrm\Models\Shift;
use App\Modules\Hrm\Models\ShowCauseNotice;
use App\Modules\Hrm\Notifications\LatenessReviewNotification;
use App\Modules\Hrm\Notifications\ShowCauseIssuedNotification;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Lateness governance (Joy, 24 Aug 2026): HR is told when someone uses their
 * LAST free late of the month, reviews, and can put a show cause notice on
 * formal record that the employee reads and prints.
 *
 * The rule worth pinning hardest: the alert threshold is DERIVED from the
 * ladder, so revising the policy moves the alert with it and no code changes.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Gov Co', 'slug' => 'gov-co']);
    app(BusinessContext::class)->set($this->business->id);

    Permission::firstOrCreate(['name' => 'hrm.attendance.manage', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo('hrm.attendance.manage');
    Role::firstOrCreate(['name' => 'Staff', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->hr = User::factory()->create();
    $this->hr->businesses()->attach($this->business->id);
    $this->hr->assignRole('Owner');

    $this->staffUser = User::factory()->create();
    $this->staffUser->businesses()->attach($this->business->id);
    $this->staffUser->assignRole('Staff');

    $this->office = Office::create(['name' => 'Head Office', 'is_active' => true]);
    $this->shift = Shift::create([
        'name' => 'General', 'start_time' => '09:00', 'end_time' => '18:00',
        'grace_minutes' => 15, 'break_minutes' => 60, 'is_overnight' => false,
    ]);
    $calendar = HolidayCalendar::create([
        'name' => 'Bangladesh', 'year' => 2026, 'weekly_off_days' => [5], 'is_default' => true,
    ]);
    // Default ladder: within_grace tiers none@1 / warning@4 / deduction@5, so
    // the last FREE occurrence is the 3rd - the alert must fire there.
    AttendancePolicy::create([
        'name' => 'Standard', 'version' => 1,
        'config' => AttendancePolicySeeder::defaultConfig(),
        'effective_from' => '2026-01-01',
    ]);
    app(AssignSchedule::class)->handle(ScheduleScope::Business, null, [
        'schedule_type' => 'fixed',
        'shift_id' => $this->shift->id,
        'holiday_calendar_id' => $calendar->id,
        'effective_from' => '2025-01-01',
    ]);

    $this->employee = Employee::create([
        'employee_code' => 'BDG-000001',
        'user_id' => $this->staffUser->id,
        'first_name' => 'Karim', 'last_name' => 'Ahmed',
        'employment_type' => 'permanent', 'status' => 'active',
    ]);
    EmployeeAssignment::create([
        'employee_id' => $this->employee->id,
        'office_id' => $this->office->id,
        'is_primary' => true, 'effective_from' => '2025-01-01',
    ]);

    // A slightly-late day: in at 09:0X (within_grace band), out at 18:30.
    $this->lateDay = function (string $date, string $in = '09:05:00') {
        app(RecordPunch::class)->handle($this->employee, "$date $in", PunchSource::Manual, [
            'direction' => PunchDirection::In, 'note' => 'test punch',
        ]);
        app(RecordPunch::class)->handle($this->employee, "$date 18:30:00", PunchSource::Manual, [
            'direction' => PunchDirection::Out, 'note' => 'test punch',
        ]);

        return app(ProcessAttendance::class)->forDay($this->employee, $date, force: true);
    };
});

function govHr()
{
    return test()->actingAs(test()->hr)->withSession(['current_business_id' => test()->business->id]);
}

function govStaff()
{
    return test()->actingAs(test()->staffUser)->withSession(['current_business_id' => test()->business->id]);
}

// --- The last-free-occurrence alert ------------------------------------------

it('alerts HR exactly at the last free occurrence, derived from the ladder', function () {
    Notification::fake();

    // Mon 3, Tue 4 Aug: occurrences 1 and 2 - free, no bell.
    ($this->lateDay)('2026-08-03');
    ($this->lateDay)('2026-08-04');
    expect(LatenessReviewAlert::count())->toBe(0);

    // Wed 5 Aug: occurrence 3 = last free before warning@4. The bell rings.
    ($this->lateDay)('2026-08-05');

    expect(LatenessReviewAlert::count())->toBe(1);
    $alert = LatenessReviewAlert::first();
    expect($alert->employee_id)->toBe($this->employee->id)
        ->and($alert->grace_window_key)->toBe('within_grace')
        ->and($alert->occurrence_number)->toBe(3)
        ->and($alert->month)->toBe(8);

    Notification::assertSentTo($this->hr, LatenessReviewNotification::class);
    // The employee is not the audience for HR's review bell.
    Notification::assertNotSentTo($this->staffUser, LatenessReviewNotification::class);

    // Thu 6 Aug: occurrence 4 (the warning itself) - the bell already rang.
    ($this->lateDay)('2026-08-06');
    expect(LatenessReviewAlert::count())->toBe(1);
});

it('moves the alert when the ladder moves - nothing is hardcoded', function () {
    Notification::fake();

    // Same window, but the ladder now bites at the 2nd occurrence, so the
    // last free one is the 1st.
    $config = AttendancePolicySeeder::defaultConfig();
    $config['grace_windows'][0]['tiers'] = [
        ['from_occurrence' => 1, 'consequence' => 'none'],
        ['from_occurrence' => 2, 'consequence' => 'deduction'],
    ];
    AttendancePolicy::first()->update(['config' => $config]);

    ($this->lateDay)('2026-08-03');

    expect(LatenessReviewAlert::count())->toBe(1)
        ->and(LatenessReviewAlert::first()->occurrence_number)->toBe(1);
});

it('never alerts for a window whose ladder carries no consequence at all', function () {
    Notification::fake();

    $config = AttendancePolicySeeder::defaultConfig();
    $config['grace_windows'][0]['tiers'] = [
        ['from_occurrence' => 1, 'consequence' => 'none'],
    ];
    AttendancePolicy::first()->update(['config' => $config]);

    ($this->lateDay)('2026-08-03');
    ($this->lateDay)('2026-08-04');
    ($this->lateDay)('2026-08-05');

    expect(LatenessReviewAlert::count())->toBe(0);
    Notification::assertNothingSent();
});

it('rings the bell once per month no matter how often the days rebuild', function () {
    Notification::fake();

    ($this->lateDay)('2026-08-03');
    ($this->lateDay)('2026-08-04');
    ($this->lateDay)('2026-08-05');

    // A full force-rebuild of the window - the same day recomputes and lands
    // on the same occurrence, and the durable once-key keeps quiet.
    foreach (['2026-08-03', '2026-08-04', '2026-08-05', '2026-08-06'] as $date) {
        app(ProcessAttendance::class)->forDay($this->employee, $date, force: true);
    }

    expect(LatenessReviewAlert::count())->toBe(1);
    Notification::assertSentToTimes($this->hr, LatenessReviewNotification::class, 1);
});

// --- Show cause notices -------------------------------------------------------

it('issues a notice on record and rings the employee bell', function () {
    Notification::fake();

    govHr()->post(route('hrm.notices.store'), [
        'employee_id' => $this->employee->id,
        'subject' => 'Show cause notice for repeated late arrival',
        'body' => 'Please explain within 3 working days.',
        'context' => [
            'window_label' => 'Up to 15 minutes late',
            'occurrence' => 3,
            'month' => 'August 2026',
            'dates' => ['2026-08-05'],
        ],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $notice = ShowCauseNotice::first();
    expect($notice)->not->toBeNull()
        ->and($notice->employee_id)->toBe($this->employee->id)
        ->and($notice->issued_by)->toBe($this->hr->id)
        ->and($notice->context['occurrence'])->toBe(3);

    Notification::assertSentTo($this->staffUser, ShowCauseIssuedNotification::class);
});

it('still records the notice when the employee has no login', function () {
    Notification::fake();

    $this->employee->update(['user_id' => null]);

    govHr()->post(route('hrm.notices.store'), [
        'employee_id' => $this->employee->id,
        'subject' => 'Show cause',
        'body' => 'On record regardless.',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(ShowCauseNotice::count())->toBe(1);
    Notification::assertNothingSent();
});

it('refuses the notices screen and issuing to somebody without the permission', function () {
    govStaff()->get(route('hrm.notices.index'))->assertForbidden();
    govStaff()->post(route('hrm.notices.store'), [
        'employee_id' => $this->employee->id,
        'subject' => 'x', 'body' => 'y',
    ])->assertForbidden();
});

it('shows an employee their own notices and nobody else theirs', function () {
    $notice = ShowCauseNotice::create([
        'employee_id' => $this->employee->id,
        'issued_by' => $this->hr->id,
        'subject' => 'Show cause', 'body' => 'Explain.', 'issued_at' => now(),
    ]);

    govStaff()->get(route('hrm.my-notices.index'))
        ->assertOk()
        ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->component('hrm/my-notices/index')
            ->has('notices', 1));

    govStaff()->get(route('hrm.my-notices.show', $notice))
        ->assertOk()
        ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->component('hrm/notices/show')
            ->where('notice.subject', 'Show cause'));

    // A second employee's login sees a 404, not somebody else's letter.
    $other = User::factory()->create();
    $other->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $other->assignRole('Staff');
    Employee::create([
        'employee_code' => 'BDG-000002', 'user_id' => $other->id,
        'first_name' => 'Rahim', 'last_name' => 'Mia',
        'employment_type' => 'permanent', 'status' => 'active',
    ]);

    test()->actingAs($other)->withSession(['current_business_id' => test()->business->id])
        ->get(route('hrm.my-notices.show', $notice))->assertNotFound();
});
