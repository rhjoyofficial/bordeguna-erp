<?php

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Actions\ReverseJournal;
use App\Modules\Accounting\Actions\SaveCounterparty;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Enums\VoucherStatus;
use App\Modules\Accounting\Exceptions\JournalException;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Counterparty;
use App\Modules\Accounting\Models\FinancePolicy;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\Voucher;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\PartyLedgers;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Hrm\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Every line on a loan head names its counterparty (loans plan v2, phase 1),
 * the contract 1170 has always kept with the employee: checked in PostJournal
 * for every writer, and met as a sentence on the voucher and journal screens.
 */
const LOAN_PARTY_SLUGS = [
    'loans_to_other_parties', 'loans_to_related_parties', 'interest_receivable', 'current_portion_of_loans_given',
    'long_term_loans_given', 'bank_overdraft', 'short_term_loans_payable', 'interest_payable',
    'current_portion_of_long_term_loans', 'bank_loans', 'owner_loans', 'loans_from_other_parties',
];

beforeEach(function () {
    Carbon::setTestNow('2026-10-14 10:00:00');

    $this->business = Business::create(['name' => 'Bor de Guna', 'slug' => 'bor-de-guna', 'fiscal_year_start_month' => 7]);
    $this->laladia = Business::create(['name' => 'LaLaDia', 'slug' => 'laladia', 'fiscal_year_start_month' => 7]);
    FinancePolicy::create(['business_id' => $this->business->id, 'refuse_negative_money' => false, 'payment_second_approval_above_minor' => 0]);
    TenantScope::enter($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);
    $this->seed(ChartOfAccountsSeeder::class);

    $permissions = ['accounting.view', 'accounting.voucher.prepare', 'accounting.voucher.post', 'accounting.journal.post'];
    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'web');
    }
    $role = Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    $role->givePermissionTo($permissions);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole($role);

    $this->bank = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'BRAC Bank', 'type' => 'bank', 'is_active' => true,
        'account_id' => Account::bySlug('cash_at_bank')->id,
    ]);

    $save = fn (array $data) => app(SaveCounterparty::class)->handle($data, $this->owner);
    $this->padma = $save(['legal_name' => 'Padma Finance PLC', 'kind' => 'nbfi']);
    // The owner's own company (owner's answer, 14 Oct 2026): a related party.
    $this->tycoon = $save(['legal_name' => 'Tycoon - Hi-Tech Park', 'kind' => 'company', 'related_party' => true, 'relationship' => "The owner's company"]);

    $this->head = fn (string $slug) => Account::bySlug($slug);
    $this->journal = fn (string $slug, ?Model $party, string $amount = '1000') => app(PostJournal::class)->handle(new JournalDraft(
        date: Carbon::parse('2026-10-01'),
        lines: [
            JournalLineDraft::debit(($this->head)($slug)->id, Money::ofTaka($amount), null, $party),
            JournalLineDraft::credit(Account::where('code', '1180')->sole()->id, Money::ofTaka($amount)),
        ],
    ), $this->owner);
    $this->refusal = function (callable $act): string {
        try {
            $act();
        } catch (JournalException $e) {
            return $e->getMessage();
        } catch (ValidationException $e) {
            return (string) collect($e->errors())->flatten()->first();
        }

        return '';
    };
});

afterEach(fn () => Carbon::setTestNow());

it('keeps every loan head as one ledger per counterparty', function () {
    foreach (LOAN_PARTY_SLUGS as $slug) {
        expect(PartyLedgers::partyClassFor(($this->head)($slug)))->toBe(Counterparty::class, $slug)
            ->and(PartyLedgers::voucherPickable())->toHaveKey($slug);
    }

    // 1170 keeps its employee, exactly as before.
    expect(PartyLedgers::partyClassFor(Account::bySlug('employee_advances')))->toBe(Employee::class)
        ->and(PartyLedgers::noun(Counterparty::class))->toBe('counterparty')
        ->and(PartyLedgers::label($this->padma))->toBe('Padma Finance PLC');
});

it('refuses a line on any loan head that names nobody, whoever posts it', function (string $slug) {
    expect(($this->refusal)(fn () => ($this->journal)($slug, null)))
        ->toContain('keeps one ledger per counterparty, so every posting there names the counterparty. This one names nobody.');
})->with(LOAN_PARTY_SLUGS);

it('refuses a loan line that names an employee instead of a counterparty', function () {
    $musa = Employee::create(['employee_code' => 'BDG-000002', 'first_name' => 'Musa', 'last_name' => 'Islam', 'employment_type' => 'permanent', 'status' => 'active']);

    expect(($this->refusal)(fn () => ($this->journal)('bank_loans', $musa)))->toContain('names nobody');
});

it('posts a loan line that names its counterparty, and the line carries it', function (string $slug) {
    $party = $slug === 'loans_to_related_parties' ? $this->tycoon : $this->padma;

    $journal = ($this->journal)($slug, $party);
    $line = $journal->lines->firstWhere('account_id', ($this->head)($slug)->id);

    expect($line->party_type)->toBe(Counterparty::class)->and($line->party_id)->toBe($party->id);
})->with(LOAN_PARTY_SLUGS);

it('keeps 11752 for related parties and 11751 for everyone else, saying which head to use', function () {
    expect(($this->refusal)(fn () => ($this->journal)('loans_to_related_parties', $this->padma)))
        ->toContain('11752 - Loans to Related Parties is only for a related party, and Padma Finance PLC is not marked related')
        ->toContain('11751 - Loans to Other Parties');

    expect(($this->refusal)(fn () => ($this->journal)('loans_to_other_parties', $this->tycoon)))
        ->toContain('Tycoon - Hi-Tech Park is a related party')
        ->toContain('goes on 11752 - Loans to Related Parties, not 11751 - Loans to Other Parties');
});

it('refuses a counterparty that is one of our own businesses on every loan head', function () {
    // Made around the screen (the screen refuses it), as an old row might be.
    $internal = Counterparty::create(['legal_name' => 'Somebody', 'kind' => 'company']);
    DB::table('counterparties')->where('id', $internal->id)->update(['legal_name' => 'LaLaDia Ltd']);

    expect(($this->refusal)(fn () => ($this->journal)('loans_from_other_parties', $internal->fresh())))
        ->toContain("one of the group's own businesses")
        ->toContain('Inter-business transfers');
});

it('refuses a deactivated counterparty on a new posting', function () {
    $this->padma->forceFill(['is_active' => false])->save();

    expect(($this->refusal)(fn () => ($this->journal)('bank_loans', $this->padma)))
        ->toContain('Padma Finance PLC is deactivated');
});

it('still reverses an old loan journal after the counterparty changed', function () {
    $journal = ($this->journal)('bank_loans', $this->padma);
    $this->padma->forceFill(['is_active' => false])->save();

    $reversal = app(ReverseJournal::class)->handle($journal, $this->owner);

    expect($reversal->lines->firstWhere('account_id', ($this->head)('bank_loans')->id)->party_id)->toBe($this->padma->id);
});

it('refuses clearing the related mark while 11752 holds a balance, and marking related while 11751 does', function () {
    ($this->journal)('loans_to_related_parties', $this->tycoon, '300000');
    ($this->journal)('loans_to_other_parties', $this->padma, '5000');

    $change = fn (Counterparty $party, bool $related) => app(SaveCounterparty::class)->handle([
        'legal_name' => $party->legal_name, 'kind' => $party->kind->value, 'related_party' => $related, 'relationship' => $related ? 'Owner holds 60%' : null,
    ], $this->owner, $party);

    expect(($this->refusal)(fn () => $change($this->tycoon, false)))
        ->toContain('Tycoon - Hi-Tech Park still has 300,000.00 on 11752 - Loans to Related Parties')
        ->and(($this->refusal)(fn () => $change($this->padma, true)))
        ->toContain('Padma Finance PLC still has 5,000.00 on 11751 - Loans to Other Parties')
        ->and($this->tycoon->fresh()->related_party)->toBeTrue()
        ->and($this->padma->fresh()->related_party)->toBeFalse();
});

// ─── The voucher screens ────────────────────────────────────────────────────

it('refuses a loan line on a voucher with no counterparty, with a sentence on the line', function () {
    $this->actingAs($this->owner)
        ->post('/accounting/vouchers/receipt', [
            'voucher_date' => '2026-10-14',
            'money_account_id' => $this->bank->id,
            'lines' => [['account_id' => ($this->head)('bank_loans')->id, 'amount' => '500000']],
            'post' => true,
        ])
        ->assertSessionHasErrors(['lines.0.party_id' => 'Say which counterparty this 2210 - Bank Loans line is for.']);

    expect(Voucher::sole()->status)->toBe(VoucherStatus::Draft)
        ->and(Journal::count())->toBe(0);
});

it('posts a bank loan received on a Receive voucher with its counterparty', function () {
    $this->actingAs($this->owner)
        ->post('/accounting/vouchers/receipt', [
            'voucher_date' => '2026-10-14',
            'money_account_id' => $this->bank->id,
            'lines' => [['account_id' => ($this->head)('bank_loans')->id, 'amount' => '500000', 'party_id' => $this->padma->id]],
            'post' => true,
        ])
        ->assertSessionHasNoErrors();

    $line = Voucher::sole()->journal->lines->firstWhere('account_id', ($this->head)('bank_loans')->id);

    expect($line->party_type)->toBe(Counterparty::class)
        ->and($line->party_id)->toBe($this->padma->id)
        ->and($line->amount_minor)->toBe(-50_000_000);
});

it('books the related-party loan on 11752 by Payment voucher, and refuses it on 11751', function () {
    // The owner's case (14 Oct 2026): 300,000 lent to his own company on 25 Aug.
    $payload = fn (string $slug) => [
        'voucher_date' => '2026-08-25',
        'money_account_id' => $this->bank->id,
        'lines' => [['account_id' => ($this->head)($slug)->id, 'amount' => '300000', 'party_id' => $this->tycoon->id]],
        'post' => true,
    ];

    $this->actingAs($this->owner)->post('/accounting/vouchers/payment', $payload('loans_to_other_parties'))
        ->assertSessionHasErrors(['lines.0.party_id']);
    expect(Voucher::count())->toBe(0);

    $this->actingAs($this->owner)->post('/accounting/vouchers/payment', $payload('loans_to_related_parties'))
        ->assertSessionHasNoErrors();

    expect(Voucher::sole()->status)->toBe(VoucherStatus::Posted)
        ->and((int) DB::table('journal_lines')->where('account_id', ($this->head)('loans_to_related_parties')->id)
            ->where('party_id', $this->tycoon->id)->sum('amount_minor'))->toBe(30_000_000);
});

it('moves a balance between two loan heads on a Journal voucher, each line naming its counterparty', function () {
    ($this->journal)('long_term_loans_given', $this->padma, '120000');

    $this->actingAs($this->owner)->post('/accounting/vouchers/journal', [
        'voucher_date' => '2026-10-14',
        'narration' => 'Being the part due within 12 months',
        'lines' => [
            ['account_id' => ($this->head)('current_portion_of_loans_given')->id, 'side' => 'debit', 'amount' => '40000', 'party_id' => $this->padma->id],
            ['account_id' => ($this->head)('long_term_loans_given')->id, 'side' => 'credit', 'amount' => '40000', 'party_id' => $this->padma->id],
        ],
        'post' => true,
    ])->assertSessionHasNoErrors();

    expect(Voucher::sole()->status)->toBe(VoucherStatus::Posted);
});

it('offers the loan heads with a counterparty picker and the counterparties on the voucher form', function () {
    $this->actingAs($this->owner)->get('/accounting/vouchers/payment/create')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('accounting/vouchers/form')
            ->where('accounts', function ($accounts) {
                $byCode = collect($accounts)->keyBy(fn ($row) => explode(' - ', $row['label'])[0]);

                return $byCode['2210']['party'] === 'counterparty'
                    && $byCode['2210']['usable'] === true
                    && $byCode['2210']['loan_rule'] === 'loan'
                    && $byCode['2220']['loan_rule'] === 'owner_loan'
                    && $byCode['11752']['loan_rule'] === 'related_only'
                    && $byCode['11751']['loan_rule'] === 'not_related'
                    && $byCode['1170']['party'] === 'employee'
                    && $byCode['1170']['loan_rule'] === null;
            })
            ->where('counterparties', fn ($rows) => collect($rows)->pluck('label')->all() === ['Padma Finance PLC', 'Tycoon - Hi-Tech Park']
                && collect($rows)->firstWhere('id', $this->tycoon->id)['related'] === true));
});

it('refuses a missing counterparty on the manual journal screen with a sentence', function () {
    $this->actingAs($this->owner)->post(route('accounting.journals.store'), [
        'journal_date' => '2026-10-01',
        'journal_type' => 'opening_balance',
        'lines' => [
            ['account_id' => Account::bySlug('opening_balance_equity')->id, 'debit' => '200000'],
            ['account_id' => ($this->head)('loans_from_other_parties')->id, 'credit' => '200000'],
        ],
    ])->assertSessionHasErrors('lines');

    $this->actingAs($this->owner)->post(route('accounting.journals.store'), [
        'journal_date' => '2026-10-01',
        'journal_type' => 'opening_balance',
        'lines' => [
            ['account_id' => Account::bySlug('opening_balance_equity')->id, 'debit' => '200000'],
            ['account_id' => ($this->head)('loans_from_other_parties')->id, 'credit' => '200000', 'party_id' => $this->padma->id],
        ],
    ])->assertSessionHasNoErrors();

    expect(Journal::sole()->lines->firstWhere('account_id', ($this->head)('loans_from_other_parties')->id)->party_id)->toBe($this->padma->id);
});
