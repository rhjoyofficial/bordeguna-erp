<?php

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\SubledgerReconciliation;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\RunLeaveProvision;
use App\Modules\Hrm\database\seeders\PayrollAccountMappingSeeder;
use App\Modules\Hrm\database\seeders\SalaryComponentSeeder;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\LeaveLedgerEntry;
use App\Modules\Hrm\Models\LeaveProvisionRun;
use App\Modules\Hrm\Models\LeaveType;
use App\Modules\Hrm\Models\SalaryComponent;
use App\Modules\Hrm\Models\SalaryStructure;
use App\Modules\Hrm\Models\SalaryStructureLine;
use Illuminate\Support\Carbon;
use Spatie\Permission\PermissionRegistrar;

/**
 * The leave encashment provision (coverage item 5).
 *
 * ⚠⚠ The liability existed from day one and was recorded NOWHERE: two staff
 * have seven years of service and encashment is valued on Gross (Joy,
 * 2026-08-01). A balance sheet that omits a known liability is the named
 * failure class - perfectly balanced, and wrong.
 *
 * The engine is a TRUE-UP: it computes the whole liability and posts only the
 * delta, so a skipped month, a raise or a lapse all self-heal.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Provision Co', 'slug' => 'provision-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    (new ChartOfAccountsSeeder)->run();
    (new SalaryComponentSeeder)->run();
    (new PayrollAccountMappingSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);

    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);

    $this->office = Office::create(['name' => 'Head Office', 'is_active' => true]);
    $this->department = Department::create([
        'name' => 'Production', 'office_id' => $this->office->id, 'is_active' => true,
    ]);

    Carbon::setTestNow('2026-08-12 10:00:00');

    $this->encashable = LeaveType::create([
        'business_id' => $this->business->id, 'name' => 'Earned leave', 'code' => 'EL',
        'annual_quota' => 20, 'can_encash' => true,
    ]);

    $this->notEncashable = LeaveType::create([
        'business_id' => $this->business->id, 'name' => 'Casual leave', 'code' => 'CL',
        'annual_quota' => 10, 'can_encash' => false,
    ]);

    $this->makeEmployee = function (string $code, string $name) {
        $employee = Employee::create([
            'employee_code' => $code,
            'first_name' => $name, 'last_name' => 'Ahmed',
            'employment_type' => 'permanent', 'status' => 'active',
        ]);

        EmployeeAssignment::create([
            'employee_id' => $employee->id,
            'business_id' => $this->business->id,
            'office_id' => $this->office->id,
            'department_id' => $this->department->id,
            'is_primary' => true, 'effective_from' => '2025-01-01',
        ]);

        return $employee;
    };

    // Gross 30,000 as a single Basic line, so one day's pay is exactly 1,000
    // under PAY-02 (Gross divided by the 30-day divisor).
    $this->giveSalary = function (Employee $employee, string $basic) {
        $structure = SalaryStructure::create([
            'business_id' => $this->business->id,
            'employee_id' => $employee->id,
            'effective_from' => '2025-01-01',
        ]);

        SalaryStructureLine::create([
            'salary_structure_id' => $structure->id,
            'salary_component_id' => SalaryComponent::where('code', 'basic')->first()->id,
            'value' => $basic,
        ]);

        return $structure;
    };

    $this->grant = function (Employee $employee, float $days, ?LeaveType $type = null, string $on = '2026-01-01') {
        return LeaveLedgerEntry::create([
            'business_id' => $this->business->id,
            'employee_id' => $employee->id,
            'leave_type_id' => ($type ?? $this->encashable)->id,
            'year' => 2026, 'entry_type' => 'grant',
            'days' => $days, 'occurred_on' => $on,
        ]);
    };

    $this->acct = fn (string $slug) => Account::bySlug($slug)->id;

    $this->balance = fn (string $slug) => (int) JournalLine::where('account_id', ($this->acct)($slug))->sum('amount_minor');

    $this->journalOf = fn (LeaveProvisionRun $run) => Journal::where('source_type', $run->getMorphClass())
        ->where('source_id', $run->id)->first();

    $this->run = fn (mixed $on = null) => app(RunLeaveProvision::class)->commit($this->user, $on);
});

afterEach(fn () => Carbon::setTestNow());

it('reports days it cannot price instead of valuing them at zero', function () {
    $priced = ($this->makeEmployee)('E-001', 'Karim');
    ($this->giveSalary)($priced, '30000');
    ($this->grant)($priced, 5);

    $unpriced = ($this->makeEmployee)('E-002', 'Rahim');
    ($this->grant)($unpriced, 8);

    $plan = app(RunLeaveProvision::class)->plan();

    expect($plan['totals']['unpriced'])->toBe(1)
        ->and($plan['totals']['liability'])->toBe(5000.0);

    $row = collect($plan['rows'])->firstWhere('employee_id', $unpriced->id);
    expect($row['days'])->toBe(8.0)
        ->and($row['amount'])->toBe(0.0)
        ->and($row['explanation'])->toContain('could NOT be priced');
});

it('previews without changing anything', function () {
    $employee = ($this->makeEmployee)('E-001', 'Karim');
    ($this->giveSalary)($employee, '30000');
    ($this->grant)($employee, 12);

    $preview = app(RunLeaveProvision::class)->preview();

    expect($preview->is_dry_run)->toBeTrue()
        ->and((float) $preview->total_liability)->toBe(12000.0)
        ->and(($this->journalOf)($preview))->toBeNull()
        ->and(Journal::count())->toBe(0);
});

// ⚠ The standing rule: an accounting feature must never stop an operational
// process. Without the payroll anchor the leave book runs exactly as before.

// ⚠⚠ THE POSTING SUITE IS GONE, DELIBERATELY - 23 Aug 2026, Joy's final
// decision: leave encashment is NOT enabled. Unused leave creates no financial
// liability and no provision journal, and the ~133,566.67 BDT the engine
// values must never be posted unless he explicitly changes the policy. The
// valuation (plan/preview) stays, as information for HR; the posting refuses.
// The ten tests that pinned the posting arithmetic live in git history at this
// commit and come back with the policy, reviewed, not by accident.

it('refuses to commit a provision run, because encashment is not enabled', function () {
    $employee = ($this->makeEmployee)('E-001', 'Karim');
    ($this->giveSalary)($employee, '30000');
    ($this->grant)($employee, 12);

    expect(fn () => ($this->run)())
        ->toThrow(RuntimeException::class, 'Leave encashment is not enabled');

    // Refused BEFORE anything was written: no run row, no journal, and the
    // provision account still carries nothing.
    expect(LeaveProvisionRun::count())->toBe(0)
        ->and(Journal::count())->toBe(0)
        ->and(($this->balance)('leave_provision') ?? 0)->toBe(0);
});

it('refuses to post a journal for any run, at the posting seam too', function () {
    // Both doors are locked independently. If a future caller reaches the
    // posting action without going through commit(), the policy still holds.
    $run = new LeaveProvisionRun;

    expect(fn () => app(App\Modules\Hrm\Actions\PostLeaveProvision::class)->forRun($run, $this->user))
        ->toThrow(RuntimeException::class, 'Leave encashment is not enabled');
});
