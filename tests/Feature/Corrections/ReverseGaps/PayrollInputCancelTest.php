<?php

use App\Modules\Hrm\Actions\ApplyOvertimeAssignment;
use App\Modules\Hrm\Actions\ApplySalaryRevision;
use App\Modules\Hrm\Actions\CancelPayrollInput;
use App\Modules\Hrm\Actions\SaveSalaryStructure;
use App\Modules\Hrm\Corrections\PayrollInputCancelPolicy;
use App\Modules\Hrm\database\seeders\LeaveTypeSeeder;
use App\Modules\Hrm\database\seeders\SalaryComponentSeeder;
use App\Modules\Hrm\Enums\LeaveLedgerEntryType;
use App\Modules\Hrm\Enums\PayrollStatus;
use App\Modules\Hrm\Enums\SalaryCalculationBasis;
use App\Modules\Hrm\Models\BonusAward;
use App\Modules\Hrm\Models\BonusEvent;
use App\Modules\Hrm\Models\LeaveLedgerEntry;
use App\Modules\Hrm\Models\LeaveType;
use App\Modules\Hrm\Models\OvertimeAssignment;
use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Models\SalaryComponent;
use App\Modules\Hrm\Models\SalaryRevision;
use App\Modules\Hrm\Models\SalaryStructure;
use App\Modules\Hrm\Support\LeaveBalance;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\CorrectionWorld;
use Tests\Support\PeopleWorld;

/**
 * ⭐ Initial plan B24 (Joy's Q5 = A, 25 Oct 2026): an approved overtime, bonus
 * award or salary revision is cancelled, with a reason, while its payroll
 * month still takes inputs; a locked, approved or paid month is never touched.
 */
beforeEach(function () {
    $this->w = CorrectionWorld::make();
    $this->p = PeopleWorld::make($this->w);
    $this->policy = app(PayrollInputCancelPolicy::class);

    $this->overtime = function (string $on = '2026-10-26', bool $holiday = false): OvertimeAssignment {
        $overtime = OvertimeAssignment::create([
            'business_id' => $this->w->business->id, 'employee_id' => $this->p->employee->id, 'work_date' => $on,
            'hours' => '8', 'is_holiday_work' => $holiday, 'reason' => 'Stock count night', 'requested_by' => $this->w->financeA->id,
        ]);
        $overtime->submitForApproval($this->w->financeA)->approve($this->w->owner);

        return app(ApplyOvertimeAssignment::class)->handle($overtime->fresh());
    };

    $this->lock = fn (int $year, int $month, PayrollStatus $status = PayrollStatus::Locked) => PayrollRun::create([
        'business_id' => $this->w->business->id, 'payroll_period_id' => $this->p->period($year, $month)->id, 'version' => 1, 'status' => $status,
    ]);
});

afterEach(fn () => Carbon::setTestNow());

it('cancels approved overtime in a month that still takes inputs; payroll leaves it out, and the day can be entered again', function () {
    $overtime = ($this->overtime)();
    expect(OvertimeAssignment::payable()->count())->toBe(1);

    $this->actingAs($this->w->financeB)->post(route('hrm.overtime.cancel', $overtime), ['reason_text' => 'x'])->assertSessionHasErrors('reason_text');
    $this->actingAs($this->w->financeB)->post(route('hrm.overtime.cancel', $overtime), ['reason_text' => 'Wrong employee picked'])->assertSessionHasNoErrors();

    $overtime->refresh();
    expect($overtime->isCancelled())->toBeTrue()
        ->and($overtime->cancel_reason)->toBe('Wrong employee picked')
        ->and(OvertimeAssignment::payable()->count())->toBe(0);

    // A double click finds it cancelled.
    $this->actingAs($this->w->financeB)->post(route('hrm.overtime.cancel', $overtime), ['reason_text' => 'Wrong employee picked'])->assertSessionHasErrors('reason_text');

    // The same day can be entered again, right.
    expect(fn () => ($this->overtime)())->not->toThrow(Exception::class);
});

it('never touches a locked month: the cancel is refused in words', function () {
    ($this->lock)(2026, 10);
    $overtime = ($this->overtime)('2026-10-10');

    $availability = $this->policy->availability($overtime, $this->w->financeB);
    expect($availability->allowed)->toBeFalse()
        ->and($availability->blockers[0]->message)->toContain('payroll is already locked');

    $this->actingAs($this->w->financeB)->post(route('hrm.overtime.cancel', $overtime), ['reason_text' => 'Wrong employee picked'])->assertSessionHasErrors('reason_text');
    expect($overtime->fresh()->isCancelled())->toBeFalse();
});

it('takes back the compensatory leave of holiday work, and refuses once it was taken', function () {
    (new LeaveTypeSeeder)->run();
    $type = LeaveType::query()->where('code', LeaveType::COMPENSATORY)->sole();
    $balance = fn () => app(LeaveBalance::class)->for($this->p->employee->id, $type->id, 2026);

    $kept = ($this->overtime)('2026-10-25', holiday: true);
    expect($balance())->toBeGreaterThan(0.0);

    app(CancelPayrollInput::class)->handle($kept, $this->w->financeB, $this->p->reason('Was not a holiday'));
    expect($balance())->toBe(0.0);

    $taken = ($this->overtime)('2026-10-27', holiday: true);
    LeaveLedgerEntry::create(['business_id' => $this->w->business->id, 'employee_id' => $this->p->employee->id, 'leave_type_id' => $type->id, 'year' => 2026,
        'entry_type' => LeaveLedgerEntryType::Taken, 'days' => -(float) $taken->compensatory_days, 'occurred_on' => '2026-10-30']);

    expect($this->policy->availability($taken, $this->w->financeB)->blockers[0]->message)->toContain('was already taken');
});

it('cancels an approved bonus award, and only the payroll manager may', function () {
    $event = BonusEvent::create(['business_id' => $this->w->business->id, 'name' => 'Puja bonus', 'calculation_basis' => SalaryCalculationBasis::Fixed,
        'value' => '3000', 'payroll_period_id' => $this->p->period(2026, 11)->id, 'created_by' => $this->w->owner->id]);
    $award = BonusAward::create(['business_id' => $this->w->business->id, 'bonus_event_id' => $event->id, 'employee_id' => $this->p->employee->id, 'amount' => '3000', 'basis_note' => 'Fixed']);
    $award->submitForApproval($this->w->financeA)->approve($this->w->owner);
    expect(BonusAward::approved()->count())->toBe(1);

    $this->actingAs($this->w->manager)->post(route('hrm.bonus.awards.cancel', $award), ['reason_text' => 'Not eligible this year'])->assertForbidden();
    $this->actingAs($this->w->financeB)->post(route('hrm.bonus.awards.cancel', $award), ['reason_text' => 'Not eligible this year'])->assertSessionHasNoErrors();

    expect($award->fresh()->isCancelled())->toBeTrue()
        ->and(BonusAward::approved()->count())->toBe(0);
});

it('cancels an applied salary revision: the salary before it comes back', function () {
    Permission::findOrCreate('hrm.salary.manage', 'web');
    Role::where('name', 'Owner')->where('business_id', $this->w->business->id)->firstOrFail()->givePermissionTo('hrm.salary.manage');
    (new SalaryComponentSeeder)->run();
    $basic = SalaryComponent::where('code', 'basic')->sole();
    $employee = $this->p->employee;

    $before = app(SaveSalaryStructure::class)->handle($employee, '2026-01-01', [['salary_component_id' => $basic->id, 'value' => 20000]], 'Opening');
    $revision = SalaryRevision::create(['business_id' => $this->w->business->id, 'employee_id' => $employee->id, 'effective_from' => '2026-10-24',
        'proposed_lines' => [['salary_component_id' => $basic->id, 'value' => 25000]], 'reason' => 'Annual increment', 'requested_by' => $this->w->financeA->id]);
    $revision = app(ApplySalaryRevision::class)->handle($revision);
    expect(SalaryStructure::activeFor($employee->id, '2026-11-01')->id)->not->toBe($before->id);

    $this->actingAs($this->w->owner)->post(route('hrm.revisions.cancel', $revision), ['reason_text' => 'Increment not agreed yet'])->assertSessionHasNoErrors();

    expect($revision->fresh()->isCancelled())->toBeTrue()
        ->and(SalaryStructure::activeFor($employee->id, '2026-11-01')->id)->toBe($before->id)
        ->and($before->fresh()->effective_to)->toBeNull();
});
