<?php

use App\Models\User;
use App\Modules\Accounting\Actions\PostVoucher;
use App\Modules\Accounting\Actions\ReverseVoucher;
use App\Modules\Accounting\Actions\SaveVoucherDraft;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Enums\VoucherStatus;
use App\Modules\Accounting\Enums\VoucherType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\Counterparty;
use App\Modules\Accounting\Models\FinancePolicy;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\Voucher;
use App\Modules\Accounting\Support\AmountInWords;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Models\Customer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Payment, Receive, Contra and Journal vouchers (the accountant, 19 Sep 2026).
 * One engine, four entry shapes; every one posts through PostJournal.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-09-19 10:00:00');

    $this->business = Business::create(['name' => 'Voucher Co', 'slug' => 'voucher-co', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($this->business->id);
    // These tests pay from empty test accounts to check the posting shape;
    // the balance guard has its own tests in FinancePolicyTest.
    FinancePolicy::create(['business_id' => $this->business->id, 'refuse_negative_money' => false]);
    $this->seed(ChartOfAccountsSeeder::class);

    $this->user = User::factory()->create();

    $this->bank = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'BRAC Bank', 'type' => 'bank', 'is_active' => true,
        'account_id' => Account::bySlug('cash_at_bank')->id,
    ]);
    $this->cash = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'Office Cash', 'type' => 'cash', 'is_active' => true,
        'account_id' => Account::bySlug('cash_in_hand')->id,
    ]);

    $this->id = fn (string $code) => Account::where('code', $code)->sole()->id;
    $this->balance = fn (string $code) => (int) DB::table('journal_lines')
        ->where('business_id', $this->business->id)->where('account_id', ($this->id)($code))->sum('amount_minor');

    $this->draft = fn (VoucherType $type, array $data) => app(SaveVoucherDraft::class)
        ->handle($type, $data + ['voucher_date' => '2026-09-19'], $this->user);
    $this->post = fn (Voucher $voucher) => app(PostVoucher::class)->handle($voucher, $this->user);
});

afterEach(fn () => Carbon::setTestNow());

// ─── A DRAFT TOUCHES NOTHING ────────────────────────────────────────────────

it('keeps a draft out of the ledger and gives it no number', function () {
    $voucher = ($this->draft)(VoucherType::Payment, [
        'money_account_id' => $this->bank->id,
        'lines' => [['account_id' => ($this->id)('6051'), 'amount' => '25000']],
    ]);

    expect($voucher->status)->toBe(VoucherStatus::Draft)
        ->and($voucher->voucher_number)->toBeNull()
        ->and($voucher->journal_id)->toBeNull()
        ->and(Journal::count())->toBe(0)
        ->and($voucher->total()->format())->toBe('25,000.00');
});

it('lets a draft be rewritten, and refuses to rewrite a posted voucher', function () {
    $voucher = ($this->draft)(VoucherType::Payment, [
        'money_account_id' => $this->bank->id,
        'lines' => [['account_id' => ($this->id)('6051'), 'amount' => '100']],
    ]);

    $voucher = app(SaveVoucherDraft::class)->handle(VoucherType::Payment, [
        'voucher_date' => '2026-09-19', 'money_account_id' => $this->cash->id,
        'lines' => [['account_id' => ($this->id)('6052'), 'amount' => '300'], ['account_id' => ($this->id)('6054'), 'amount' => '50']],
    ], $this->user, $voucher);

    expect($voucher->lines)->toHaveCount(2)->and($voucher->total()->format())->toBe('350.00');

    $posted = ($this->post)($voucher);

    expect(fn () => app(SaveVoucherDraft::class)->handle(VoucherType::Payment, ['voucher_date' => '2026-09-19'], $this->user, $posted))
        ->toThrow(ValidationException::class);
});

// ─── THE FOUR SHAPES ────────────────────────────────────────────────────────

it('posts a payment: debits each line, credits the money account once', function () {
    $voucher = ($this->post)(($this->draft)(VoucherType::Payment, [
        'money_account_id' => $this->bank->id,
        'counterparty' => 'Adabor landlord',
        'lines' => [
            ['account_id' => ($this->id)('6051'), 'amount' => '25000', 'narration' => 'September rent'],
            // ⭐ Not an expense: a loan repayment. The old expense voucher refused this.
            // Since 14 Oct 2026 it names the bank it repays (loans plan v2).
            ['account_id' => ($this->id)('2210'), 'amount' => '10000.50', 'party_id' => Counterparty::create(['legal_name' => 'BRAC Bank PLC', 'kind' => 'bank'])->id],
        ],
    ]));

    expect($voucher->status)->toBe(VoucherStatus::Posted)
        ->and($voucher->voucher_number)->toBe('PV-2026-27-0001')
        ->and(($this->balance)('6051'))->toBe(2500000)
        ->and(($this->balance)('2210'))->toBe(1000050)
        ->and(($this->balance)('11201'))->toBe(-3500050);

    $journal = $voucher->journal()->with('lines')->first();
    $moneyLine = $journal->lines->firstWhere('account_id', ($this->id)('11201'));

    // The bank line says WHICH bank: that is what the cash book reads.
    expect($moneyLine->money_account_id)->toBe($this->bank->id)
        ->and($journal->source_type)->toBe($voucher->getMorphClass())
        ->and($journal->source_id)->toBe($voucher->id)
        ->and($journal->balance()->isZero())->toBeTrue();
});

it('posts a receipt: debits the money account, credits each line', function () {
    $voucher = ($this->post)(($this->draft)(VoucherType::Receipt, [
        'money_account_id' => $this->bank->id,
        'counterparty' => 'Chowdhury Md. Moin',
        'lines' => [['account_id' => ($this->id)('3100'), 'amount' => '500000', 'narration' => 'Capital introduced']],
    ]));

    expect($voucher->voucher_number)->toBe('RV-2026-27-0001')
        ->and(($this->balance)('11201'))->toBe(50000000)
        ->and(($this->balance)('3100'))->toBe(-50000000);
});

it('posts a contra between two money accounts and nothing else', function () {
    $voucher = ($this->post)(($this->draft)(VoucherType::Contra, [
        'money_account_id' => $this->bank->id,
        'to_money_account_id' => $this->cash->id,
        'amount' => '20000',
        'narration' => 'Cash withdrawn for petty cash',
    ]));

    expect($voucher->voucher_number)->toBe('CV-2026-27-0001')
        ->and(($this->balance)('11201'))->toBe(-2000000)
        ->and(($this->balance)('11101'))->toBe(2000000);

    $lines = $voucher->journal->lines;

    expect($lines)->toHaveCount(2)
        ->and($lines->pluck('money_account_id')->sort()->values()->all())->toBe(collect([$this->bank->id, $this->cash->id])->sort()->values()->all());
});

it('refuses a contra into the account it came from', function () {
    expect(fn () => ($this->draft)(VoucherType::Contra, [
        'money_account_id' => $this->bank->id, 'to_money_account_id' => $this->bank->id, 'amount' => '10',
    ]))->toThrow(ValidationException::class);
});

it('posts a journal voucher that balances and carries the journal\'s own JV number', function () {
    $voucher = ($this->post)(($this->draft)(VoucherType::Journal, [
        'narration' => 'Accrue September electricity',
        'lines' => [
            ['account_id' => ($this->id)('6052'), 'side' => 'debit', 'amount' => '4200'],
            ['account_id' => ($this->id)('2160'), 'side' => 'credit', 'amount' => '4200'],
        ],
    ]));

    expect($voucher->voucher_number)->toBe($voucher->journal->journal_number)
        ->and($voucher->voucher_number)->toStartWith('JV-2026-27-')
        ->and($voucher->money_account_id)->toBeNull()
        ->and(($this->balance)('6052'))->toBe(420000)
        ->and(($this->balance)('2160'))->toBe(-420000);
});

it('refuses a journal voucher that does not balance, and burns no number doing so', function () {
    $draft = ($this->draft)(VoucherType::Journal, [
        'lines' => [
            ['account_id' => ($this->id)('6052'), 'side' => 'debit', 'amount' => '4200'],
            ['account_id' => ($this->id)('2160'), 'side' => 'credit', 'amount' => '4000'],
        ],
    ]);

    expect(fn () => ($this->post)($draft))->toThrow(ValidationException::class, 'differ by 200.00');
    expect($draft->fresh()->status)->toBe(VoucherStatus::Draft)->and(Journal::count())->toBe(0);
});

// ─── NUMBERING ──────────────────────────────────────────────────────────────

it('numbers each type on its own stream, at posting, leaving no gap for a deleted draft', function () {
    $line = [['account_id' => ($this->id)('6051'), 'amount' => '10']];

    $abandoned = ($this->draft)(VoucherType::Payment, ['money_account_id' => $this->bank->id, 'lines' => $line]);
    $first = ($this->post)(($this->draft)(VoucherType::Payment, ['money_account_id' => $this->bank->id, 'lines' => $line]));
    $abandoned->delete();
    $second = ($this->post)(($this->draft)(VoucherType::Payment, ['money_account_id' => $this->bank->id, 'lines' => $line]));
    $receipt = ($this->post)(($this->draft)(VoucherType::Receipt, ['money_account_id' => $this->bank->id, 'lines' => [['account_id' => ($this->id)('4990'), 'amount' => '5']]]));

    expect($first->voucher_number)->toBe('PV-2026-27-0001')
        ->and($second->voucher_number)->toBe('PV-2026-27-0002')
        ->and($receipt->voucher_number)->toBe('RV-2026-27-0001');
});

it('restarts the sequence in a new fiscal year and keeps businesses apart', function () {
    $line = [['account_id' => ($this->id)('6051'), 'amount' => '10']];

    // 30 June 2026 belongs to fiscal year 2025-26.
    $old = ($this->post)(($this->draft)(VoucherType::Payment, ['voucher_date' => '2026-06-30', 'money_account_id' => $this->bank->id, 'lines' => $line]));
    $new = ($this->post)(($this->draft)(VoucherType::Payment, ['money_account_id' => $this->bank->id, 'lines' => $line]));

    expect($old->voucher_number)->toBe('PV-2025-26-0001')->and($new->voucher_number)->toBe('PV-2026-27-0001');

    $other = Business::create(['name' => 'Other Co', 'slug' => 'other-co', 'fiscal_year_start_month' => 7]);
    FinancePolicy::create(['business_id' => $other->id, 'refuse_negative_money' => false]);
    TenantScope::enter($other->id);
    $theirBank = MoneyAccount::create(['business_id' => $other->id, 'name' => 'Their Bank', 'type' => 'bank', 'is_active' => true, 'account_id' => Account::bySlug('cash_at_bank')->id]);
    $theirs = ($this->post)(($this->draft)(VoucherType::Payment, ['money_account_id' => $theirBank->id, 'lines' => $line]));

    expect($theirs->voucher_number)->toBe('PV-2026-27-0001');
});

// ─── WHAT A VOUCHER LINE MAY NOT TOUCH ──────────────────────────────────────

it('refuses accounts a subsystem owns, cash and bank heads, and system heads', function (string $code, ?string $mapping) {
    if ($mapping !== null) {
        AccountMapping::assign($mapping, Account::where('code', $code)->sole(), Carbon::parse('2000-01-01'));
    }

    expect(fn () => ($this->draft)(VoucherType::Payment, [
        'money_account_id' => $this->bank->id,
        'lines' => [['account_id' => ($this->id)($code), 'amount' => '10']],
    ]))->toThrow(ValidationException::class);
})->with([
    'accounts receivable, owned by Sales' => ['1140', 'sales.accounts_receivable'],
    'accounts payable, owned by Purchasing' => ['2110', 'purchasing.accounts_payable'],
    'stock, owned by Inventory' => ['1150', 'inventory.stock_on_hand'],
    'a bank head: money moves through the money account field' => ['11201', null],
    'cash in hand' => ['11101', null],
    'opening balance equity' => ['3900', null],
    'employee reimbursements payable, settled by claims' => ['2145', null],
    'a heading' => ['6050', null],
]);

it('refuses another business\'s bank account', function () {
    $other = Business::create(['name' => 'Other Co', 'slug' => 'other-co', 'fiscal_year_start_month' => 7]);
    FinancePolicy::create(['business_id' => $other->id, 'refuse_negative_money' => false]);
    $theirBank = MoneyAccount::withoutGlobalScopes()->create(['business_id' => $other->id, 'name' => 'Their Bank', 'type' => 'bank', 'is_active' => true, 'account_id' => Account::bySlug('cash_at_bank')->id]);

    expect(fn () => ($this->draft)(VoucherType::Payment, [
        'money_account_id' => $theirBank->id,
        'lines' => [['account_id' => ($this->id)('6051'), 'amount' => '10']],
    ]))->toThrow(ValidationException::class);
});

it('refuses a courier or gateway account: those settle by their own statements', function () {
    $courier = MoneyAccount::create(['business_id' => $this->business->id, 'name' => 'Pathao', 'type' => 'courier', 'is_active' => true, 'account_id' => Account::bySlug('courier_receivable')->id]);

    expect(fn () => ($this->draft)(VoucherType::Receipt, [
        'money_account_id' => $courier->id,
        'lines' => [['account_id' => ($this->id)('4990'), 'amount' => '10']],
    ]))->toThrow(ValidationException::class);
});

// ─── ADVANCES CARRY THEIR PARTY ─────────────────────────────────────────────

it('books a supplier advance to 1160 with the supplier on the line', function () {
    $supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'Khulna Honey House', 'code' => 'SUP-001']);

    $voucher = ($this->post)(($this->draft)(VoucherType::Payment, [
        'money_account_id' => $this->bank->id,
        'lines' => [['account_id' => ($this->id)('1160'), 'amount' => '40000', 'party_id' => $supplier->id]],
    ]));

    $line = $voucher->journal->lines->firstWhere('account_id', ($this->id)('1160'));

    expect($line->party_type)->toBe($supplier->getMorphClass())
        ->and($line->party_id)->toBe($supplier->id)
        ->and(($this->balance)('1160'))->toBe(4000000);
});

it('books a customer advance to 2150 with the customer on the line', function () {
    $customer = Customer::create(['business_id' => $this->business->id, 'name' => 'Rahim Traders', 'code' => 'CUS-001', 'type' => 'dealer']);

    $voucher = ($this->post)(($this->draft)(VoucherType::Receipt, [
        'money_account_id' => $this->bank->id,
        'lines' => [['account_id' => ($this->id)('2150'), 'amount' => '15000', 'party_id' => $customer->id]],
    ]));

    expect($voucher->journal->lines->firstWhere('account_id', ($this->id)('2150'))->party_id)->toBe($customer->id);
});

it('lets an advance be drafted without its party, and refuses to post it that way', function () {
    $draft = ($this->draft)(VoucherType::Payment, [
        'money_account_id' => $this->bank->id,
        'lines' => [['account_id' => ($this->id)('1160'), 'amount' => '40000']],
    ]);

    expect(fn () => ($this->post)($draft))->toThrow(ValidationException::class, 'which supplier');
});

it('drops a party named on an account that carries none', function () {
    $supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'Khulna Honey House', 'code' => 'SUP-001']);

    $voucher = ($this->draft)(VoucherType::Payment, [
        'money_account_id' => $this->bank->id,
        'lines' => [['account_id' => ($this->id)('6051'), 'amount' => '10', 'party_id' => $supplier->id]],
    ]);

    expect($voucher->lines->first()->party_id)->toBeNull();
});

// ─── POSTING'S OWN REFUSALS ─────────────────────────────────────────────────

it('refuses to post into a closed period or the future, or with nothing on it', function () {
    $line = [['account_id' => ($this->id)('6051'), 'amount' => '10']];

    $future = ($this->draft)(VoucherType::Payment, ['voucher_date' => '2026-09-25', 'money_account_id' => $this->bank->id, 'lines' => $line]);
    expect(fn () => ($this->post)($future))->toThrow(ValidationException::class);

    $empty = ($this->draft)(VoucherType::Payment, ['money_account_id' => $this->bank->id, 'lines' => []]);
    expect(fn () => ($this->post)($empty))->toThrow(ValidationException::class);

    $noMoney = ($this->draft)(VoucherType::Payment, ['lines' => $line]);
    expect(fn () => ($this->post)($noMoney))->toThrow(ValidationException::class);

    AccountingPeriod::closeThrough(Carbon::parse('2026-08-31'), $this->user);
    $closed = ($this->draft)(VoucherType::Payment, ['voucher_date' => '2026-08-20', 'money_account_id' => $this->bank->id, 'lines' => $line]);
    expect(fn () => ($this->post)($closed))->toThrow(PeriodClosedException::class);

    expect(Journal::count())->toBe(0);
});

it('posts once even when asked twice', function () {
    $voucher = ($this->post)(($this->draft)(VoucherType::Payment, [
        'money_account_id' => $this->bank->id, 'lines' => [['account_id' => ($this->id)('6051'), 'amount' => '10']],
    ]));

    expect(fn () => ($this->post)($voucher))->toThrow(ValidationException::class);
    expect(Journal::count())->toBe(1);
});

// ─── REVERSAL, AND REVERSE AND COPY ─────────────────────────────────────────

it('reverses with a reason: the original stays, a second journal undoes it, both are marked', function () {
    $voucher = ($this->post)(($this->draft)(VoucherType::Payment, [
        'money_account_id' => $this->bank->id, 'lines' => [['account_id' => ($this->id)('6051'), 'amount' => '25000']],
    ]));

    $reversed = app(ReverseVoucher::class)->handle($voucher, $this->user, 'Paid from the wrong bank');

    expect($reversed->status)->toBe(VoucherStatus::Reversed)
        ->and($reversed->voucher_number)->toBe('PV-2026-27-0001')
        ->and($reversed->reversal_reason)->toBe('Paid from the wrong bank')
        ->and($reversed->reversalJournal->reverses_journal_id)->toBe($voucher->journal_id)
        ->and(($this->balance)('6051'))->toBe(0)
        ->and(($this->balance)('11201'))->toBe(0)
        ->and(Journal::count())->toBe(2);
});

it('refuses a reversal with no reason, of a draft, or a second time', function () {
    $draft = ($this->draft)(VoucherType::Payment, ['money_account_id' => $this->bank->id, 'lines' => [['account_id' => ($this->id)('6051'), 'amount' => '10']]]);

    expect(fn () => app(ReverseVoucher::class)->handle($draft, $this->user, 'x'))->toThrow(ValidationException::class);

    $voucher = ($this->post)($draft);

    expect(fn () => app(ReverseVoucher::class)->handle($voucher, $this->user, '   '))->toThrow(ValidationException::class);

    app(ReverseVoucher::class)->handle($voucher, $this->user, 'Entered twice');

    expect(fn () => app(ReverseVoucher::class)->handle($voucher->fresh(), $this->user, 'Again'))->toThrow(ValidationException::class);
    expect(Journal::count())->toBe(2);
});

it('reverse and copy corrects on the original day and keeps the bank reference', function () {
    $voucher = ($this->post)(($this->draft)(VoucherType::Payment, [
        'money_account_id' => $this->bank->id,
        'counterparty' => 'Adabor landlord',
        'instrument_type' => 'cheque', 'instrument_number' => 'CHQ-001122', 'instrument_date' => '2026-09-19', 'instrument_bank' => 'BRAC Bank',
        'lines' => [['account_id' => ($this->id)('6051'), 'amount' => '25000', 'narration' => 'September rent']],
    ]));

    $copy = app(ReverseVoucher::class)->andCopy($voucher, $this->user, 'Amount was 26,000');

    expect($voucher->fresh()->status)->toBe(VoucherStatus::Reversed)
        ->and($copy->status)->toBe(VoucherStatus::Draft)
        ->and($copy->voucher_number)->toBeNull()
        ->and($copy->copied_from_id)->toBe($voucher->id)
        ->and($copy->counterparty)->toBe('Adabor landlord')
        ->and($copy->lines->first()->narration)->toBe('September rent')
        ->and($copy->total()->format())->toBe('25,000.00')
        // ⭐ A correcting copy is the SAME bank line, and the old voucher is reversed:
        // it keeps the date, the cheque and the reversal sits on the original day.
        ->and($copy->voucher_date->toDateString())->toBe($voucher->voucher_date->toDateString())
        ->and($copy->instrument_number)->toBe('CHQ-001122')
        ->and($copy->instrument_bank)->toBe('BRAC Bank')
        ->and($voucher->fresh()->reversalJournal->journal_date->toDateString())->toBe($voucher->voucher_date->toDateString());
});

it('a plain duplicate is a new payment: today, and no cheque number', function () {
    $voucher = ($this->post)(($this->draft)(VoucherType::Payment, [
        'money_account_id' => $this->bank->id,
        'instrument_type' => 'cheque', 'instrument_number' => 'CHQ-001133', 'instrument_bank' => 'BRAC Bank',
        'lines' => [['account_id' => ($this->id)('6051'), 'amount' => '25000', 'narration' => 'October rent']],
    ]));

    $copy = app(ReverseVoucher::class)->copy($voucher, $this->user);

    expect($voucher->fresh()->status)->toBe(VoucherStatus::Posted)
        ->and($copy->voucher_date->toDateString())->toBe(now()->toDateString())
        ->and($copy->instrument_number)->toBeNull();
});

// ─── AMOUNT IN WORDS ────────────────────────────────────────────────────────

it('writes the amount in words the way a Bangladeshi voucher does', function (string $taka, string $words) {
    expect(AmountInWords::taka(Money::ofTaka($taka)))->toBe($words);
})->with([
    ['0', 'Taka Zero Only'],
    ['19', 'Taka Nineteen Only'],
    ['120500.50', 'Taka One Lakh Twenty Thousand Five Hundred and Paisa Fifty Only'],
    ['10000000', 'Taka One Crore Only'],
    ['253467891.05', 'Taka Twenty Five Crore Thirty Four Lakh Sixty Seven Thousand Eight Hundred Ninety One and Paisa Five Only'],
    ['100', 'Taka One Hundred Only'],
]);

// ─── Accountant meeting 19 Sep 2026, F4: a gateway payout reaching the bank ───

it('moves a gateway payout into the bank with a contra, and only in that direction', function () {
    $gateway = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'SSLCommerz', 'type' => 'gateway', 'is_active' => true,
        'account_id' => Account::where('code', '1146')->sole()->id,
    ]);

    $voucher = ($this->post)(($this->draft)(VoucherType::Contra, [
        'money_account_id' => $gateway->id, 'to_money_account_id' => $this->bank->id,
        'amount' => '5000', 'narration' => 'SSLCommerz payout',
    ]));

    expect($voucher->voucher_number)->toStartWith('CV-')
        ->and(($this->balance)('1146'))->toBe(-500000)
        ->and(($this->balance)('11201'))->toBe(500000);

    // Into a gateway by hand: refused. A gateway fills itself from sales.
    expect(fn () => ($this->draft)(VoucherType::Contra, [
        'money_account_id' => $this->bank->id, 'to_money_account_id' => $gateway->id, 'amount' => '10',
    ]))->toThrow(ValidationException::class);

    // And a gateway still can never pay an expense.
    expect(fn () => ($this->draft)(VoucherType::Payment, [
        'money_account_id' => $gateway->id,
        'lines' => [['account_id' => ($this->id)('6055'), 'amount' => '10']],
    ]))->toThrow(ValidationException::class);
});
