<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Jobs\SendPayslipEmail;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Models\Payslip;
use App\Modules\Hrm\Support\PayslipAddress;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Emailing payslips (DIS-04), finished.
 *
 * ⚠⚠ The sending itself was already built and careful: queued one job per
 * payslip, a password-protected PDF, the outcome written back onto the row. The
 * hole was that NOTHING READ THAT OUTCOME. HR pressed the button, was told "8
 * queued", and had no way to learn that the ninth person has no address or that
 * one send failed. An outcome nobody can see is not a record, it is a log.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Mail Co', 'slug' => 'mail-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Permission::firstOrCreate(['name' => 'hrm.payroll.manage', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo('hrm.payroll.manage');

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->owner->assignRole('Owner');

    $period = PayrollPeriod::create([
        'business_id' => $this->business->id,
        'year' => 2026, 'month' => 8,
        'start_date' => '2026-07-24', 'end_date' => '2026-08-23', 'divisor_days' => 30,
    ]);

    $this->run = PayrollRun::create([
        'business_id' => $this->business->id,
        'payroll_period_id' => $period->id,
        'version' => 1, 'status' => 'disbursed', 'employee_count' => 2,
    ]);

    $this->makeEmployee = function (string $code, ?string $personalEmail, ?string $loginEmail = null) {
        $employee = Employee::create([
            'employee_code' => $code,
            'first_name' => $code, 'last_name' => 'Person',
            'employment_type' => 'permanent', 'status' => 'active',
            'personal_email' => $personalEmail,
        ]);

        EmployeeAssignment::create([
            'employee_id' => $employee->id, 'is_primary' => true, 'effective_from' => '2025-01-01',
        ]);

        if ($loginEmail !== null) {
            $user = User::factory()->create(['email' => $loginEmail]);
            $user->businesses()->attach($this->business->id);
            $employee->update(['user_id' => $user->id]);
        }

        $payslip = Payslip::create([
            'business_id' => $this->business->id,
            'payroll_run_id' => $this->run->id,
            'employee_id' => $employee->id,
            'gross_salary' => 20000, 'total_earnings' => 20000,
            'total_deductions' => 0, 'net' => 20000, 'is_blocked' => false,
        ]);

        return [$employee, $payslip];
    };

    $this->payslipOf = fn (Employee $employee) => Payslip::where('employee_id', $employee->id)->sole();

    $this->asOwner = fn () => $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id]);
});

it('prefers the personal address and falls back to the login', function () {
    // ⚠ A work login may be shared, or disabled the day somebody leaves, which
    // is exactly when a final payslip still has to reach them.
    [, $own] = ($this->makeEmployee)('BDG-1', 'karim@example.com', 'karim@work.test');
    [, $loginOnly] = ($this->makeEmployee)('BDG-2', null, 'rahim@work.test');
    [, $neither] = ($this->makeEmployee)('BDG-3', null);

    expect(PayslipAddress::for($own->fresh()))->toBe('karim@example.com')
        ->and(PayslipAddress::for($loginOnly->fresh()))->toBe('rahim@work.test')
        ->and(PayslipAddress::for($neither->fresh()))->toBeNull();
});

it('does not queue a job for somebody with nowhere to send, and names them', function () {
    Queue::fake();

    ($this->makeEmployee)('BDG-1', 'karim@example.com');
    ($this->makeEmployee)('BDG-2', null);

    ($this->asOwner)()->post(route('hrm.payroll.email-payslips', $this->run))
        ->assertRedirect()
        ->assertSessionHas('warning', fn ($message) => str_contains($message, '1 payslip(s) queued')
            && str_contains($message, 'BDG-2 Person'));

    // ⚠⚠ One job, not two. Queueing a job whose only possible outcome is "no
    // address on record" buries the fact HR needs inside a failure.
    Queue::assertPushed(SendPayslipEmail::class, 1);
});

it('reports plain success when everybody can be reached', function () {
    Queue::fake();

    ($this->makeEmployee)('BDG-1', 'karim@example.com');
    ($this->makeEmployee)('BDG-2', 'rahim@example.com');

    ($this->asOwner)()->post(route('hrm.payroll.email-payslips', $this->run))
        ->assertSessionHas('success', fn ($message) => str_contains($message, '2 payslip(s) queued'))
        ->assertSessionMissing('warning');

    Queue::assertPushed(SendPayslipEmail::class, 2);
});

it('shows HR who was emailed, who failed, and who cannot be reached', function () {
    [, $sent] = ($this->makeEmployee)('BDG-1', 'karim@example.com');
    [, $failed] = ($this->makeEmployee)('BDG-2', 'rahim@example.com');
    ($this->makeEmployee)('BDG-3', null);

    $sent->update(['emailed_at' => now(), 'emailed_to' => 'karim@example.com']);
    $failed->update(['email_error' => 'Mailbox unavailable']);

    ($this->asOwner)()->get(route('hrm.payroll.disbursement', $this->run))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('email_summary.sendable', 2)
            ->where('email_summary.sent', 1)
            ->where('email_summary.failed', 1)
            ->where('email_summary.unreachable', ['BDG-3 Person'])
            ->where('payslips', function ($rows) {
                $byCode = collect($rows)->keyBy('employee_code');

                return $byCode['BDG-1']['email']['sent_at'] !== null
                    && $byCode['BDG-2']['email']['error'] === 'Mailbox unavailable'
                    && $byCode['BDG-3']['email']['reachable'] === false;
            })
        );
});

it('never emails a blocked payslip', function () {
    Queue::fake();

    [, $blocked] = ($this->makeEmployee)('BDG-1', 'karim@example.com');
    $blocked->update(['is_blocked' => true]);

    ($this->asOwner)()->post(route('hrm.payroll.email-payslips', $this->run));

    Queue::assertNothingPushed();
});
