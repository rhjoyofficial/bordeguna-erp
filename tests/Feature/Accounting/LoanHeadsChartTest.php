<?php

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Hrm\database\seeders\PayrollAccountMappingSeeder;
use App\Modules\Hrm\Models\Employee;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The loan heads (loans plan v2, phase 1, and the ChatGPT confirmation of 14 Oct
 * 2026). See the migration 2026_10_14_990200_loan_heads_join_the_chart.
 *
 * ⚠ The properties worth pinning: 1170 Employee Advances is left EXACTLY as it
 * was (row, slug, payroll mappings, postings); every new head sits where the
 * plan puts it with a slug the software finds it by; 2210 and 2220 keep their
 * code and name and gain slugs; a second run changes nothing; a code held by
 * something else stops it with a sentence; and a fresh install seeds the same.
 */
const LOAN_HEADS_MIGRATION = 'app/Modules/Accounting/database/migrations/2026_10_14_990200_loan_heads_join_the_chart.php';

/** code => [name, parent code, postable, slug] */
const LOAN_HEADS = [
    '1175' => ['Loans & Advances to Others', '1100', false, 'loans_and_advances_to_others'],
    '11751' => ['Loans to Other Parties', '1175', true, 'loans_to_other_parties'],
    '11752' => ['Loans to Related Parties', '1175', true, 'loans_to_related_parties'],
    '11753' => ['Interest Receivable', '1175', true, 'interest_receivable'],
    '11754' => ['Current Portion of Long-term Loans Given', '1175', true, 'current_portion_of_loans_given'],
    '1270' => ['Long-term Loans Given', '1200', true, 'long_term_loans_given'],
    '2116' => ['Bank Overdraft / Cash Credit', '2100', true, 'bank_overdraft'],
    '2126' => ['Short-term Loans Payable', '2100', true, 'short_term_loans_payable'],
    '2127' => ['Interest Payable', '2100', true, 'interest_payable'],
    '2128' => ['Current Portion of Long-term Loans', '2100', true, 'current_portion_of_long_term_loans'],
    '2210' => ['Bank Loans', '2200', true, 'bank_loans'],
    '2220' => ['Loans from Owners', '2200', true, 'owner_loans'],
    '2230' => ['Loans from Other Parties', '2200', true, 'loans_from_other_parties'],
];

const LOAN_HEADS_NEW = ['1175', '11751', '11752', '11753', '11754', '1270', '2116', '2126', '2127', '2128', '2230'];

beforeEach(function () {
    Carbon::setTestNow('2026-10-14 10:00:00');

    $this->business = Business::create(['name' => 'Loans Co', 'slug' => 'loans-co', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($this->business->id);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);

    $this->migration = fn () => require base_path(LOAN_HEADS_MIGRATION);

    // The chart as production had it on 14 Oct 2026: none of the new heads,
    // 2210 Bank Loans with no slug, 2220 Loans from Owners slugged
    // `owner_loans`, payroll mapped onto 1170, and an advance posted on 1170.
    $this->productionChart = function (): void {
        $this->seed(ChartOfAccountsSeeder::class);

        // Heads first, then the 1175 heading they sit under.
        DB::table('accounts')->whereIn('code', LOAN_HEADS_NEW)->where('is_postable', true)->delete();
        DB::table('accounts')->whereIn('code', LOAN_HEADS_NEW)->delete();
        DB::table('accounts')->where('code', '2210')->update(['system_slug' => null]);
        DB::table('accounts')->where('code', '2220')->update(['note' => null]);

        $this->seed(PayrollAccountMappingSeeder::class);
        TenantScope::enter($this->business->id);

        $musa = Employee::create(['employee_code' => 'BDG-000002', 'first_name' => 'Musa', 'last_name' => 'Islam', 'employment_type' => 'permanent', 'status' => 'active']);

        app(PostJournal::class)->handle(new JournalDraft(
            date: Carbon::parse('2026-10-01'),
            lines: [
                JournalLineDraft::debit(Account::bySlug('employee_advances')->id, Money::ofTaka('5000'), null, $musa),
                JournalLineDraft::credit(Account::where('code', '1180')->sole()->id, Money::ofTaka('5000')),
            ],
        ), $this->owner);
    };

    $this->row = fn (string $code) => (array) DB::table('accounts')->where('code', $code)->first();

    // The whole chart as comparable rows, for "nothing changed" checks.
    $this->chart = fn () => DB::table('accounts as a')
        ->leftJoin('accounts as p', 'p.id', '=', 'a.parent_id')
        ->orderBy('a.code')
        ->get(['a.code', 'a.name', 'a.type', 'a.is_postable', 'a.is_active', 'a.system_slug', 'a.note', 'p.code as parent_code'])
        ->map(fn ($row) => (array) $row)
        ->all();
});

afterEach(fn () => Carbon::setTestNow());

it('plants every loan head where the plan puts it, each with its slug', function () {
    ($this->productionChart)();
    ($this->migration)()->up();

    foreach (LOAN_HEADS as $code => [$name, $parentCode, $postable, $slug]) {
        $head = Account::where('code', (string) $code)->sole();
        $expectedType = str_starts_with((string) $code, '1') ? AccountType::Asset : AccountType::Liability;

        expect($head->name)->toBe($name)
            ->and($head->type)->toBe($expectedType)
            ->and($head->is_postable)->toBe($postable)
            ->and($head->is_active)->toBeTrue()
            ->and($head->system_slug)->toBe($slug)
            ->and($head->parent->code)->toBe($parentCode)
            ->and(Account::bySlug($slug)->id)->toBe($head->id);
    }

    expect(Account::where('code', '2220')->sole()->note)->toContain("Owner's Capital 3100");
});

it('leaves 1170 Employee Advances, its payroll mappings and its postings exactly as they were', function () {
    ($this->productionChart)();

    $employeeAdvances = ($this->row)('1170');
    $mappings = DB::table('account_mappings')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    $lines = DB::table('journal_lines')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    $under1170 = DB::table('accounts')->where('parent_id', $employeeAdvances['id'])->pluck('id')->all();

    ($this->migration)()->up();

    expect(($this->row)('1170'))->toBe($employeeAdvances)
        ->and(DB::table('account_mappings')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all())->toBe($mappings)
        ->and(DB::table('journal_lines')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all())->toBe($lines)
        ->and(DB::table('accounts')->where('parent_id', $employeeAdvances['id'])->pluck('id')->all())->toBe($under1170)
        ->and(AccountMapping::resolve('payroll.employee_advances', Carbon::today())->id)->toBe($employeeAdvances['id']);
});

it('gives 2210 and 2220 their slugs without changing their code, name or id', function () {
    ($this->productionChart)();
    $bankLoans = ($this->row)('2210');
    $ownerLoans = ($this->row)('2220');

    ($this->migration)()->up();

    expect(Account::bySlug('bank_loans')->id)->toBe($bankLoans['id'])
        ->and(Account::bySlug('bank_loans')->name)->toBe('Bank Loans')
        ->and(Account::bySlug('owner_loans')->id)->toBe($ownerLoans['id'])
        ->and(Account::bySlug('owner_loans')->name)->toBe('Loans from Owners');
});

it('writes each change to the activity log, and one system entry for the whole change', function () {
    ($this->productionChart)();
    ($this->migration)()->up();

    $created = DB::table('activity_log')->where('subject_type', Account::class)->where('description', 'created')
        ->where('properties', 'like', '%loan heads%')->count();
    $bankLoans = DB::table('activity_log')->where('subject_type', Account::class)->where('subject_id', Account::bySlug('bank_loans')->id)
        ->where('properties', 'like', '%loan heads%')->sole();

    expect($created)->toBe(count(LOAN_HEADS_NEW))
        ->and(json_decode($bankLoans->properties, true)['old'])->toBe(['system_slug' => null])
        ->and(DB::table('activity_log')->where('log_name', 'system')->where('description', 'like', '%loan heads%')->count())->toBe(1);
});

it('changes nothing when it runs a second time', function () {
    ($this->productionChart)();
    ($this->migration)()->up();

    $chart = ($this->chart)();
    $logged = DB::table('activity_log')->count();

    ($this->migration)()->up();

    expect(($this->chart)())->toBe($chart)
        ->and(DB::table('activity_log')->count())->toBe($logged);
});

it('builds the same chart on a fresh install as on an upgraded one', function () {
    ($this->productionChart)();
    ($this->migration)()->up();
    $upgraded = ($this->chart)();

    DB::table('account_mappings')->delete();
    DB::table('journal_lines')->delete();
    DB::table('journals')->delete();
    DB::table('employees')->update(['account_id' => null]);
    DB::table('accounts')->update(['parent_id' => null]);
    DB::table('accounts')->delete();
    $this->seed(ChartOfAccountsSeeder::class);

    // The loan heads, the headings they sit under, and 1170 beside them. (Other
    // rows differ for reasons of their own: an older migration's note on 6478.)
    $codes = [...array_map('strval', array_keys(LOAN_HEADS)), '1100', '1170', '1200', '2100', '2200'];
    $loanRows = fn (array $chart) => array_values(array_filter($chart, fn (array $row) => in_array($row['code'], $codes, true)));

    expect($loanRows(($this->chart)()))->toBe($loanRows($upgraded))
        ->and(count($loanRows($upgraded)))->toBe(count($codes));
});

it('does nothing on a chart the seeder has not planted yet', function () {
    expect(Account::where('code', '1000')->exists())->toBeFalse();
    $accounts = Account::count();

    ($this->migration)()->up();

    expect(Account::count())->toBe($accounts);
});

it('stops with a sentence when a loan code is held by another head, and changes nothing', function () {
    ($this->productionChart)();
    Account::create(['code' => '2116', 'name' => 'Old Suspense', 'type' => AccountType::Liability, 'parent_id' => Account::where('code', '2100')->value('id')]);
    $chart = ($this->chart)();

    expect(fn () => ($this->migration)()->up())->toThrow(RuntimeException::class, 'code 2116 is already used by "Old Suspense"');

    expect(($this->chart)())->toBe($chart);
});

it('stops with a sentence when 2210 already carries another slug', function () {
    ($this->productionChart)();
    DB::table('accounts')->where('code', '2210')->update(['system_slug' => 'something_else']);

    expect(fn () => ($this->migration)()->up())->toThrow(RuntimeException::class, '2210 Bank Loans already carries the slug something_else');

    expect(Account::where('code', '1175')->exists())->toBeFalse();
});

it('accepts a head somebody already made with the same name and shape', function () {
    ($this->productionChart)();
    $made = Account::create(['code' => '2127', 'name' => 'Interest payable', 'type' => AccountType::Liability, 'parent_id' => Account::where('code', '2100')->value('id')]);

    ($this->migration)()->up();

    expect(Account::bySlug('interest_payable')->id)->toBe($made->id)
        ->and(Account::find($made->id)->name)->toBe('Interest payable');
});

it('rolls back while the new heads are unused, and refuses once one has postings', function () {
    ($this->productionChart)();
    $before = ($this->chart)();

    ($this->migration)()->up();
    ($this->migration)()->down();

    expect(($this->chart)())->toBe($before);

    ($this->migration)()->up();
    DB::table('journal_lines')->insert([
        'journal_id' => DB::table('journals')->value('id'), 'business_id' => $this->business->id, 'line_no' => 9,
        'account_id' => Account::bySlug('bank_overdraft')->id, 'amount_minor' => 100, 'created_at' => now(),
    ]);

    expect(fn () => ($this->migration)()->down())->toThrow(RuntimeException::class, 'already have postings: 2116');
});
