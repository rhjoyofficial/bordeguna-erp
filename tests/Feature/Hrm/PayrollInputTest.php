<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\ApplyOvertimeAssignment;
use App\Modules\Hrm\Actions\AssignSchedule;
use App\Modules\Hrm\Actions\AwardBonus;
use App\Modules\Hrm\Actions\CalculatePayroll;
use App\Modules\Hrm\Actions\CarryForwardNegativeNet;
use App\Modules\Hrm\Actions\RequestOvertime;
use App\Modules\Hrm\Actions\RequestPayrollAdjustment;
use App\Modules\Hrm\Actions\SaveSalaryStructure;
use App\Modules\Hrm\Actions\TransitionPayrollRun;
use App\Modules\Hrm\Actions\YearEndLeaveProcessor;
use App\Modules\Hrm\database\seeders\LeaveTypeSeeder;
use App\Modules\Hrm\database\seeders\PayrollInputWorkflowSeeder;
use App\Modules\Hrm\database\seeders\PayrollWorkflowSeeder;
use App\Modules\Hrm\database\seeders\SalaryComponentSeeder;
use App\Modules\Hrm\Enums\LeaveLedgerEntryType;
use App\Modules\Hrm\Enums\PayrollAdjustmentType;
use App\Modules\Hrm\Enums\PayrollBalanceEntryType;
use App\Modules\Hrm\Enums\SalaryCalculationBasis;
use App\Modules\Hrm\Enums\ScheduleScope;
use App\Modules\Hrm\Models\BonusAward;
use App\Modules\Hrm\Models\BonusEvent;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\HolidayCalendar;
use App\Modules\Hrm\Models\LeaveLedgerEntry;
use App\Modules\Hrm\Models\LeaveType;
use App\Modules\Hrm\Models\OvertimeAssignment;
use App\Modules\Hrm\Models\PayrollAdjustment;
use App\Modules\Hrm\Models\PayrollBalanceEntry;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Models\SalaryComponent;
use App\Modules\Hrm\Models\Shift;
use App\Modules\Hrm\Support\LeaveBalance;
use App\Support\ApprovalInbox;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    Storage::fake('local');

    $this->business = Business::create(['name' => 'Input Co', 'slug' => 'input-co']);
    app(BusinessContext::class)->set($this->business->id);

    foreach (['hrm.salary.manage', 'hrm.payroll.manage', 'hrm.payroll.approve', 'hrm.scheduling.manage'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }
    foreach (['Owner', 'Staff'] as $roleName) {
        Role::firstOrCreate(['name' => $roleName, 'business_id' => $this->business->id, 'guard_name' => 'web']);
    }
    Role::where('business_id', $this->business->id)->where('name', 'Owner')->first()
        ->givePermissionTo(['hrm.salary.manage', 'hrm.payroll.manage', 'hrm.payroll.approve', 'hrm.scheduling.manage']);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    (new SalaryComponentSeeder)->run();
    (new LeaveTypeSeeder)->run();
    (new PayrollWorkflowSeeder)->run();
    (new PayrollInputWorkflowSeeder)->run();

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
    $this->calendar = HolidayCalendar::create([
        'name' => 'Bangladesh', 'year' => 2026, 'weekly_off_days' => [5], 'is_default' => true,
    ]);

    $this->employee = Employee::create([
        'employee_code' => 'BDG-000001',
        'first_name' => 'Karim', 'last_name' => 'Ahmed',
        'employment_type' => 'permanent', 'status' => 'active',
        'bank_account_number' => '1234567890',
    ]);
    EmployeeAssignment::create([
        'employee_id' => $this->employee->id,
        'office_id' => $this->office->id,
        'is_primary' => true,
        'effective_from' => '2025-01-01',
    ]);

    app(AssignSchedule::class)->handle(ScheduleScope::Business, null, [
        'schedule_type' => 'fixed',
        'shift_id' => $this->shift->id,
        'holiday_calendar_id' => $this->calendar->id,
        'effective_from' => '2025-01-01',
    ]);

    // The PAY-13 structure: Basic 25,000 + House Rent 25,000 = Gross 50,000,
    // with ADJ-01 eligibility switched on.
    app(SaveSalaryStructure::class)->handle($this->employee, '2025-01-01', [
        ['salary_component_id' => SalaryComponent::where('code', 'basic')->sole()->id, 'value' => 25000],
        ['salary_component_id' => SalaryComponent::where('code', 'house_rent')->sole()->id, 'value' => 25000],
    ], 'Opening structure', overtimeEligible: true);

    $this->period = PayrollPeriod::forMonth(2026, 7);
});

function inputHr()
{
    return test()->actingAs(test()->hr)->withSession(['current_business_id' => test()->business->id]);
}

/** A working day inside the 24 Jun → 23 Jul period. */
const WORK_DATE = '2026-07-01';

// --- ADJ-01: eligibility -------------------------------------------------------

it('refuses overtime for an employee who is not marked eligible', function () {
    $other = Employee::create([
        'employee_code' => 'BDG-000002', 'first_name' => 'Not', 'last_name' => 'Eligible',
        'employment_type' => 'permanent', 'status' => 'active', 'bank_account_number' => '99',
    ]);
    EmployeeAssignment::create([
        'employee_id' => $other->id, 'office_id' => $this->office->id,
        'is_primary' => true, 'effective_from' => '2025-01-01',
    ]);
    app(SaveSalaryStructure::class)->handle($other, '2025-01-01', [
        ['salary_component_id' => SalaryComponent::where('code', 'basic')->sole()->id, 'value' => 20000],
    ]);

    expect(fn () => app(RequestOvertime::class)->handle($other, WORK_DATE, 2, false, 'Stock take', $this->hr))
        ->toThrow(ValidationException::class);
});

it('judges eligibility as at the date worked, not as at today', function () {
    // Eligibility rides on the effective-dated structure, so a structure that
    // did not grant it cannot be made to grant it after the fact.
    app(SaveSalaryStructure::class)->handle($this->employee, '2026-07-10', [
        ['salary_component_id' => SalaryComponent::where('code', 'basic')->sole()->id, 'value' => 25000],
    ], 'Withdrew eligibility', overtimeEligible: false);

    // Before the change: still allowed.
    $before = app(RequestOvertime::class)->handle($this->employee, '2026-07-01', 2, false, 'Stock take', $this->hr);
    expect($before->id)->not->toBeNull();

    // After it: refused.
    expect(fn () => app(RequestOvertime::class)->handle($this->employee, '2026-07-15', 2, false, 'Stock take', $this->hr))
        ->toThrow(ValidationException::class);
});

// --- ADJ-03: caps ---------------------------------------------------------------

it('refuses more than the ADJ-03 daily maximum', function () {
    expect(fn () => app(RequestOvertime::class)->handle($this->employee, WORK_DATE, 5, false, 'Long night', $this->hr))
        ->toThrow(ValidationException::class);
});

it('refuses a second assignment on the same day, which would slip past the daily cap', function () {
    app(RequestOvertime::class)->handle($this->employee, WORK_DATE, 4, false, 'First', $this->hr);

    expect(fn () => app(RequestOvertime::class)->handle($this->employee, WORK_DATE, 4, false, 'Second', $this->hr))
        ->toThrow(ValidationException::class);
});

it('lets holiday work exceed the daily pay cap, since it is not paid at all', function () {
    $assignment = app(RequestOvertime::class)->handle($this->employee, WORK_DATE, 8, true, 'Eid coverage', $this->hr);

    expect((float) $assignment->hours)->toBe(8.0);
});

// --- ADJ-01 + engine: approved overtime reaches the payslip ----------------------

it('prices approved overtime on the payslip, and nothing before approval', function () {
    $assignment = app(RequestOvertime::class)->handle($this->employee, WORK_DATE, 4, false, 'Delivery push', $this->hr);

    // Unapproved: no overtime line.
    $run = app(CalculatePayroll::class)->handle($this->period);
    expect($run->payslips()->sole()->lines()->where('code', 'overtime')->exists())->toBeFalse();

    inputHr()->post(route('hrm.overtime.approve', $assignment))->assertRedirect();

    $run = app(CalculatePayroll::class)->handle($this->period);
    $line = $run->payslips()->sole()->lines()->where('code', 'overtime')->sole();

    // ADJ-02 via C7a: Basic 25,000 ÷ 30 ÷ 8 = 104.1667/hour × 4 = 416.67.
    expect((float) $line->amount)->toBe(416.67);
    expect((float) $line->quantity)->toBe(4.0);
    expect($line->formula)->toContain('÷ 30 ÷ 8');
});

// --- ADJ-04 / C8b: holiday work becomes leave, not money -------------------------

it('credits compensatory leave for holiday work and pays nothing', function () {
    $assignment = app(RequestOvertime::class)->handle($this->employee, WORK_DATE, 8, true, 'Eid coverage', $this->hr);

    inputHr()->post(route('hrm.overtime.approve', $assignment))->assertRedirect();

    $assignment->refresh();
    expect((float) $assignment->compensatory_days)->toBe(1.0);

    $compensatory = LeaveType::where('code', LeaveType::COMPENSATORY)->sole();
    expect(app(LeaveBalance::class)->for($this->employee->id, $compensatory->id, 2026))->toBe(1.0);

    // And crucially: no payslip line. The two outcomes are alternatives.
    $run = app(CalculatePayroll::class)->handle($this->period);
    expect($run->payslips()->sole()->lines()->where('code', 'overtime')->exists())->toBeFalse();
});

it('posts the compensatory credit exactly once, however often it is applied', function () {
    $assignment = app(RequestOvertime::class)->handle($this->employee, WORK_DATE, 8, true, 'Eid coverage', $this->hr);
    approveIfPending($assignment->approvalRequest, $this->hr);

    app(ApplyOvertimeAssignment::class)->handle($assignment);
    app(ApplyOvertimeAssignment::class)->handle($assignment->fresh());
    app(ApplyOvertimeAssignment::class)->handle($assignment->fresh());

    expect(LeaveLedgerEntry::where('source_key', "overtime_assignment:{$assignment->id}")->count())->toBe(1);
});

it('files the credit as earned rather than granted, so the ledger explains itself', function () {
    $assignment = app(RequestOvertime::class)->handle($this->employee, WORK_DATE, 8, true, 'Eid coverage', $this->hr);
    inputHr()->post(route('hrm.overtime.approve', $assignment));

    $entry = LeaveLedgerEntry::where('source_key', "overtime_assignment:{$assignment->id}")->sole();

    // Filing this under "annual quota granted" would misdescribe both where the
    // day came from and why it cannot be encashed.
    expect($entry->entry_type)->toBe(LeaveLedgerEntryType::CompensatoryCredit);
    expect($entry->entry_type->label())->toBe('Earned by working a holiday');
    expect($entry->entry_type->isCredit())->toBeTrue();
    expect($entry->note)->toContain('ADJ-04');
});

it('lapses an unused compensatory balance at year end rather than carrying it', function () {
    $assignment = app(RequestOvertime::class)->handle($this->employee, WORK_DATE, 8, true, 'Eid coverage', $this->hr);
    inputHr()->post(route('hrm.overtime.approve', $assignment));

    $compensatory = LeaveType::where('code', LeaveType::COMPENSATORY)->sole();
    expect(app(LeaveBalance::class)->for($this->employee->id, $compensatory->id, 2026))->toBe(1.0);

    app(YearEndLeaveProcessor::class)->commit(2026);

    // C8b chose "expires": holiday work buys time off, not a standing liability.
    expect(app(LeaveBalance::class)->for($this->employee->id, $compensatory->id, 2027))->toBe(0.0);
});

it('gives compensatory leave a type that neither carries forward nor encashes', function () {
    // C8b: ADJ-04 declined every pay multiple, so the credit must not become a
    // year-end cash liability by the back door.
    $type = LeaveType::where('code', LeaveType::COMPENSATORY)->sole();

    expect($type->can_carry_forward)->toBeFalse();
    expect($type->can_encash)->toBeFalse();
    expect((float) $type->annual_quota)->toBe(0.0);
    expect($type->deducts_balance)->toBeTrue();
});

// --- ADJ-05: bonus ----------------------------------------------------------------

it('resolves a percentage bonus against the salary in force and stores it', function () {
    $event = BonusEvent::create([
        'name' => 'Eid-ul-Fitr', 'calculation_basis' => SalaryCalculationBasis::PercentOfBasic,
        'value' => 100, 'payroll_period_id' => $this->period->id,
    ]);

    $result = app(AwardBonus::class)->handle($event, Employee::all(), $this->hr);

    expect($result['awarded'])->toBe(1);
    $award = BonusAward::sole();
    // 100% of Basic 25,000.
    expect((float) $award->amount)->toBe(25000.0);
    expect($award->basis_note)->toContain('100% of Basic');
});

it('keeps a bonus at the figure it was awarded at, whatever happens to salary later', function () {
    $event = BonusEvent::create([
        'name' => 'Eid-ul-Fitr', 'calculation_basis' => SalaryCalculationBasis::PercentOfBasic,
        'value' => 100, 'payroll_period_id' => $this->period->id,
    ]);
    app(AwardBonus::class)->handle($event, Employee::all(), $this->hr);

    // A later raise must not restate a past bonus.
    app(SaveSalaryStructure::class)->handle($this->employee, '2026-07-10', [
        ['salary_component_id' => SalaryComponent::where('code', 'basic')->sole()->id, 'value' => 40000],
    ], 'Raise', overtimeEligible: true);

    expect((float) BonusAward::sole()->amount)->toBe(25000.0);
});

it('pays an approved bonus and withholds an unapproved one', function () {
    $event = BonusEvent::create([
        'name' => 'Eid-ul-Fitr', 'calculation_basis' => SalaryCalculationBasis::Fixed,
        'value' => 5000, 'payroll_period_id' => $this->period->id,
    ]);
    app(AwardBonus::class)->handle($event, Employee::all(), $this->hr);

    $run = app(CalculatePayroll::class)->handle($this->period);
    expect($run->payslips()->sole()->lines()->where('code', 'bonus')->exists())->toBeFalse();

    inputHr()->post(route('hrm.bonus.awards.approve', BonusAward::sole()))->assertRedirect();

    $run = app(CalculatePayroll::class)->handle($this->period);
    expect((float) $run->payslips()->sole()->lines()->where('code', 'bonus')->sole()->amount)->toBe(5000.0);
});

it('awards each employee separately so one refusal does not unpick the event', function () {
    $event = BonusEvent::create([
        'name' => 'Eid-ul-Fitr', 'calculation_basis' => SalaryCalculationBasis::Fixed,
        'value' => 5000, 'payroll_period_id' => $this->period->id,
    ]);
    app(AwardBonus::class)->handle($event, Employee::all(), $this->hr);

    expect(BonusAward::sole()->approvalRequest)->not->toBeNull();
});

it('skips an employee already awarded rather than awarding twice', function () {
    $event = BonusEvent::create([
        'name' => 'Eid-ul-Fitr', 'calculation_basis' => SalaryCalculationBasis::Fixed,
        'value' => 5000, 'payroll_period_id' => $this->period->id,
    ]);

    app(AwardBonus::class)->handle($event, Employee::all(), $this->hr);
    $second = app(AwardBonus::class)->handle($event, Employee::all(), $this->hr);

    expect($second['awarded'])->toBe(0);
    expect($second['skipped'])->toBe(1);
    expect(BonusAward::count())->toBe(1);
});

// --- ADJ-08 / ADJ-09: adjustments ---------------------------------------------------

function adjustment(PayrollAdjustmentType $type, float $amount, ?UploadedFile $file = null): PayrollAdjustment
{
    return app(RequestPayrollAdjustment::class)->handle(
        test()->employee,
        $type,
        $amount,
        test()->period,
        'Agreed with the employee',
        $file ?? UploadedFile::fake()->create('evidence.pdf', 10),
        test()->hr,
    );
}

it('requires evidence on every manual adjustment', function () {
    expect(fn () => app(RequestPayrollAdjustment::class)->handle(
        $this->employee, PayrollAdjustmentType::Penalty, 500, $this->period, 'Because', null, $this->hr,
    ))->toThrow(ValidationException::class);
});

it('refuses an arrear entered by hand, since SAL-05 generates them', function () {
    expect(fn () => adjustment(PayrollAdjustmentType::Arrear, 500))
        ->toThrow(ValidationException::class);
});

it('stores the amount positive and lets the type decide the direction', function () {
    $penalty = adjustment(PayrollAdjustmentType::Penalty, 500);

    expect((float) $penalty->amount)->toBe(500.0);
    expect($penalty->type->isEarning())->toBeFalse();
    expect($penalty->attachments()->count())->toBe(1);
});

it('gives every adjustment type its own payslip line rather than netting them', function () {
    adjustment(PayrollAdjustmentType::Addition, 1000);
    $penalty = adjustment(PayrollAdjustmentType::Penalty, 400);
    // Second adjustment needs its own approval.
    foreach (PayrollAdjustment::all() as $a) {
        approveIfPending($a->approvalRequest, $this->hr);
    }

    $run = app(CalculatePayroll::class)->handle($this->period);
    $lines = $run->payslips()->sole()->lines;

    expect($lines->where('code', 'addition')->count())->toBe(1);
    expect($lines->where('code', 'penalty')->count())->toBe(1);
    expect((float) $lines->where('code', 'penalty')->sole()->amount)->toBe(400.0);
    expect($penalty->fresh()->isApproved())->toBeTrue();
});

it('withholds an unapproved adjustment from the payslip', function () {
    adjustment(PayrollAdjustmentType::Addition, 1000);

    $run = app(CalculatePayroll::class)->handle($this->period);

    expect($run->payslips()->sole()->lines()->where('code', 'addition')->exists())->toBeFalse();
});

it('refuses an adjustment pointed at a payroll month that is already disbursed', function () {
    $run = app(CalculatePayroll::class)->handle($this->period);
    $transition = app(TransitionPayrollRun::class);
    $transition->review($run, $this->hr);
    approveIfPending($run->approvalRequest, $this->hr);
    $transition->markApproved($run->fresh(), $this->hr);
    $transition->disburse($run->fresh(), $this->hr);

    expect(fn () => adjustment(PayrollAdjustmentType::Addition, 1000))
        ->toThrow(ValidationException::class);
});

// --- PAY-11 / C8c: the carry-forward balance -------------------------------------------

/** Force a negative net by deducting more than the salary is worth. */
function negativeRun()
{
    $adjustment = adjustment(PayrollAdjustmentType::Recovery, 80000);
    approveIfPending($adjustment->approvalRequest, test()->hr);

    return app(CalculatePayroll::class)->handle(test()->period);
}

it('blocks a negative net rather than paying it', function () {
    $run = negativeRun();
    $payslip = $run->payslips()->sole();

    expect($payslip->is_blocked)->toBeTrue();
    expect((float) $payslip->net)->toBeLessThan(0);
    expect(implode(' ', $payslip->block_reasons))->toContain('Negative net');
});

it('carries the shortfall forward as a ledger entry and squares the month on recalculation', function () {
    $run = negativeRun();
    $payslip = $run->payslips()->sole();
    $shortfall = round(-1 * (float) $payslip->net, 2);

    app(CarryForwardNegativeNet::class)->handle($payslip);

    $entry = PayrollBalanceEntry::sole();
    expect($entry->entry_type)->toBe(PayrollBalanceEntryType::CarriedForward);
    expect((float) $entry->amount)->toBe($shortfall);

    // Recalculate: the credit arrives as an ordinary input and the block clears.
    $recalculated = app(CalculatePayroll::class)->handle($this->period);
    $after = $recalculated->payslips()->sole();

    expect((float) $after->net)->toBe(0.0);
    expect($after->is_blocked)->toBeFalse();
    expect((float) $after->lines()->where('code', 'balance_carry_forward')->sole()->amount)->toBe($shortfall);
});

it('carries a shortfall forward only once, however many times it is pressed', function () {
    $run = negativeRun();
    $payslip = $run->payslips()->sole();

    app(CarryForwardNegativeNet::class)->handle($payslip);
    app(CarryForwardNegativeNet::class)->handle($payslip->fresh());

    expect(PayrollBalanceEntry::count())->toBe(1);
});

it('refuses to carry forward a payslip that is not actually negative', function () {
    $run = app(CalculatePayroll::class)->handle($this->period);

    expect(fn () => app(CarryForwardNegativeNet::class)->handle($run->payslips()->sole()))
        ->toThrow(ValidationException::class);
});

it('recovers the balance in full from the next payroll and closes it on disbursement', function () {
    $run = negativeRun();
    $shortfall = round(-1 * (float) $run->payslips()->sole()->net, 2);
    app(CarryForwardNegativeNet::class)->handle($run->payslips()->sole());
    app(CalculatePayroll::class)->handle($this->period);

    // The next month recovers it in full — C8c's rule, no invented schedule.
    $august = PayrollPeriod::forMonth(2026, 8);
    $next = app(CalculatePayroll::class)->handle($august);
    $recovery = $next->payslips()->sole()->lines()->where('code', 'balance_recovery')->sole();

    expect((float) $recovery->amount)->toBe($shortfall);

    // Nothing is written off until the money actually moves.
    expect(PayrollBalanceEntry::outstandingFor($this->employee->id))->toBe($shortfall);

    $transition = app(TransitionPayrollRun::class);
    $transition->review($next->fresh(), $this->hr);
    approveIfPending($next->fresh()->approvalRequest, $this->hr);
    $transition->markApproved($next->fresh(), $this->hr);
    $transition->disburse($next->fresh(), $this->hr);

    expect(PayrollBalanceEntry::outstandingFor($this->employee->id))->toBe(0.0);
});

// --- Workflow, inbox and access -------------------------------------------------------

it('puts every new payroll input in the shared approval inbox', function () {
    app(RequestOvertime::class)->handle($this->employee, WORK_DATE, 2, false, 'Stock take', $this->hr);
    $event = BonusEvent::create([
        'name' => 'Eid', 'calculation_basis' => SalaryCalculationBasis::Fixed,
        'value' => 5000, 'payroll_period_id' => $this->period->id,
    ]);
    app(AwardBonus::class)->handle($event, Employee::all(), $this->hr);
    adjustment(PayrollAdjustmentType::Addition, 1000);

    $actionable = app(ApprovalInbox::class)->pendingActionableFor($this->hr);

    expect($actionable->pluck('approvable')->map(fn ($a) => class_basename($a))->sort()->values()->all())
        ->toBe(['BonusAward', 'OvertimeAssignment', 'PayrollAdjustment']);
});

it('separates raising an input from approving it', function () {
    // The stage authorises on hrm.payroll.approve, not hrm.payroll.manage, so a
    // preparer holding only manage cannot wave their own adjustment through.
    $preparer = User::factory()->create();
    $preparer->businesses()->attach($this->business->id);
    $role = Role::firstOrCreate(['name' => 'Payroll Officer', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    $role->givePermissionTo('hrm.payroll.manage');
    $preparer->assignRole('Payroll Officer');

    $assignment = app(RequestOvertime::class)->handle($this->employee, WORK_DATE, 2, false, 'Stock take', $this->hr);

    $this->actingAs($preparer)->withSession(['current_business_id' => $this->business->id])
        ->post(route('hrm.overtime.approve', $assignment))->assertForbidden();
});

it('blocks a user without the payroll permission from the inputs screen', function () {
    $this->actingAs($this->staffUser)->withSession(['current_business_id' => $this->business->id])
        ->get(route('hrm.payroll.inputs.index'))->assertForbidden();
});

it('renders the payroll inputs screen', function () {
    app(RequestOvertime::class)->handle($this->employee, WORK_DATE, 2, false, 'Stock take', $this->hr);

    inputHr()->get(route('hrm.payroll.inputs.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('hrm/payroll/inputs')
            ->has('overtime', 1)
            ->has('employees')
            ->has('periods')
            ->has('adjustment_types'));
});

it('offers no arrear among the adjustment types a human may enter', function () {
    inputHr()->get(route('hrm.payroll.inputs.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('hrm/payroll/inputs')
            ->where('adjustment_types', fn ($types) => collect($types)->pluck('value')->doesntContain('arrear')));
});

it('records overtime through the real HTTP route', function () {
    inputHr()->post(route('hrm.overtime.store'), [
        'employee_id' => $this->employee->id,
        'work_date' => WORK_DATE,
        'hours' => 2,
        'is_holiday_work' => false,
        'reason' => 'Stock take',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(OvertimeAssignment::count())->toBe(1);
});

// --- Audit 13 Oct 2026: a month past Calculated takes no new inputs ------------------

function reviewedInputMonth(): void
{
    test()->period->runs()->create(['version' => 1, 'status' => 'reviewed']);
}

it('refuses an adjustment aimed at a month already reviewed or approved', function (string $status) {
    $this->period->runs()->create(['version' => 1, 'status' => $status]);

    expect(fn () => adjustment(PayrollAdjustmentType::Addition, 1000))
        ->toThrow(ValidationException::class, 'no longer takes new inputs');
})->with(['reviewed', 'approved']);

it('still takes an adjustment while the month is only calculated, or was voided', function (string $status) {
    $this->period->runs()->create(['version' => 1, 'status' => $status]);

    expect(adjustment(PayrollAdjustmentType::Addition, 1000))->toBeInstanceOf(PayrollAdjustment::class);
})->with(['draft', 'calculated', 'cancelled', 'rejected']);

it('refuses to approve an adjustment once its month has been reviewed', function () {
    $adjustment = adjustment(PayrollAdjustmentType::Addition, 1000);
    reviewedInputMonth();

    inputHr()->post(route('hrm.adjustments.approve', $adjustment))->assertSessionHasErrors('payroll_period_id');

    expect($adjustment->fresh()->isApproved())->toBeFalse();
});

it('refuses paid overtime in a reviewed month, at request and at approval', function () {
    $overtime = app(RequestOvertime::class)->handle($this->employee, WORK_DATE, 2, false, 'Stock take', $this->hr);
    reviewedInputMonth();

    inputHr()->post(route('hrm.overtime.approve', $overtime))->assertSessionHasErrors('work_date');
    expect(fn () => app(RequestOvertime::class)->handle($this->employee, '2026-07-02', 2, false, 'Again', $this->hr))
        ->toThrow(ValidationException::class, 'no longer takes new inputs');
});

it('refuses a bonus award and its approval once the month has been reviewed', function () {
    $event = BonusEvent::create([
        'name' => 'Eid-ul-Fitr', 'calculation_basis' => SalaryCalculationBasis::Fixed,
        'value' => 5000, 'payroll_period_id' => $this->period->id,
    ]);
    app(AwardBonus::class)->handle($event, Employee::all(), $this->hr);
    reviewedInputMonth();

    inputHr()->post(route('hrm.bonus.awards.approve', BonusAward::sole()))->assertSessionHasErrors('payroll_period_id');
    expect(fn () => app(AwardBonus::class)->handle($event, Employee::all(), $this->hr))
        ->toThrow(ValidationException::class);
});
