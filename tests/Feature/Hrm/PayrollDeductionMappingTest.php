<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Hrm\Actions\SaveSalaryStructure;
use App\Modules\Hrm\Actions\TransitionPayrollRun;
use App\Modules\Hrm\database\seeders\PayrollAccountMappingSeeder;
use App\Modules\Hrm\database\seeders\SalaryComponentSeeder;
use App\Modules\Hrm\Enums\PayrollAdjustmentType;
use App\Modules\Hrm\Enums\PayrollStatus;
use App\Modules\Hrm\Enums\SalaryComponentType;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Models\SalaryComponent;
use App\Modules\Hrm\Payroll\PayrollConfig;
use App\Modules\Hrm\Payroll\PayrollContextBuilder;
use App\Modules\Hrm\Payroll\PayrollEngine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ⚠⚠ EVERY DEDUCTION CODE PAYROLL CAN PUT ON A PAYSLIP IS MAPPED ON PURPOSE.
 *
 * Found 15 Oct 2026 on the September payroll: Munna's part-month cut (code
 * `proration`, 10,000) had no mapping of its own, fell through to
 * `payroll.deduction.default` and was credited to 2140 Employee Deductions
 * Payable, a liability, while the full 12,000 stayed in 6131 Salaries. The
 * journal balanced, so nothing noticed: the wage cost was overstated by 10,000
 * and the books said the company owed somebody 10,000 it did not owe.
 *
 * The default is right only for money held FOR someone else. A code that falls
 * through to it is therefore a guess, and this file is what stops a new code
 * from guessing silently.
 */

/**
 * Codes payroll emits on purpose but whose home is a decision still open. Each
 * must be emitted (or the entry is stale) and must NOT be mapped (or the entry
 * is out of date). Shrink this list; never grow it without a reason written here.
 */
function undecidedDeductionCodes(): array
{
    return [
        // The generic adjustment type. Hand-typed it can mean anything; generated it
        // is SettleFrozenAttendance's "attendance changed after approval" (rule #18),
        // which is less wage. One code, two meanings: the owner decides.
        'deduction' => 'owner decision: generic adjustment shares a code with the rule #18 attendance settlement',
        // Clears the walk-in invoice the staff purchase hand-over left open in 1140
        // Accounts Receivable. A mapping row alone would credit AR with no customer
        // and no receipt, so the invoice stays open and AR stops reconciling.
        'staff_purchase' => 'needs a customer receipt against the open invoice, not a mapping row',
        // PAY-11 shortfall recovery. Its earning twin `balance_carry_forward` is on
        // the wage default; moving the pair to 1170 also needs the Employee Advances
        // reconciliation (today: loan ledger only) to read the balance ledger.
        'balance_recovery' => 'moves together with balance_carry_forward and the 1170 reconciliation',
    ];
}

/**
 * Every deduction code the engine, its inputs, the adjustment types and the
 * seeded salary components can produce, read from the code itself so that a
 * new one cannot appear without this test seeing it.
 *
 * @return array<string, string> code => where it comes from
 */
function emittedDeductionCodes(): array
{
    $codes = [];

    // 1. The engine stages: every `new PayslipLineData(code: ..., label: ..., type: ...)`.
    foreach (glob(app_path('Modules/Hrm/Payroll/Stages/*.php')) as $file) {
        $source = file_get_contents($file);
        $stage = basename($file, '.php');

        preg_match_all('/code:\s*([^,\n]+),\s*\n\s*label:[^\n]*\n\s*type:\s*([^,\n]+),/', $source, $matches, PREG_SET_ORDER);

        foreach ($matches as [, $code, $type]) {
            $code = trim($code);

            if (str_contains($type, 'Earning')) {
                continue;
            }

            if (preg_match("/^'(\w+)'$/", $code, $literal)) {
                $codes[$literal[1]] = $stage;
            } elseif ($code === 'self::CODE') {
                $codes[constant('App\\Modules\\Hrm\\Payroll\\Stages\\'.$stage.'::CODE')] = $stage;
            } elseif ($code === '$code') {
                // ApplyAbsenceDeductions: the code is the second argument of deduct().
                preg_match_all('/->deduct\(\$computation,\s*\'(\w+)\'/', $source, $deducted);
                expect($deducted[1])->not->toBeEmpty();

                foreach ($deducted[1] as $literal) {
                    $codes[$literal] = $stage;
                }
            } elseif (! in_array($code, ['$line->code', '$input->code'], true)) {
                // `$line->code` is a salary component (part 3), `$input->code`
                // a context input (part 2). Anything else is a new source.
                throw new RuntimeException("{$stage} emits a payslip code from `{$code}`, which this guard cannot read. Teach it.");
            }
        }
    }

    // 2. The context builder's inputs: loans, the PAY-11 balance and adjustments.
    $builder = file_get_contents(app_path('Modules/Hrm/Payroll/PayrollContextBuilder.php'));
    preg_match_all('/PayrollInput::deduction\(\s*code:\s*\'(\w+)\'/', $builder, $inputs);

    foreach ($inputs[1] as $code) {
        $codes[$code] = 'PayrollContextBuilder';
    }

    preg_match_all('/code:\s*([^,\n]+),/', $builder, $all);

    foreach ($all[1] as $code) {
        expect(preg_match("/^'\w+'$/", trim($code)) === 1 || trim($code) === '$adjustment->type->value')
            ->toBeTrue("PayrollContextBuilder emits a payslip code from `{$code}`, which this guard cannot read. Teach it.");
    }

    foreach (PayrollAdjustmentType::cases() as $type) {
        if (! $type->isEarning()) {
            $codes[$type->value] = 'PayrollAdjustmentType';
        }
    }

    // 3. The seeded deduction components (SAL-03).
    foreach (SalaryComponentSeeder::definitions() as $definition) {
        if ($definition['type'] === SalaryComponentType::Deduction->value) {
            $codes[$definition['code']] = 'SalaryComponentSeeder';
        }
    }

    ksort($codes);

    return $codes;
}

beforeEach(function () {
    Carbon::setTestNow('2026-10-15 10:00:00');

    $this->business = Business::create(['name' => 'Wage Co', 'slug' => 'wage-co']);
    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);

    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(PayrollAccountMappingSeeder::class);
    TenantScope::enter($this->business->id);
});

// ─── The guard ──────────────────────────────────────────────────────────────

it('reads the codes from the code, so the guard is never vacuous', function () {
    expect(array_keys(emittedDeductionCodes()))->toContain(
        'proration', 'absent', 'lwp', 'late_early', 'loan_repayment',
        'balance_recovery', 'staff_purchase', 'penalty', 'recovery', 'deduction',
        'income_tax', 'provident_fund', 'advance_recovery', 'festival_loan', 'other',
    );
});

it('maps every deduction code payroll can emit on purpose, not through the default', function () {
    $today = Carbon::parse('2026-09-30');

    $unmapped = collect(emittedDeductionCodes())
        ->reject(fn (string $from, string $code) => array_key_exists($code, undecidedDeductionCodes()))
        ->filter(fn (string $from, string $code) => AccountMapping::forKeyOn("payroll.deduction.{$code}", $today) === null)
        ->map(fn (string $from, string $code) => "{$code} (from {$from})")
        ->values()
        ->all();

    expect($unmapped)->toBe([], 'Unmapped payroll deduction codes fall through to 2140 Employee Deductions Payable. Map each in PayrollAccountMappingSeeder (and a data migration for live books): '.implode(', ', $unmapped));
});

it('keeps the undecided list honest: still emitted, still unmapped', function () {
    $emitted = emittedDeductionCodes();

    foreach (array_keys(undecidedDeductionCodes()) as $code) {
        expect($emitted)->toHaveKey($code)
            ->and(AccountMapping::forKeyOn("payroll.deduction.{$code}", now()))->toBeNull();
    }
});

it('credits a part-month cut and a salary claw-back back to the wage cost', function () {
    $wages = Account::bySlug('salaries_expense')->id;

    foreach (['proration', 'recovery'] as $code) {
        expect(AccountMapping::resolve("payroll.deduction.{$code}", Carbon::parse('2026-09-30'), 'payroll.deduction.default')->id)
            ->toBe($wages);
    }
});

// ─── The September case, end to end ─────────────────────────────────────────

it('posts a leaver\'s part-month cut as less wage, and nothing to 2140', function () {
    (new SalaryComponentSeeder)->run();

    $user = User::factory()->create();
    $user->businesses()->attach($this->business->id);

    $period = PayrollPeriod::forMonth(2026, 9);

    // Munna: 12,000 a month, on the books for 5 of the 30 divisor days.
    $leaver = Employee::create([
        'employee_code' => 'BDG-000021', 'first_name' => 'Munna', 'last_name' => 'Leaver',
        'employment_type' => 'permanent', 'status' => 'separated',
        'joining_date' => '2025-01-01',
        'separation_date' => $period->start_date->copy()->addDays(4)->toDateString(),
    ]);
    EmployeeAssignment::create([
        'employee_id' => $leaver->id,
        'office_id' => Office::create(['name' => 'Head Office', 'is_active' => true])->id,
        'is_primary' => true,
        'effective_from' => '2025-01-01',
    ]);
    app(SaveSalaryStructure::class)->handle($leaver, '2025-01-01', [
        ['salary_component_id' => SalaryComponent::where('code', 'basic')->sole()->id, 'value' => 12000],
    ], 'Munna');

    // The real engine, so the line is the one production writes.
    $context = app(PayrollContextBuilder::class)->build($leaver->fresh(), $period, PayrollConfig::defaults());
    $outcome = app(PayrollEngine::class)->process($context);
    $cut = collect($outcome->lines)->firstWhere('code', 'proration');

    expect($cut)->not->toBeNull()
        ->and($cut->amount)->toBe(10000.0)
        ->and($outcome->net)->toBe(2000.0);

    $run = PayrollRun::create([
        'business_id' => $this->business->id, 'payroll_period_id' => $period->id,
        'version' => 1, 'status' => PayrollStatus::Reviewed,
    ]);
    $payslip = $run->payslips()->create([
        'employee_id' => $leaver->id,
        'gross_salary' => $outcome->grossSalary, 'total_earnings' => $outcome->totalEarnings,
        'total_deductions' => $outcome->totalDeductions, 'net' => $outcome->net,
    ]);

    foreach ($outcome->lines as $index => $line) {
        $payslip->lines()->create([
            'code' => $line->code, 'label' => $line->label, 'type' => $line->type,
            'amount' => $line->amount, 'formula' => $line->formula, 'source' => $line->source,
            'sequence' => $index,
        ]);
    }

    app(TransitionPayrollRun::class)->markApproved($run->fresh(), $user);

    $journal = Journal::query()->where('source_type', $run->getMorphClass())->where('source_id', $run->id)->sole();
    $on = fn (string $slug) => JournalLine::query()->where('journal_id', $journal->id)
        ->where('account_id', Account::bySlug($slug)->id);

    expect($journal->balance()->isZero())->toBeTrue()
        // 12,000 earned less the 10,000 cut: the wage cost is what was paid.
        ->and((int) $on('salaries_expense')->sum('amount_minor'))->toBe(200000)
        ->and((int) $on('salaries_payable')->sum('amount_minor'))->toBe(-200000)
        ->and($on('employee_deductions_payable')->exists())->toBeFalse();
});

// ─── The live data migration ────────────────────────────────────────────────

describe('the 15 Oct 2026 data migration', function () {
    beforeEach(function () {
        $this->migration = require base_path('app/Modules/Hrm/database/migrations/2026_10_15_100000_payroll_deductions_that_are_less_wage_are_mapped.php');

        // Production as it stood on 15 Oct 2026: none of the new keys.
        DB::table('account_mappings')
            ->whereIn('key', ['payroll.deduction.proration', 'payroll.deduction.recovery'])
            ->delete();

        $this->keyCount = fn (string $key, ?int $business = null) => DB::table('account_mappings')
            ->where('key', $key)->where('business_id', $business ?? $this->business->id)->count();
    });

    it('adds the missing rows from the start of time, and a re-run adds nothing', function () {
        $before = DB::table('account_mappings')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

        $this->migration->up();
        $this->migration->up();

        $wages = Account::bySlug('salaries_expense');

        foreach (['proration', 'recovery'] as $code) {
            expect(($this->keyCount)("payroll.deduction.{$code}"))->toBe(1)
                ->and(AccountMapping::resolve("payroll.deduction.{$code}", Carbon::parse('2026-09-30'))->id)->toBe($wages->id)
                // ⚠ Far enough back to re-post September, as the seeder is.
                ->and(AccountMapping::resolve("payroll.deduction.{$code}", Carbon::parse('2000-01-01'))->id)->toBe($wages->id);
        }

        // Nothing that was there moved.
        $after = DB::table('account_mappings')->whereIn('id', array_column($before, 'id'))->orderBy('id')->get()
            ->map(fn ($row) => (array) $row)->all();
        expect($after)->toBe($before);
    });

    it('leaves a key the accountant already mapped exactly as it is', function () {
        $chosen = Account::bySlug('employee_advances');
        AccountMapping::create([
            'key' => 'payroll.deduction.recovery', 'account_id' => $chosen->id,
            'effective_from' => '2026-10-01', 'note' => 'Chosen by hand',
        ]);

        $this->migration->up();

        expect(($this->keyCount)('payroll.deduction.recovery'))->toBe(1)
            ->and(AccountMapping::resolve('payroll.deduction.recovery', Carbon::parse('2026-10-15'))->id)->toBe($chosen->id)
            ->and(($this->keyCount)('payroll.deduction.proration'))->toBe(1);
    });

    it('does not touch a business that has no payroll mappings', function () {
        $other = Business::create(['name' => 'No Payroll Co', 'slug' => 'no-payroll-co']);

        $this->migration->up();

        expect(DB::table('account_mappings')->where('business_id', $other->id)->count())->toBe(0)
            ->and(($this->keyCount)('payroll.deduction.proration'))->toBe(1);
    });

    it('finds the wage head by its system slug, never by its code', function () {
        // The chart is shared and its codes have been renumbered before (the
        // master expense chart of 5 Sep 2026); the slug is what stays put.
        DB::table('accounts')->where('system_slug', 'salaries_expense')->update(['code' => '6199']);

        $this->migration->up();

        expect(AccountMapping::resolve('payroll.deduction.proration', Carbon::parse('2026-09-30'))->code)->toBe('6199');
    });

    it('adds nothing, and does not fail, when the wage head has no slug to find it by', function () {
        $before = DB::table('account_mappings')->count();
        DB::table('accounts')->where('system_slug', 'salaries_expense')->update(['system_slug' => null]);

        $this->migration->up();

        expect(DB::table('account_mappings')->count())->toBe($before);
    });
});
