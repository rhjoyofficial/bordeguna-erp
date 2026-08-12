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

it('prices banked leave at one day of Gross and posts the liability', function () {
    $employee = ($this->makeEmployee)('E-001', 'Karim');
    ($this->giveSalary)($employee, '30000');
    ($this->grant)($employee, 12);

    $run = ($this->run)();

    // 12 days x (30,000 / 30) = 12,000.
    expect((float) $run->total_liability)->toBe(12000.0)
        ->and((float) $run->opening_balance)->toBe(0.0)
        ->and((float) $run->delta)->toBe(12000.0)
        ->and($run->employees_affected)->toBe(1);

    // Dr 6130 Leave Encashment / Cr 2135 Provision (party = employee).
    $journal = ($this->journalOf)($run);
    $lines = JournalLine::where('journal_id', $journal->id)->get();

    expect($lines)->toHaveCount(2)
        ->and($lines->firstWhere('account_id', ($this->acct)('leave_encashment'))->amount_minor)->toBe(1200000)
        ->and($lines->firstWhere('account_id', ($this->acct)('leave_provision'))->amount_minor)->toBe(-1200000);

    // The provision side carries the employee, so it is a subsidiary ledger.
    $provisionLine = $lines->firstWhere('account_id', ($this->acct)('leave_provision'));
    expect($provisionLine->party_id)->toBe($employee->id);
});

it('ignores leave types that cannot be encashed', function () {
    $employee = ($this->makeEmployee)('E-001', 'Karim');
    ($this->giveSalary)($employee, '30000');
    ($this->grant)($employee, 10, $this->notEncashable);

    $run = ($this->run)();

    // Casual leave lapsing unpaid is not a liability; providing for it would
    // invent money.
    expect((float) $run->total_liability)->toBe(0.0)
        ->and(($this->journalOf)($run))->toBeNull();
});

// ⚠⚠ The heart of the design: a true-up aims at the computed truth, so it
// posts only the movement and a second run on the same facts posts nothing.
it('posts only the delta, and a rerun on the same facts posts nothing', function () {
    $employee = ($this->makeEmployee)('E-001', 'Karim');
    ($this->giveSalary)($employee, '30000');
    ($this->grant)($employee, 12);

    ($this->run)();

    // Three days taken since.
    LeaveLedgerEntry::create([
        'business_id' => $this->business->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $this->encashable->id,
        'year' => 2026, 'entry_type' => 'taken',
        'days' => -3, 'occurred_on' => '2026-08-05',
    ]);

    $second = ($this->run)();

    expect((float) $second->total_liability)->toBe(9000.0)
        ->and((float) $second->opening_balance)->toBe(12000.0)
        ->and((float) $second->delta)->toBe(-3000.0);

    // The release is a DEBIT to the provision and a CREDIT to the expense.
    $lines = JournalLine::where('journal_id', ($this->journalOf)($second)->id)->get();
    expect($lines->firstWhere('account_id', ($this->acct)('leave_provision'))->amount_minor)->toBe(300000)
        ->and($lines->firstWhere('account_id', ($this->acct)('leave_encashment'))->amount_minor)->toBe(-300000);

    // The account now carries exactly the computed liability.
    expect(-($this->balance)('leave_provision'))->toBe(900000);

    $third = ($this->run)();
    expect((float) $third->delta)->toBe(0.0)
        ->and(($this->journalOf)($third))->toBeNull();
});

it('raises the liability for days already banked when the salary rises', function () {
    $employee = ($this->makeEmployee)('E-001', 'Karim');
    $structure = ($this->giveSalary)($employee, '30000');
    ($this->grant)($employee, 10);

    ($this->run)();
    expect(-($this->balance)('leave_provision'))->toBe(1000000);

    // A raise: encashment pays at the rate in force when it is PAID.
    $structure->update(['effective_to' => '2026-08-10']);
    $newStructure = SalaryStructure::create([
        'business_id' => $this->business->id,
        'employee_id' => $employee->id,
        'effective_from' => '2026-08-11',
    ]);
    SalaryStructureLine::create([
        'salary_structure_id' => $newStructure->id,
        'salary_component_id' => SalaryComponent::where('code', 'basic')->first()->id,
        'value' => '36000',
    ]);

    $second = ($this->run)();

    expect((float) $second->total_liability)->toBe(12000.0)
        ->and((float) $second->delta)->toBe(2000.0);
});

// ⚠ A missing salary structure is an administrative gap, not a free liability.
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

it('clamps a negative balance per type instead of netting it off another', function () {
    $employee = ($this->makeEmployee)('E-001', 'Karim');
    ($this->giveSalary)($employee, '30000');

    $second = LeaveType::create([
        'business_id' => $this->business->id, 'name' => 'Compensatory', 'code' => 'COMP',
        'annual_quota' => 5, 'can_encash' => true,
    ]);

    ($this->grant)($employee, 10);
    ($this->grant)($employee, -4, $second);

    // 10 + max(0, -4) = 10 days, not 6: a negative balance is not a debt the
    // employee owes back.
    expect((float) ($this->run)()->total_liability)->toBe(10000.0);
});

it('drains the provision when an employee leaves', function () {
    $stayer = ($this->makeEmployee)('E-001', 'Karim');
    ($this->giveSalary)($stayer, '30000');
    ($this->grant)($stayer, 6);

    $leaver = ($this->makeEmployee)('E-002', 'Rahim');
    ($this->giveSalary)($leaver, '30000');
    ($this->grant)($leaver, 4);

    ($this->run)();
    expect(-($this->balance)('leave_provision'))->toBe(1000000);

    $leaver->update(['separation_date' => '2026-08-11']);

    $second = ($this->run)();

    expect((float) $second->total_liability)->toBe(6000.0)
        ->and((float) $second->delta)->toBe(-4000.0)
        ->and(-($this->balance)('leave_provision'))->toBe(600000);

    // The leaver's own party balance is back to zero - the subsidiary ledger
    // agrees with the fact that nothing is owed to them under this head.
    $leaverBalance = (int) JournalLine::where('account_id', ($this->acct)('leave_provision'))
        ->where('party_id', $leaver->id)->sum('amount_minor');
    expect($leaverBalance)->toBe(0);
});

it('keeps a per-employee balance for each person', function () {
    $a = ($this->makeEmployee)('E-001', 'Karim');
    ($this->giveSalary)($a, '30000');
    ($this->grant)($a, 6);

    $b = ($this->makeEmployee)('E-002', 'Rahim');
    ($this->giveSalary)($b, '60000');
    ($this->grant)($b, 3);

    ($this->run)();

    $forA = (int) JournalLine::where('account_id', ($this->acct)('leave_provision'))->where('party_id', $a->id)->sum('amount_minor');
    $forB = (int) JournalLine::where('account_id', ($this->acct)('leave_provision'))->where('party_id', $b->id)->sum('amount_minor');

    expect(-$forA)->toBe(600000)   // 6 x 1,000
        ->and(-$forB)->toBe(600000); // 3 x 2,000
});

it('agrees with reconciliation after every run', function () {
    $employee = ($this->makeEmployee)('E-001', 'Karim');
    ($this->giveSalary)($employee, '30000');
    ($this->grant)($employee, 12);

    ($this->run)();

    $result = SubledgerReconciliation::reconcileAllBusinesses();
    expect($result['problems'])->toBeEmpty();
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
it('records the run but posts nothing when accounting is off', function () {
    $employee = ($this->makeEmployee)('E-001', 'Karim');
    ($this->giveSalary)($employee, '30000');
    ($this->grant)($employee, 12);

    AccountMapping::where('key', 'payroll.net_payable')->delete();

    $run = ($this->run)();

    expect((float) $run->total_liability)->toBe(12000.0)
        ->and(($this->journalOf)($run))->toBeNull();
});

// ⚠⚠ The gap between the two views of the provision account: the run's delta
// is measured against the WHOLE balance, the per-employee lines only against
// party-tagged ones. C-LPE-4's pre-cutover catch-up lands exactly there, and
// before this was closed the difference was swept into Rounding Differences -
// real payroll expense misfiled, and the provision left overstated.
it('trues up a provision balance that carries no employee, instead of rounding it away', function () {
    $employee = ($this->makeEmployee)('E-001', 'Karim');
    ($this->giveSalary)($employee, '30000');
    ($this->grant)($employee, 12);

    AccountMapping::create([
        'key' => 'accounting.rounding_difference',
        'account_id' => ($this->acct)('rounding_difference'),
        'effective_from' => '2020-01-01',
    ]);

    // The accountant's catch-up: Dr 6130 / Cr 2135, no party on either side.
    app(PostJournal::class)->handle(
        new JournalDraft(
            date: Carbon::parse('2026-08-01'),
            lines: [
                JournalLineDraft::debit(($this->acct)('leave_encashment'), Money::ofTaka('5000')),
                JournalLineDraft::credit(($this->acct)('leave_provision'), Money::ofTaka('5000')),
            ],
            memo: 'Pre-cutover leave provision catch-up',
        ),
        $this->user,
    );

    $run = ($this->run)();

    expect((float) $run->total_liability)->toBe(12000.0)
        ->and((float) $run->opening_balance)->toBe(5000.0)
        ->and((float) $run->delta)->toBe(7000.0);

    // The account lands on the COMPUTED liability, not liability plus stray.
    expect(-($this->balance)('leave_provision'))->toBe(1200000)
        // ...and not a paisa of it was filed as a rounding difference.
        ->and(($this->balance)('rounding_difference'))->toBe(0);

    // The unattributed 5,000 was released on its own party-less line.
    $lines = JournalLine::where('journal_id', ($this->journalOf)($run)->id)
        ->where('account_id', ($this->acct)('leave_provision'))->get();

    expect($lines->firstWhere('party_id', $employee->id)->amount_minor)->toBe(-1200000)
        ->and($lines->firstWhere('party_id', null)->amount_minor)->toBe(500000);

    // And reconciliation agrees, which is the whole point of trueing up.
    expect(SubledgerReconciliation::reconcileAllBusinesses()['problems'])->toBe([]);
});
