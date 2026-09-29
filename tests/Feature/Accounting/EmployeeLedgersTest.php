<?php

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Actions\PostVoucher;
use App\Modules\Accounting\Actions\SaveVoucherDraft;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Enums\VoucherType;
use App\Modules\Accounting\Exceptions\JournalException;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FinancePolicy;
use App\Modules\Accounting\Models\Voucher;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\SubledgerReconciliation;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Hrm\database\seeders\PayrollAccountMappingSeeder;
use App\Modules\Hrm\Models\Employee;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * "A ledger head for every employee" (the accountant, 26 Sep 2026), kept as
 * one control head plus the employee on every line (Support\PartyLedgers).
 */
beforeEach(function () {
    Carbon::setTestNow('2026-09-26 10:00:00');

    $this->business = Business::create(['name' => 'Staff Co', 'slug' => 'staff-co', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($this->business->id);
    // These tests pay from empty test accounts to check the posting shape;
    // the balance guard has its own tests in FinancePolicyTest.
    FinancePolicy::create(['business_id' => $this->business->id, 'refuse_negative_money' => false]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(PayrollAccountMappingSeeder::class);
    TenantScope::enter($this->business->id);

    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);

    Permission::findOrCreate('accounting.view', 'web');
    $role = Role::firstOrCreate(['name' => 'Books', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    $role->givePermissionTo('accounting.view');
    $this->user->assignRole($role);

    $this->bank = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'BRAC Bank', 'type' => 'bank', 'is_active' => true,
        'account_id' => Account::bySlug('cash_at_bank')->id,
    ]);

    $this->musa = Employee::create(['employee_code' => 'BDG-000002', 'first_name' => 'Musa', 'last_name' => 'Islam', 'employment_type' => 'permanent', 'status' => 'active']);
    $this->joy = Employee::create(['employee_code' => 'BDG-000001', 'first_name' => 'Rakibul', 'last_name' => 'Joy', 'employment_type' => 'permanent', 'status' => 'active']);

    $this->id = fn (string $code) => Account::where('code', $code)->sole()->id;
    $this->draft = fn (VoucherType $type, array $data) => app(SaveVoucherDraft::class)->handle($type, $data + ['voucher_date' => '2026-09-26'], $this->user);
    $this->post = fn (Voucher $voucher) => app(PostVoucher::class)->handle($voucher, $this->user);
    $this->partyBalance = fn (string $code, Employee $who) => (int) DB::table('journal_lines')
        ->where('account_id', ($this->id)($code))->where('party_type', $who->getMorphClass())->where('party_id', $who->id)->sum('amount_minor');
});

afterEach(fn () => Carbon::setTestNow());

it('pays an advance to an employee on 1170 with the employee on the line', function () {
    $voucher = ($this->post)(($this->draft)(VoucherType::Payment, [
        'money_account_id' => $this->bank->id,
        'lines' => [['account_id' => ($this->id)('1170'), 'amount' => '10000', 'party_id' => $this->musa->id]],
    ]));

    $line = $voucher->journal->lines->firstWhere('account_id', ($this->id)('1170'));

    expect($line->party_type)->toBe($this->musa->getMorphClass())
        ->and($line->party_id)->toBe($this->musa->id)
        ->and(($this->partyBalance)('1170', $this->musa))->toBe(1000000)
        ->and(($this->partyBalance)('1170', $this->joy))->toBe(0);
});

it('refuses to post an employee line with nobody named, and names the missing employee', function () {
    $draft = ($this->draft)(VoucherType::Payment, [
        'money_account_id' => $this->bank->id,
        'lines' => [['account_id' => ($this->id)('1170'), 'amount' => '10000']],
    ]);

    expect(fn () => ($this->post)($draft))->toThrow(ValidationException::class, 'which employee');
});

it('settles an advance against a held deduction by journal voucher, both lines naming the employee', function () {
    ($this->post)(($this->draft)(VoucherType::Payment, [
        'money_account_id' => $this->bank->id,
        'lines' => [['account_id' => ($this->id)('1170'), 'amount' => '3000', 'party_id' => $this->musa->id]],
    ]));

    $jv = ($this->post)(($this->draft)(VoucherType::Journal, [
        'lines' => [
            ['account_id' => ($this->id)('2140'), 'side' => 'debit', 'amount' => '3000', 'party_id' => $this->musa->id],
            ['account_id' => ($this->id)('1170'), 'side' => 'credit', 'amount' => '3000', 'party_id' => $this->musa->id],
        ],
    ]));

    expect($jv->journal->lines->every(fn ($line) => $line->party_id === $this->musa->id))->toBeTrue()
        ->and(($this->partyBalance)('1170', $this->musa))->toBe(0)
        ->and(($this->partyBalance)('2140', $this->musa))->toBe(300000);
});

it('keeps Salaries Payable off the vouchers: salaries are paid from Payroll', function () {
    expect(fn () => ($this->draft)(VoucherType::Payment, [
        'money_account_id' => $this->bank->id,
        'lines' => [['account_id' => ($this->id)('2130'), 'amount' => '5000', 'party_id' => $this->musa->id]],
    ]))->toThrow(ValidationException::class);
});

it('refuses ANY writer that posts to an employee ledger without the employee', function () {
    $draft = new JournalDraft(
        date: Carbon::parse('2026-09-26'),
        lines: [
            JournalLineDraft::debit(($this->id)('1170'), Money::ofTaka('500'), 'bare advance'),
            JournalLineDraft::credit(($this->id)('6051'), Money::ofTaka('500'), 'whatever'),
        ],
        memo: 'a raw journal with no party',
    );

    expect(fn () => app(PostJournal::class)->handle($draft, $this->user))
        ->toThrow(JournalException::class, 'names the employee');

    // And the wrong KIND of party is as bad as none.
    $customerOnEmployeeHead = new JournalDraft(
        date: Carbon::parse('2026-09-26'),
        lines: [
            JournalLineDraft::debit(($this->id)('1170'), Money::ofTaka('500'), 'advance', $this->bank),
            JournalLineDraft::credit(($this->id)('6051'), Money::ofTaka('500'), 'whatever'),
        ],
        memo: 'a money account is not an employee',
    );

    expect(fn () => app(PostJournal::class)->handle($customerOnEmployeeHead, $this->user))->toThrow(JournalException::class);
});

it('shows one ledger per head on the employee statement, with the running balance', function () {
    ($this->post)(($this->draft)(VoucherType::Payment, [
        'money_account_id' => $this->bank->id,
        'lines' => [['account_id' => ($this->id)('1170'), 'amount' => '10000', 'party_id' => $this->musa->id]],
    ]));
    ($this->post)(($this->draft)(VoucherType::Receipt, [
        'money_account_id' => $this->bank->id,
        'lines' => [['account_id' => ($this->id)('1170'), 'amount' => '4000', 'party_id' => $this->musa->id]],
    ]));
    ($this->post)(($this->draft)(VoucherType::Payment, [
        'money_account_id' => $this->bank->id,
        'lines' => [['account_id' => ($this->id)('1170'), 'amount' => '999', 'party_id' => $this->joy->id]],
    ]));

    $page = $this->actingAs($this->user)
        ->get('/accounting/employee-statement?employee='.$this->musa->id.'&from=2026-07-01&to=2026-09-30')
        ->assertOk()
        ->viewData('page')['props'];

    $advances = collect($page['heads'])->firstWhere('slug', 'employee_advances');

    expect($page['employee']['name'])->toBe('Musa Islam')
        ->and(collect($page['heads'])->pluck('slug')->all())->toBe(['employee_advances', 'salaries_payable', 'employee_deductions_payable', 'employee_reimbursements_payable'])
        ->and($advances['closing'])->toBe('6,000.00')
        ->and($advances['closing_side'])->toBe('debit')
        ->and(collect($advances['lines'])->pluck('balance')->all())->toBe(['10,000.00', '6,000.00'])
        ->and($advances['lines'][0]['source'])->not->toBeNull()
        ->and(collect($page['heads'])->firstWhere('slug', 'salaries_payable')['closing'])->toBe('0.00');

    // Joy's 999 never leaks into Musa's statement.
    expect(collect($advances['lines'])->pluck('debit')->filter()->all())->toBe(['10,000.00']);

    $this->actingAs($this->user)->get('/accounting/employee-statement')->assertOk();
});

it('flags a legacy line on an employee ledger that names nobody on the reconciliation screen', function () {
    // Bypass PostJournal on purpose: this is what a posting from before the
    // contract looks like, and the screen must say so rather than hide it.
    $journalId = DB::table('journals')->insertGetId([
        'business_id' => $this->business->id, 'journal_number' => 'JV-LEGACY-0001', 'journal_date' => '2026-09-01',
        'memo' => 'old payroll', 'posted_by' => $this->user->id, 'posted_at' => now(), 'created_at' => now(),
    ]);
    DB::table('journal_lines')->insert([
        ['journal_id' => $journalId, 'business_id' => $this->business->id, 'line_no' => 1, 'account_id' => ($this->id)('2140'), 'amount_minor' => -250000, 'memo' => null],
        ['journal_id' => $journalId, 'business_id' => $this->business->id, 'line_no' => 2, 'account_id' => ($this->id)('6131'), 'amount_minor' => 250000, 'memo' => null],
    ]);

    $result = SubledgerReconciliation::reconcileCurrentBusiness();

    expect(collect($result['problems'])->first(fn (string $p) => str_contains($p, '2140') && str_contains($p, '2,500.00') && str_contains($p, 'no employee')))->not->toBeNull();
});
