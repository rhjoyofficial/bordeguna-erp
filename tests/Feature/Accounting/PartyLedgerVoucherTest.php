<?php

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Actions\PostVoucher;
use App\Modules\Accounting\Actions\ReverseVoucher;
use App\Modules\Accounting\Actions\SaveVoucherDraft;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Enums\VoucherStatus;
use App\Modules\Accounting\Enums\VoucherType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FinancePolicy;
use App\Modules\Accounting\Models\PartyAdjustment;
use App\Modules\Accounting\Support\CustomerStatement;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\PartyBillWise;
use App\Modules\Accounting\Support\SubledgerReconciliation;
use App\Modules\Accounting\Support\SupplierLedger;
use App\Modules\Accounting\Support\SupplierStatement;
use App\Modules\Accounting\Support\VoucherAccounts;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Purchasing\Actions\ReverseSupplierRefund;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierAdvanceRefund;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierPayment;
use App\Modules\Purchasing\Models\SupplierRefund;
use App\Modules\Purchasing\Models\WithholdingRate;
use App\Modules\Purchasing\Support\SupplierAdvanceBook;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ Suppliers and customers as Tally ledgers on Payment, Receipt and Journal
 * vouchers (Joy, 18 Oct 2026: "how do I give them money or how do they give
 * me?"): bill-wise details, refunds both ways, TDS on a supplier payment, and
 * a party on a journal voucher posted as the adjustment it is. Every case
 * still ties out: one control account per side, the party on the line.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-09-20 10:00:00');

    $this->business = Business::create(['name' => 'Ledgers Co', 'slug' => 'ledgers-co', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);
    (new ChartOfAccountsSeeder)->run();
    (new SalesAccountMappingSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    FinancePolicy::create(['business_id' => $this->business->id, 'refuse_negative_money' => false]);

    $this->user = User::factory()->create();

    $this->bank = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'BRAC Bank', 'type' => 'bank', 'is_active' => true,
        'account_id' => Account::bySlug('cash_at_bank')->id,
    ]);

    $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'Rahman Traders', 'code' => 'RAHMAN']);
    $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'Padma Oil']);
    $this->customer->refresh();
    $this->supplier->refresh();

    $receivable = Account::bySlug('accounts_receivable');
    $payable = Account::bySlug('accounts_payable');
    $capital = Account::bySlug('owners_capital');

    $this->invoice = function (string $total, string $date, string $number) use ($receivable, $capital) {
        $invoice = SalesInvoice::create([
            'business_id' => $this->business->id, 'customer_id' => $this->customer->id, 'invoice_number' => $number,
            'invoice_date' => $date, 'subtotal' => $total, 'total' => $total, 'created_by' => $this->user->id,
        ]);
        app(PostJournal::class)->handle(new JournalDraft(Carbon::parse($date), [
            JournalLineDraft::debit($receivable->id, Money::ofTaka($total), null, $this->customer),
            JournalLineDraft::credit($capital->id, Money::ofTaka($total)),
        ], source: $invoice), $this->user);

        return $invoice;
    };

    $this->bill = function (string $total, string $date, string $number) use ($payable, $capital) {
        $bill = SupplierBill::create([
            'business_id' => $this->business->id, 'supplier_id' => $this->supplier->id, 'bill_number' => $number,
            'bill_date' => $date, 'total' => $total, 'created_by' => $this->user->id,
        ]);
        app(PostJournal::class)->handle(new JournalDraft(Carbon::parse($date), [
            JournalLineDraft::credit($payable->id, Money::ofTaka($total), null, $this->supplier),
            JournalLineDraft::debit($capital->id, Money::ofTaka($total)),
        ], source: $bill), $this->user);

        return $bill;
    };

    $this->draft = fn (VoucherType $type, array $lines, array $extra = []) => app(SaveVoucherDraft::class)->handle($type, [
        'voucher_date' => '2026-09-20', 'money_account_id' => $type->usesMoneyAccount() ? $this->bank->id : null, 'lines' => $lines, ...$extra,
    ], $this->user);

    $this->post = fn (VoucherType $type, array $lines, array $extra = [], ?User $by = null) => app(PostVoucher::class)->handle(($this->draft)($type, $lines, $extra), $by ?? $this->user);

    $this->balance = fn (string $slug, $party) => (int) DB::table('journal_lines')
        ->where('account_id', Account::bySlug($slug)->id)
        ->where('party_type', $party->getMorphClass())->where('party_id', $party->id)
        ->sum('amount_minor');

    $this->tiesOut = function () {
        $problems = collect(SubledgerReconciliation::reconcileCurrentBusiness()['problems'])
            ->filter(fn (string $problem) => str_contains($problem, 'Accounts Payable') || str_contains($problem, 'Accounts Receivable'))
            ->values()->all();

        expect($problems)->toBe([]);
    };

    $this->poster = function (): User {
        Permission::firstOrCreate(['name' => 'accounting.journal.post', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'Journal poster', 'business_id' => $this->business->id, 'guard_name' => 'web'])
            ->givePermissionTo(['accounting.journal.post']);
        $this->user->businesses()->attach($this->business->id);
        $this->user->assignRole($role);

        return $this->user->fresh();
    };

    // An Owner or Manager other than the voucher's preparer ($this->user).
    $this->approver = function (string $role): User {
        $approver = User::factory()->create();
        $approver->businesses()->attach($this->business->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);
        $approver->assignRole(Role::firstOrCreate(['name' => $role, 'business_id' => $this->business->id, 'guard_name' => 'web']));

        return $approver->fresh();
    };
});

afterEach(fn () => Carbon::setTestNow());

it('lets every customer and supplier head be picked on payment, receipt and journal vouchers', function () {
    foreach ([VoucherType::Payment, VoucherType::Receipt, VoucherType::Journal] as $type) {
        $rows = VoucherAccounts::catalogue(Carbon::today(), $type)->keyBy(fn ($row) => $row['account']->id);

        expect($rows[$this->customer->account_id]['usable'])->toBeTrue($type->value)
            ->and($rows[$this->supplier->account_id]['usable'])->toBeTrue($type->value);
    }
});

it('pays the bills the person chose, not the oldest, and keeps the rest as an advance', function () {
    $old = ($this->bill)('8000', '2026-08-05', 'SB-1');
    $new = ($this->bill)('5000', '2026-09-01', 'SB-2');

    $voucher = ($this->post)(VoucherType::Payment, [[
        'account_id' => $this->supplier->account_id, 'amount' => '6000',
        'bill_wise' => [['ref' => "bill:{$new->id}", 'amount' => '5000'], ['ref' => 'advance', 'amount' => '1000']],
    ]]);

    $payment = SupplierPayment::query()->where('voucher_id', $voucher->id)->sole();

    expect($payment->allocations->sole()->supplier_bill_id)->toBe($new->id)
        ->and($new->fresh()->outstanding()->minor)->toBe(0)
        ->and($old->fresh()->outstanding()->format())->toBe('8,000.00')
        ->and(($this->balance)('supplier_advances', $this->supplier))->toBe(100_000)
        ->and(SupplierStatement::for($this->supplier, Carbon::parse('2026-07-01'), Carbon::parse('2026-09-30')->endOfDay())->isConsistent())->toBeTrue();

    ($this->tiesOut)();
});

it('refuses a bill-wise amount above what the bill still owes, or a bill that is not open', function () {
    $bill = ($this->bill)('3000', '2026-08-05', 'SB-1');

    expect(fn () => ($this->post)(VoucherType::Payment, [[
        'account_id' => $this->supplier->account_id, 'amount' => '5000',
        'bill_wise' => [['ref' => "bill:{$bill->id}", 'amount' => '4000']],
    ]]))->toThrow(ValidationException::class, 'SB-1 has only 3,000.00 open');

    expect(fn () => ($this->post)(VoucherType::Payment, [[
        'account_id' => $this->supplier->account_id, 'amount' => '500',
        'bill_wise' => [['ref' => 'bill:999999', 'amount' => '500']],
    ]]))->toThrow(ValidationException::class, 'no longer open');

    expect(fn () => ($this->post)(VoucherType::Payment, [[
        'account_id' => $this->supplier->account_id, 'amount' => '500',
        'bill_wise' => [['ref' => "bill:{$bill->id}", 'amount' => '900']],
    ]]))->toThrow(ValidationException::class, 'more than the line');

    expect(SupplierPayment::count())->toBe(0);
});

it('holds TDS back on a supplier payment voucher through the owner\'s rate: the bank pays the net', function () {
    $bill = ($this->bill)('10000', '2026-08-05', 'SB-1');
    $tds = WithholdingRate::create(['business_id' => $this->business->id, 'kind' => 'tds', 'supplier_type' => null, 'name' => 'Supply', 'rate' => '5', 'effective_from' => '2026-07-01']);

    $voucher = ($this->post)(VoucherType::Payment, [[
        'account_id' => $this->supplier->account_id, 'amount' => '10000', 'tds_rate_id' => $tds->id,
    ]]);

    $payment = SupplierPayment::query()->where('voucher_id', $voucher->id)->sole();
    $bankLine = DB::table('journal_lines')->where('journal_id', $voucher->journal_id)->where('account_id', $this->bank->account_id)->value('amount_minor');

    expect((string) $payment->tds_amount)->toStartWith('500')
        ->and($bill->fresh()->outstanding()->minor)->toBe(0)
        ->and((int) $bankLine)->toBe(-950_000)
        ->and(($this->balance)('tds_payable', $this->supplier))->toBe(-50_000)
        ->and(($this->balance)('accounts_payable', $this->supplier))->toBe(0);

    ($this->tiesOut)();
});

it('refuses tax on a line that does not pay a supplier', function () {
    $tds = WithholdingRate::create(['business_id' => $this->business->id, 'kind' => 'tds', 'supplier_type' => null, 'name' => 'Supply', 'rate' => '5', 'effective_from' => '2026-07-01']);

    $draft = ($this->draft)(VoucherType::Receipt, [['account_id' => $this->customer->account_id, 'amount' => '100', 'tds_rate_id' => $tds->id]]);

    // Dropped on the draft: a customer's line holds nothing back.
    expect($draft->lines->sole()->tds_rate_id)->toBeNull();
});

it('receives money a supplier owes us back on a receipt voucher as a supplier refund (SREF)', function () {
    // Paid twice by mistake: the supplier's account is 5,000 in OUR favour.
    app(PostJournal::class)->handle(new JournalDraft(Carbon::parse('2026-09-01'), [
        JournalLineDraft::debit(Account::bySlug('accounts_payable')->id, Money::ofTaka('5000'), null, $this->supplier),
        JournalLineDraft::credit(Account::bySlug('owners_capital')->id, Money::ofTaka('5000')),
    ]), $this->user);

    $journals = DB::table('journals')->count();
    $voucher = ($this->post)(VoucherType::Receipt, [['account_id' => $this->supplier->account_id, 'amount' => '3000']]);

    $refund = SupplierRefund::query()->where('voucher_id', $voucher->id)->sole();

    expect($refund->money()->format())->toBe('3,000.00')
        ->and($refund->reference)->toBe($voucher->voucher_number)
        ->and(($this->balance)('accounts_payable', $this->supplier))->toBe(200_000)
        // One journal: the voucher's own.
        ->and(DB::table('journals')->count())->toBe($journals + 1);

    // The refund belongs to the voucher: it is reversed with it, never alone.
    expect(fn () => app(ReverseSupplierRefund::class)->handle($refund, $this->user, 'Wrong'))
        ->toThrow(ValidationException::class, 'Reverse the voucher');

    app(ReverseVoucher::class)->handle($voucher, $this->user, 'Wrong supplier');

    expect($refund->fresh()->isReversed())->toBeTrue()
        ->and(($this->balance)('accounts_payable', $this->supplier))->toBe(500_000);
});

it('receives an advance back on a receipt voucher as an advance refund (SARF), counted once', function () {
    // An advance paid by voucher with no bill: on account in 1160.
    ($this->post)(VoucherType::Payment, [['account_id' => $this->supplier->account_id, 'amount' => '1000']]);
    expect(SupplierAdvanceBook::onAccount($this->supplier)->format())->toBe('1,000.00');

    $voucher = ($this->post)(VoucherType::Receipt, [['account_id' => $this->supplier->account_id, 'amount' => '600']]);

    $refund = SupplierAdvanceRefund::query()->where('voucher_id', $voucher->id)->sole();

    expect($refund->supplier_advance_id)->toBeNull()
        ->and(SupplierAdvanceBook::onAccount($this->supplier)->format())->toBe('400.00')
        ->and(($this->balance)('supplier_advances', $this->supplier))->toBe(40_000)
        ->and(SupplierAdvanceBook::totalOpen($this->supplier)->minor)->toBe(($this->balance)('supplier_advances', $this->supplier));

    app(ReverseVoucher::class)->handle($voucher, $this->user, 'Bank returned it');

    expect($refund->fresh()->isReversed())->toBeTrue()
        ->and(SupplierAdvanceBook::onAccount($this->supplier)->format())->toBe('1,000.00')
        ->and(($this->balance)('supplier_advances', $this->supplier))->toBe(100_000);
});

it('says plainly what a supplier can pay back when the receipt asks for more, or nothing is open', function () {
    expect(fn () => ($this->post)(VoucherType::Receipt, [['account_id' => $this->supplier->account_id, 'amount' => '100']]))
        ->toThrow(ValidationException::class, 'owes us nothing back and holds no advance');

    ($this->post)(VoucherType::Payment, [['account_id' => $this->supplier->account_id, 'amount' => '1000']]);

    expect(fn () => ($this->post)(VoucherType::Receipt, [['account_id' => $this->supplier->account_id, 'amount' => '1500']]))
        ->toThrow(ValidationException::class, 'owes us back only 1,000.00');
});

it('refunds a customer their advance on a payment voucher, and never more than they hold', function () {
    ($this->post)(VoucherType::Receipt, [['account_id' => $this->customer->account_id, 'amount' => '3000']]);
    expect(($this->balance)('customer_advances', $this->customer))->toBe(-300_000);

    ($this->post)(VoucherType::Payment, [['account_id' => $this->customer->account_id, 'amount' => '1000']]);

    expect(($this->balance)('customer_advances', $this->customer))->toBe(-200_000);

    expect(fn () => ($this->post)(VoucherType::Payment, [['account_id' => $this->customer->account_id, 'amount' => '2500']]))
        ->toThrow(ValidationException::class, 'Only 2,000.00 can be refunded to Rahman Traders');

    expect(fn () => ($this->post)(VoucherType::Payment, [['account_id' => Customer::create(['business_id' => $this->business->id, 'name' => 'Walk-in', 'code' => 'WALK'])->fresh()->account_id, 'amount' => '10']]))
        ->toThrow(ValidationException::class, 'Nothing of Walk-in');

    ($this->tiesOut)();
});

it('posts a customer on a journal voucher as a write-off against the invoice, with the journal look', function () {
    $user = ($this->poster)();
    $invoice = ($this->invoice)('4000', '2026-08-01', 'INV-1');

    $voucher = ($this->post)(VoucherType::Journal, [
        ['account_id' => Account::bySlug('bad_debts')->id, 'side' => 'debit', 'amount' => '1500'],
        ['account_id' => $this->customer->account_id, 'side' => 'credit', 'amount' => '1500'],
    ], ['narration' => 'Customer closed the shop'], $user);

    $adjustment = PartyAdjustment::query()->where('voucher_id', $voucher->id)->sole();

    expect($voucher->status)->toBe(VoucherStatus::Posted)
        ->and($voucher->voucher_number)->toBe($voucher->journal->journal_number)
        ->and($adjustment->kind)->toBe('customer_write_off')
        ->and($invoice->fresh()->outstanding()->format())->toBe('2,500.00')
        ->and(CustomerStatement::for($this->customer, Carbon::parse('2026-07-01'), Carbon::parse('2026-09-30')->endOfDay())->isConsistent())->toBeTrue();

    ($this->tiesOut)();

    app(ReverseVoucher::class)->handle($voucher, $user, 'Customer paid after all');

    expect(PartyAdjustment::count())->toBe(0)
        ->and($invoice->fresh()->outstanding()->format())->toBe('4,000.00');

    ($this->tiesOut)();
});

it('sets a supplier off against a customer on a journal voucher', function () {
    $user = ($this->poster)();
    $invoice = ($this->invoice)('2000', '2026-08-01', 'INV-1');
    $bill = ($this->bill)('3000', '2026-08-02', 'SB-1');

    $voucher = ($this->post)(VoucherType::Journal, [
        ['account_id' => $this->supplier->account_id, 'side' => 'debit', 'amount' => '2000'],
        ['account_id' => $this->customer->account_id, 'side' => 'credit', 'amount' => '2000'],
    ], ['narration' => 'Netted by agreement'], $user);

    expect(PartyAdjustment::query()->where('voucher_id', $voucher->id)->sole()->kind)->toBe('set_off')
        ->and($invoice->fresh()->outstanding()->minor)->toBe(0)
        ->and($bill->fresh()->outstanding()->format())->toBe('1,000.00');

    ($this->tiesOut)();
});

it('refuses a party journal voucher without the journal right, a reason, two lines, or a known other side', function () {
    ($this->invoice)('4000', '2026-08-01', 'INV-1');
    $lines = [
        ['account_id' => Account::bySlug('bad_debts')->id, 'side' => 'debit', 'amount' => '100'],
        ['account_id' => $this->customer->account_id, 'side' => 'credit', 'amount' => '100'],
    ];

    expect(fn () => ($this->post)(VoucherType::Journal, $lines, ['narration' => 'x']))
        ->toThrow(ValidationException::class, 'Only someone who may post journals');

    $user = ($this->poster)();

    expect(fn () => ($this->post)(VoucherType::Journal, $lines, [], $user))
        ->toThrow(ValidationException::class, 'Say why in the narration');

    expect(fn () => ($this->post)(VoucherType::Journal, [
        ['account_id' => Account::bySlug('owners_capital')->id, 'side' => 'debit', 'amount' => '100'],
        ['account_id' => $this->customer->account_id, 'side' => 'credit', 'amount' => '100'],
    ], ['narration' => 'x'], $user))->toThrow(ValidationException::class, 'has two lines');

    expect(fn () => ($this->post)(VoucherType::Journal, [
        ['account_id' => Account::bySlug('bad_debts')->id, 'side' => 'debit', 'amount' => '9000'],
        ['account_id' => $this->customer->account_id, 'side' => 'credit', 'amount' => '9000'],
    ], ['narration' => 'x'], $user))->toThrow(ValidationException::class, 'has only 4,000.00 open on invoices');

    expect(PartyAdjustment::count())->toBe(0);
});

it('names the party in the voucher header, and its printed name', function () {
    $draft = ($this->draft)(VoucherType::Payment, [['account_id' => $this->supplier->account_id, 'amount' => '100']], ['party' => "supplier:{$this->supplier->id}"]);

    expect($draft->party_id)->toBe($this->supplier->id)
        ->and($draft->party_type)->toBe($this->supplier->getMorphClass())
        ->and($draft->counterparty)->toBe('Padma Oil');
});

it('lists what a party line can be against, as the voucher form asks for it', function () {
    ($this->bill)('8000', '2026-08-05', 'SB-1');
    ($this->bill)('5000', '2026-09-10', 'SB-LATER');

    $items = PartyBillWise::items(PartyBillWise::SUPPLIER_PAYMENT, $this->supplier, Carbon::parse('2026-09-05'));

    expect(array_column($items, 'number'))->toBe(['SB-1'])
        ->and(PartyBillWise::restIsAdvance(PartyBillWise::SUPPLIER_PAYMENT))->toBeTrue()
        ->and(PartyBillWise::restIsAdvance(PartyBillWise::SUPPLIER_REFUND))->toBeFalse();
});

it('keeps the supplier ledger view tied out through payments, refunds and advances', function () {
    ($this->bill)('8000', '2026-08-05', 'SB-1');
    ($this->post)(VoucherType::Payment, [['account_id' => $this->supplier->account_id, 'amount' => '9000']]);
    ($this->post)(VoucherType::Receipt, [['account_id' => $this->supplier->account_id, 'amount' => '400']]);

    $ledger = SupplierLedger::for($this->supplier, Carbon::parse('2026-07-01'), Carbon::parse('2026-09-30')->endOfDay());

    expect($ledger->isConsistent())->toBeTrue()
        ->and($ledger->advances()->format())->toBe('600.00');
});

it('refuses the supplier named twice on an advance refund, once by their ledger and once on Advances to Suppliers', function () {
    ($this->post)(VoucherType::Payment, [['account_id' => $this->supplier->account_id, 'amount' => '1000']]);

    expect(fn () => ($this->post)(VoucherType::Receipt, [
        ['account_id' => $this->supplier->account_id, 'amount' => '300'],
        ['account_id' => Account::bySlug('supplier_advances')->id, 'amount' => '200', 'party_id' => $this->supplier->id],
    ]))->toThrow(ValidationException::class, 'Name Padma Oil once');

    expect(SupplierAdvanceRefund::count())->toBe(0)
        ->and(SupplierAdvanceBook::onAccount($this->supplier)->format())->toBe('1,000.00');
});

it('holds a Tk 200,000 supplier refund (SREF) on a receipt voucher for an Owner or Manager who did not prepare it', function () {
    config(['accounting.purchasing_approvals' => true]);
    app(PostJournal::class)->handle(new JournalDraft(Carbon::parse('2026-09-01'), [
        JournalLineDraft::debit(Account::bySlug('accounts_payable')->id, Money::ofTaka('300000'), null, $this->supplier),
        JournalLineDraft::credit(Account::bySlug('owners_capital')->id, Money::ofTaka('300000')),
    ]), $this->user);

    $draft = ($this->draft)(VoucherType::Receipt, [['account_id' => $this->supplier->account_id, 'amount' => '200000']]);

    // The preparer may not post it, however senior.
    expect(fn () => app(PostVoucher::class)->handle($draft, $this->user))
        ->toThrow(ValidationException::class, "needs a second person's approval");
    expect(SupplierRefund::count())->toBe(0)
        ->and($draft->fresh()->status)->not->toBe(VoucherStatus::Posted);

    $manager = ($this->approver)('Manager');
    $voucher = app(PostVoucher::class)->handle($draft->fresh(), $manager);

    expect(SupplierRefund::query()->where('voucher_id', $voucher->id)->sole()->money()->format())->toBe('200,000.00')
        ->and(($this->balance)('accounts_payable', $this->supplier))->toBe(10_000_000);
});

it('holds a Tk 200,000 advance refund (SARF) on a receipt voucher for an Owner or Manager who did not prepare it', function () {
    config(['accounting.purchasing_approvals' => true]);
    $owner = ($this->approver)('Owner');

    // The advance itself is at the limit too: posted by an approver.
    app(PostVoucher::class)->handle(($this->draft)(VoucherType::Payment, [['account_id' => $this->supplier->account_id, 'amount' => '250000']]), $owner);
    expect(SupplierAdvanceBook::onAccount($this->supplier)->format())->toBe('250,000.00');

    $draft = ($this->draft)(VoucherType::Receipt, [['account_id' => $this->supplier->account_id, 'amount' => '220000']]);

    expect(fn () => app(PostVoucher::class)->handle($draft, $this->user))
        ->toThrow(ValidationException::class, "needs a second person's approval");
    expect(SupplierAdvanceRefund::count())->toBe(0);

    // Just under the limit needs nobody.
    ($this->post)(VoucherType::Receipt, [['account_id' => $this->supplier->account_id, 'amount' => '1000']]);

    app(PostVoucher::class)->handle($draft->fresh(), $owner);

    expect(SupplierAdvanceRefund::count())->toBe(2)
        ->and(SupplierAdvanceBook::onAccount($this->supplier)->format())->toBe('29,000.00');
});
