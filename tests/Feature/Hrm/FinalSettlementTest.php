<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Enums\LoanLedgerEntryType;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\LeaveLedgerEntry;
use App\Modules\Hrm\Models\LeaveType;
use App\Modules\Hrm\Models\Loan;
use App\Modules\Hrm\Models\LoanLedgerEntry;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Models\Payslip;
use App\Modules\Hrm\Models\PayslipPayment;
use App\Modules\Hrm\Reporting\Definitions\SettlementWorksheetReport;
use App\Modules\Hrm\Reporting\ReportParameters;
use App\Modules\Hrm\Support\SettlementFigures;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The final settlement statement.
 *
 * ⚠⚠ EMP-08 approved a settlement, no phase built one, and the reason was real:
 * a settlement has to price unused leave and the encashment rate was never
 * agreed. Joy has since switched encashment off on every leave type, so what is
 * left is salary already earned and a loan already lent. A total made only of
 * ledger facts is arithmetic; the moment a type becomes encashable again it
 * stops being arithmetic, and these tests pin that.
 */
beforeEach(function () {
    $this->business = Business::create([
        'name' => 'Leaver Co',
        'slug' => 'leaver-co',
        'legal_name' => 'Leaver Company Limited',
        'address' => 'Adabor, Dhaka',
        'phone' => '01711000000',
        'email' => 'hr@leaverco.test',
    ]);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->office = Office::create(['business_id' => $this->business->id, 'name' => 'Head Office']);
    $this->department = Department::create([
        'business_id' => $this->business->id, 'office_id' => $this->office->id, 'name' => 'Sales',
    ]);

    foreach (['hrm.payroll.manage', 'hrm.employees.manage'] as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    foreach (['Owner', 'Clerk'] as $roleName) {
        Role::firstOrCreate(['name' => $roleName, 'business_id' => $this->business->id, 'guard_name' => 'web']);
    }

    Role::where('business_id', $this->business->id)->where('name', 'Owner')->first()
        ->givePermissionTo(['hrm.payroll.manage', 'hrm.employees.manage']);

    // Deliberately half-privileged: may manage employees, may not see pay.
    Role::where('business_id', $this->business->id)->where('name', 'Clerk')->first()
        ->givePermissionTo('hrm.employees.manage');

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->owner->assignRole('Owner');

    $this->leaver = Employee::create([
        'employee_code' => 'BDG-000031',
        'first_name' => 'Karim', 'last_name' => 'Ahmed',
        'employment_type' => 'permanent', 'status' => 'separated',
        'joining_date' => '2025-01-01',
        'separation_type' => 'resignation',
        'separation_date' => '2026-07-20',
    ]);

    EmployeeAssignment::create([
        'employee_id' => $this->leaver->id,
        'office_id' => $this->office->id,
        'department_id' => $this->department->id,
        'is_primary' => true,
        'effective_from' => '2025-01-01',
    ]);

    $period = PayrollPeriod::create([
        'business_id' => $this->business->id,
        'year' => 2026, 'month' => 7,
        'start_date' => '2026-06-24', 'end_date' => '2026-07-23', 'divisor_days' => 30,
    ]);

    $run = PayrollRun::create([
        'business_id' => $this->business->id,
        'payroll_period_id' => $period->id,
        'version' => 1, 'status' => 'disbursed', 'employee_count' => 1,
    ]);

    // Earned, never paid: no payslip_payments rows behind it.
    Payslip::create([
        'business_id' => $this->business->id,
        'payroll_run_id' => $run->id,
        'employee_id' => $this->leaver->id,
        'gross_salary' => 25000, 'total_earnings' => 25000,
        'total_deductions' => 0, 'net' => 25000, 'is_blocked' => false,
    ]);

    $loan = Loan::create([
        'business_id' => $this->business->id, 'employee_id' => $this->leaver->id,
        'reference' => 'LOAN-000031', 'type' => 'loan', 'status' => 'active',
        'principal' => 8000, 'installment_amount' => 2000, 'installment_count' => 4,
        'start_year' => 2026, 'start_month' => 7, 'reason' => 'Family', 'applied_at' => now(),
    ]);

    LoanLedgerEntry::create([
        'business_id' => $this->business->id, 'loan_id' => $loan->id, 'employee_id' => $this->leaver->id,
        'entry_type' => LoanLedgerEntryType::Disbursement, 'amount' => 8000, 'occurred_on' => '2026-06-01',
    ]);

    $this->asOwner = fn () => $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id]);
});

// ⚠ Every numeric assertion below compares as a float on purpose: JSON hands
// back 12 for 12.0, and `where('x', 12.0)` fails on a value that is correct.
it('states what is owed, what is recovered, and the net', function () {
    ($this->asOwner)()->get(route('hrm.employees.settlement', $this->leaver))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('hrm/settlement/show')
            ->where('figures.unpaid_salary', fn ($value) => (float) $value === 25000.0)
            ->where('figures.loan_outstanding', fn ($value) => (float) $value === 8000.0)
            ->where('net', fn ($value) => (float) $value === 17000.0)
            ->where('can_total', true)
            ->where('employee.has_left', true)
            ->where('issuer.legal_name', 'Leaver Company Limited')
        );
});

it('says the employee owes the company when the loan outruns the last payslip', function () {
    // ⚠ A real outcome, not an edge case, and it must not be shown as a negative
    // amount payable. Somebody reads that as money to hand over.
    $loan = Loan::where('employee_id', $this->leaver->id)->sole();

    LoanLedgerEntry::create([
        'business_id' => $this->business->id, 'loan_id' => $loan->id, 'employee_id' => $this->leaver->id,
        'entry_type' => LoanLedgerEntryType::Disbursement, 'amount' => 30000, 'occurred_on' => '2026-06-15',
    ]);

    ($this->asOwner)()->get(route('hrm.employees.settlement', $this->leaver))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('net', fn ($value) => (float) $value === -13000.0));
});

it('refuses to total the moment any leave becomes encashable again', function () {
    // ⚠⚠ THE SAFETY CATCH. The statement can only be totalled because nothing on
    // it is priced by a rule that does not exist. Tick a type as encashable and
    // the encashment rate is needed again, so the total goes away rather than
    // being invented.
    $type = LeaveType::create([
        'business_id' => $this->business->id, 'name' => 'Earned leave', 'code' => 'EL',
        'annual_quota' => 20, 'can_encash' => true,
    ]);

    LeaveLedgerEntry::create([
        'business_id' => $this->business->id, 'employee_id' => $this->leaver->id,
        'leave_type_id' => $type->id, 'year' => 2026, 'entry_type' => 'grant',
        'days' => 12, 'occurred_on' => '2026-01-01',
    ]);

    ($this->asOwner)()->get(route('hrm.employees.settlement', $this->leaver))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('can_total', false)
            ->where('encashment_is_live', true)
            ->where('figures.encashable_leave_days', fn ($value) => (float) $value === 12.0)
        );
});

it('agrees exactly with the settlement worksheet report', function () {
    // ⚠⚠ One leaver, two screens, one set of numbers. Before this slice the
    // report derived them with its own SQL; a statement with a second derivation
    // is a second opinion about a final payment.
    $row = collect(app(SettlementWorksheetReport::class)
        ->rows(new ReportParameters(['outstanding_only' => true]))->all())
        ->firstWhere('employee_code', 'BDG-000031');

    $figures = SettlementFigures::for($this->leaver);

    expect($figures['unpaid_salary'])->toBe($row['unpaid_salary'])
        ->and($figures['loan_outstanding'])->toBe($row['loan_outstanding'])
        ->and($figures['encashable_leave_days'])->toBe($row['encashable_leave_days']);
});

it('is shut to somebody who may manage employees but not payroll', function () {
    $clerk = User::factory()->create();
    $clerk->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $clerk->assignRole('Clerk');

    $this->actingAs($clerk)->withSession(['current_business_id' => $this->business->id])
        ->get(route('hrm.employees.settlement', $this->leaver))
        ->assertForbidden();
});

it('will not open for somebody outside this business', function () {
    $stranger = Employee::create([
        'employee_code' => 'OTHER-0001',
        'first_name' => 'Someone', 'last_name' => 'Else',
        'employment_type' => 'permanent', 'status' => 'separated',
    ]);

    // No assignment in this business, so this business cannot see them at all.
    ($this->asOwner)()->get("/hrm/employees/{$stranger->id}/settlement")->assertNotFound();
});

// ⚠⚠ Audit 13 Oct 2026: only each month's payslip of record is owed.
function settlementSlip(int $businessId, PayrollPeriod $period, int $version, string $status, float $net, int $employeeId): Payslip
{
    $run = PayrollRun::create([
        'business_id' => $businessId, 'payroll_period_id' => $period->id,
        'version' => $version, 'status' => $status, 'employee_count' => 1,
    ]);

    return Payslip::create([
        'business_id' => $businessId, 'payroll_run_id' => $run->id, 'employee_id' => $employeeId,
        'gross_salary' => $net, 'total_earnings' => $net, 'total_deductions' => 0, 'net' => $net, 'is_blocked' => false,
    ]);
}

it('owes only the newest approved version of each month, in this business', function () {
    $id = $this->leaver->id;
    $biz = $this->business->id;

    // August: cancelled then still draft - nothing of record yet, nothing owed.
    $aug = PayrollPeriod::create(['business_id' => $biz, 'year' => 2026, 'month' => 8,
        'start_date' => '2026-07-24', 'end_date' => '2026-08-23', 'divisor_days' => 30]);
    settlementSlip($biz, $aug, 1, 'cancelled', 25000, $id);
    settlementSlip($biz, $aug, 2, 'draft', 25000, $id);

    // September: rejected, then approved - only the approved one counts.
    $sep = PayrollPeriod::create(['business_id' => $biz, 'year' => 2026, 'month' => 9,
        'start_date' => '2026-08-24', 'end_date' => '2026-09-23', 'divisor_days' => 30]);
    settlementSlip($biz, $sep, 1, 'rejected', 10000, $id);
    settlementSlip($biz, $sep, 2, 'approved', 12000, $id);

    // Another business in the group pays the same person: not ours to state.
    $other = Business::create(['name' => 'Sister Co', 'slug' => 'sister-co']);
    $otherPeriod = PayrollPeriod::create(['business_id' => $other->id, 'year' => 2026, 'month' => 7,
        'start_date' => '2026-06-24', 'end_date' => '2026-07-23', 'divisor_days' => 30]);
    settlementSlip($other->id, $otherPeriod, 1, 'approved', 5000, $id);

    // July 25,000 (from setup) + September 12,000.
    expect(SettlementFigures::for($this->leaver)['unpaid_salary'])->toBe(37000.0);
});

it('counts money paid against a locked version once its unlocked replacement is the record', function () {
    $biz = $this->business->id;
    $july = PayrollPeriod::where('year', 2026)->where('month', 7)->sole();
    $locked = Payslip::where('employee_id', $this->leaver->id)->sole();
    $locked->run->update(['status' => 'locked']);

    PayslipPayment::create([
        'business_id' => $biz, 'payslip_id' => $locked->id, 'employee_id' => $this->leaver->id,
        'event_type' => 'paid', 'method' => 'bank_transfer', 'amount' => 25000, 'paid_on' => '2026-07-25',
    ]);

    settlementSlip($biz, $july, 2, 'approved', 26000, $this->leaver->id);

    // 26,000 now of record, 25,000 already paid against version 1.
    expect(SettlementFigures::for($this->leaver)['unpaid_salary'])->toBe(1000.0);
});
