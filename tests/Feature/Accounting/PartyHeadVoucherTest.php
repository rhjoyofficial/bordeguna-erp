<?php

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Actions\PostVoucher;
use App\Modules\Accounting\Actions\ReverseVoucher;
use App\Modules\Accounting\Actions\SaveVoucherDraft;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Enums\VoucherType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FinancePolicy;
use App\Modules\Accounting\Support\CustomerStatement;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\SupplierStatement;
use App\Modules\Accounting\Support\VoucherAccounts;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierPayment;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A customer or supplier picked straight onto a voucher by its own head
 * (finance decision C3, option c, 29 Sep 2026): the voucher creates the real
 * receipt or payment, oldest invoice first, the rest is an advance, and the
 * statement still ties to the ledger.
 */
beforeEach(function () {
    // The balance guard is off in the suite by default (phpunit.xml); on here.
    config(['accounting.money_availability' => true]);

    Carbon::setTestNow('2026-09-20 10:00:00');

    $this->business = Business::create(['name' => 'Heads Co', 'slug' => 'heads-co', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);
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

    // An invoice WITH its ledger side, so the statement can tie out.
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

    $this->post = fn (VoucherType $type, array $lines) => app(PostVoucher::class)->handle(
        app(SaveVoucherDraft::class)->handle($type, [
            'voucher_date' => '2026-09-20', 'money_account_id' => $this->bank->id, 'lines' => $lines,
        ], $this->user),
        $this->user,
    );

    $this->balance = fn (string $slug, $party) => (int) DB::table('journal_lines')
        ->where('account_id', Account::bySlug($slug)->id)
        ->where('party_type', $party->getMorphClass())->where('party_id', $party->id)
        ->sum('amount_minor');

    $this->customerStatement = fn () => CustomerStatement::for($this->customer, Carbon::parse('2026-07-01'), Carbon::parse('2026-09-30')->endOfDay());
    $this->supplierStatement = fn () => SupplierStatement::for($this->supplier, Carbon::parse('2026-07-01'), Carbon::parse('2026-09-30')->endOfDay());
});

afterEach(fn () => Carbon::setTestNow());

it('gives every customer and supplier a head that is pickable only where the voucher type allows it', function () {
    $rows = fn (VoucherType $type) => VoucherAccounts::catalogue(Carbon::today(), $type)->keyBy(fn ($row) => $row['account']->id);

    expect($rows(VoucherType::Receipt)[$this->customer->account_id]['usable'])->toBeTrue()
        ->and($rows(VoucherType::Payment)[$this->customer->account_id]['usable'])->toBeFalse()
        ->and($rows(VoucherType::Payment)[$this->supplier->account_id]['usable'])->toBeTrue()
        ->and($rows(VoucherType::Journal)[$this->supplier->account_id]['usable'])->toBeFalse()
        ->and(Account::find($this->customer->account_id)->is_postable)->toBeFalse();
});

it('turns a customer line on a receive voucher into a real receipt, oldest invoice first, the rest an advance', function () {
    $old = ($this->invoice)('10000', '2026-08-01', 'INV-1');
    $new = ($this->invoice)('5000', '2026-09-01', 'INV-2');

    $voucher = ($this->post)(VoucherType::Receipt, [['account_id' => $this->customer->account_id, 'amount' => '17000']]);

    $receipt = CustomerReceipt::query()->where('voucher_id', $voucher->id)->sole();

    expect($receipt->amount)->toEqual('15000.0000')
        ->and($receipt->reference)->toBe($voucher->voucher_number)
        ->and($old->fresh()->outstanding()->minor)->toBe(0)
        ->and($new->fresh()->outstanding()->minor)->toBe(0)
        // AR cleared for this customer, 2,000 held as their advance.
        ->and(($this->balance)('accounts_receivable', $this->customer))->toBe(0)
        ->and(($this->balance)('customer_advances', $this->customer))->toBe(-200_000)
        ->and(($this->customerStatement)()->isConsistent())->toBeTrue();
});

it('pays the oldest invoice partly when the money does not cover it', function () {
    $old = ($this->invoice)('10000', '2026-08-01', 'INV-1');
    $new = ($this->invoice)('5000', '2026-09-01', 'INV-2');

    ($this->post)(VoucherType::Receipt, [['account_id' => $this->customer->account_id, 'amount' => '4000']]);

    expect($old->fresh()->outstanding()->format())->toBe('6,000.00')
        ->and($new->fresh()->outstanding()->format())->toBe('5,000.00')
        ->and(($this->customerStatement)()->isConsistent())->toBeTrue();
});

it('books money from a customer with nothing owing as an advance and makes no receipt', function () {
    ($this->post)(VoucherType::Receipt, [['account_id' => $this->customer->account_id, 'amount' => '3000']]);

    expect(CustomerReceipt::count())->toBe(0)
        ->and(($this->balance)('customer_advances', $this->customer))->toBe(-300_000);
});

it('never pays the same invoice twice when one voucher names the customer on two lines', function () {
    $invoice = ($this->invoice)('10000', '2026-08-01', 'INV-1');

    ($this->post)(VoucherType::Receipt, [
        ['account_id' => $this->customer->account_id, 'amount' => '6000'],
        ['account_id' => $this->customer->account_id, 'amount' => '6000'],
    ]);

    expect($invoice->fresh()->outstanding()->minor)->toBe(0)
        ->and(CustomerReceipt::sum('amount'))->toEqual(10000)
        ->and(($this->balance)('customer_advances', $this->customer))->toBe(-200_000)
        ->and(($this->customerStatement)()->isConsistent())->toBeTrue();
});

it('turns a supplier line on a payment voucher into a real payment against bills, the rest an advance', function () {
    $bill = ($this->bill)('8000', '2026-08-05', 'SB-1');

    $voucher = ($this->post)(VoucherType::Payment, [['account_id' => $this->supplier->account_id, 'amount' => '9000']]);

    $payment = SupplierPayment::query()->where('voucher_id', $voucher->id)->sole();

    expect((string) $payment->amount)->toStartWith('8000')
        ->and($bill->fresh()->outstanding()->minor)->toBe(0)
        ->and(($this->balance)('accounts_payable', $this->supplier))->toBe(0)
        ->and(($this->balance)('supplier_advances', $this->supplier))->toBe(100_000)
        ->and(($this->supplierStatement)()->isConsistent())->toBeTrue();
});

it('posts only ONE journal: the receipt rides on the voucher, not a second posting', function () {
    ($this->invoice)('10000', '2026-08-01', 'INV-1');
    $before = DB::table('journals')->count();

    ($this->post)(VoucherType::Receipt, [['account_id' => $this->customer->account_id, 'amount' => '10000']]);

    expect(DB::table('journals')->count())->toBe($before + 1);
});

it('takes the receipt away again when the voucher is reversed, so the invoice is open', function () {
    $invoice = ($this->invoice)('10000', '2026-08-01', 'INV-1');
    $voucher = ($this->post)(VoucherType::Receipt, [['account_id' => $this->customer->account_id, 'amount' => '10000']]);

    app(ReverseVoucher::class)->handle($voucher, $this->user, 'Wrong customer');

    expect(CustomerReceipt::count())->toBe(0)
        ->and($invoice->fresh()->outstanding()->format())->toBe('10,000.00')
        ->and(($this->balance)('accounts_receivable', $this->customer))->toBe(1_000_000)
        ->and(($this->customerStatement)()->isConsistent())->toBeTrue();
});

it('refuses a customer on a payment voucher and a supplier on a journal voucher', function () {
    expect(fn () => ($this->post)(VoucherType::Payment, [['account_id' => $this->customer->account_id, 'amount' => '100']]))
        ->toThrow(ValidationException::class, 'refund');

    expect(fn () => app(SaveVoucherDraft::class)->handle(VoucherType::Journal, [
        'voucher_date' => '2026-09-20',
        'lines' => [
            ['account_id' => $this->supplier->account_id, 'side' => 'debit', 'amount' => '100'],
            ['account_id' => Account::bySlug('owners_capital')->id, 'side' => 'credit', 'amount' => '100'],
        ],
    ], $this->user))->toThrow(ValidationException::class, 'party adjustment');
});

it('fills the party from the head on the draft, whatever the form sent', function () {
    $draft = app(SaveVoucherDraft::class)->handle(VoucherType::Receipt, [
        'voucher_date' => '2026-09-20', 'money_account_id' => $this->bank->id,
        'lines' => [['account_id' => $this->customer->account_id, 'amount' => '100', 'party_id' => 999999]],
    ], $this->user);

    $line = $draft->lines->sole();

    expect($line->party_type)->toBe($this->customer->getMorphClass())
        ->and($line->party_id)->toBe($this->customer->id);
});

it('refuses another business\'s customer head', function () {
    $other = Business::create(['name' => 'Other Co', 'slug' => 'other-co', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($other->id);
    $stranger = Customer::create(['business_id' => $other->id, 'name' => 'Stranger', 'code' => 'STR']);
    $head = $stranger->fresh()->account_id;
    TenantScope::enter($this->business->id);

    expect(fn () => ($this->post)(VoucherType::Receipt, [['account_id' => $head, 'amount' => '100']]))
        ->toThrow(ValidationException::class);
});
