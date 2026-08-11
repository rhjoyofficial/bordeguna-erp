<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Actions\RecordCustomerReceipt;
use App\Modules\Sales\Actions\RecordDelivery;
use App\Modules\Sales\Actions\RecordSalesInvoice;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\Models\Customer;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Sales slice 4: the money coming in.
 *
 * ⚠ The mirror of SupplierPaymentTest, deliberately. Accounts Receivable and
 * Accounts Payable behave identically, and one mental model covers both.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Receipt Co', 'slug' => 'receipt-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    (new SalesAccountMappingSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);

    $warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Savar', 'code' => 'SAVAR']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $product = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-HONEY',
        'name' => 'Sundarban Honey 500g',
        'type' => ProductType::FinishedGood,
    ]);
    $this->customer = Customer::create([
        'business_id' => $this->business->id,
        'name' => 'Karim Traders',
    ]);
    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);

    // ⚠ A money account with a ledger account behind it. Without the link the
    // receipt is refused outright rather than posted somewhere plausible.
    $this->cash = MoneyAccount::create([
        'business_id' => $this->business->id,
        'name' => 'Cash in Hand',
        'type' => 'cash',
        'account_id' => Account::bySlug('cash_in_hand')->id,
    ]);

    Carbon::setTestNow('2026-08-04 10:00:00');

    app(RecordStockIn::class)->handle($product, $warehouse, '100', '500', $this->user, occurredAt: '2026-08-01 09:00:00');

    $this->invoiceFor = function (string $qty, string $price) use ($warehouse, $product) {
        $delivery = app(RecordDelivery::class)->handle(
            customer: $this->customer,
            warehouse: $warehouse,
            lines: [['product' => $product, 'quantity' => $qty]],
            createdBy: $this->user,
            deliveredAt: '2026-08-02 10:00:00',
        );

        return app(RecordSalesInvoice::class)->handle(
            customer: $this->customer,
            lines: [['deliveryLine' => $delivery->lines->first(), 'quantity' => $qty, 'unitPrice' => $price]],
            createdBy: $this->user,
            invoiceDate: '2026-08-03',
        );
    };

    $this->receive = fn (array $allocations, ?string $ref = null) => app(RecordCustomerReceipt::class)->handle(
        customer: $this->customer,
        moneyAccount: $this->cash,
        receivedOn: '2026-08-04',
        allocations: $allocations,
        createdBy: $this->user,
        reference: $ref,
    );
});

afterEach(fn () => Carbon::setTestNow());

it('settles an invoice in full', function () {
    $invoice = ($this->invoiceFor)('10', '850');

    $receipt = ($this->receive)([['invoice' => $invoice, 'amount' => '8500']], 'bKash 8891');

    expect((float) $receipt->amount)->toBe(8500.0)
        ->and($invoice->fresh()->outstanding()->toTaka())->toBe('0.00')
        ->and($invoice->fresh()->isSettled())->toBeTrue();
});

it('derives what is still outstanding from the allocations', function () {
    $invoice = ($this->invoiceFor)('10', '850');

    ($this->receive)([['invoice' => $invoice, 'amount' => '3000']]);

    expect($invoice->fresh()->outstanding()->toTaka())->toBe('5500.00')
        ->and($invoice->fresh()->isSettled())->toBeFalse();

    ($this->receive)([['invoice' => $invoice->fresh(), 'amount' => '5500']]);

    expect($invoice->fresh()->outstanding()->toTaka())->toBe('0.00');
});

// ⚠ Receiving more than is owed is an advance from the customer, a different
// event against 2150. Absorbing it would make the receivable go negative.
it('refuses to receive more than is outstanding', function () {
    $invoice = ($this->invoiceFor)('10', '850');

    ($this->receive)([['invoice' => $invoice, 'amount' => '9000']]);
})->throws(ValidationException::class, 'outstanding');

it('refuses an invoice belonging to another customer', function () {
    $invoice = ($this->invoiceFor)('10', '850');
    $other = Customer::create(['business_id' => $this->business->id, 'name' => 'Someone Else']);

    app(RecordCustomerReceipt::class)->handle(
        customer: $other,
        moneyAccount: $this->cash,
        receivedOn: '2026-08-04',
        allocations: [['invoice' => $invoice, 'amount' => '100']],
        createdBy: $this->user,
    );
})->throws(ValidationException::class, 'different customer');

it('refuses a receipt that settles nothing', function () {
    ($this->receive)([]);
})->throws(ValidationException::class, 'which invoices');

it('settles several invoices with one receipt', function () {
    $first = ($this->invoiceFor)('4', '850');
    $second = ($this->invoiceFor)('6', '850');

    $receipt = ($this->receive)([
        ['invoice' => $first, 'amount' => '3400'],
        ['invoice' => $second, 'amount' => '5100'],
    ]);

    expect((float) $receipt->amount)->toBe(8500.0)
        ->and($receipt->allocations)->toHaveCount(2)
        ->and($first->fresh()->isSettled())->toBeTrue()
        ->and($second->fresh()->isSettled())->toBeTrue();
});

describe('the ledger', function () {
    it('debits the money account and credits Accounts Receivable', function () {
        $invoice = ($this->invoiceFor)('10', '850');
        $receipt = ($this->receive)([['invoice' => $invoice, 'amount' => '8500']]);

        $journal = Journal::where('source_type', $receipt->getMorphClass())
            ->where('source_id', $receipt->id)->first();
        $lines = JournalLine::where('journal_id', $journal->id)->get();

        expect($lines)->not->toBeEmpty()
            ->and((int) $lines->where('account_id', Account::bySlug('cash_in_hand')->id)->sum('amount_minor'))->toBe(850000)
            ->and((int) $lines->where('account_id', Account::bySlug('accounts_receivable')->id)->sum('amount_minor'))->toBe(-850000)
            ->and((int) $lines->sum('amount_minor'))->toBe(0);
    });

    // ⚠⚠ THE WHOLE POINT OF ONE ENGINE: invoice then receipt leaves Accounts
    // Receivable at exactly zero. At a counter both happen in one transaction
    // and the balance never really existed; on credit it stands open until paid.
    it('leaves Accounts Receivable at zero once the invoice is paid', function () {
        $invoice = ($this->invoiceFor)('10', '850');
        ($this->receive)([['invoice' => $invoice, 'amount' => '8500']]);

        $balance = JournalLine::where('account_id', Account::bySlug('accounts_receivable')->id)->sum('amount_minor');

        expect((int) $balance)->toBe(0);
    });

    it('names the customer on the receivable side', function () {
        $invoice = ($this->invoiceFor)('10', '850');
        $receipt = ($this->receive)([['invoice' => $invoice, 'amount' => '8500']]);

        $journal = Journal::where('source_type', $receipt->getMorphClass())
            ->where('source_id', $receipt->id)->first();
        $arLine = JournalLine::where('journal_id', $journal->id)
            ->where('account_id', Account::bySlug('accounts_receivable')->id)->first();

        expect($arLine->party_type)->toBe($this->customer->getMorphClass())
            ->and($arLine->party_id)->toBe($this->customer->id);
    });

    // ⚠ A money account with no ledger account behind it must be refused, not
    // posted somewhere plausible - that is how a cash book goes wrong in a way
    // nobody finds.
    it('refuses a money account with no ledger account behind it', function () {
        $unlinked = MoneyAccount::create([
            'business_id' => $this->business->id,
            'name' => 'Unlinked Wallet',
            'type' => 'mfs',
        ]);

        $invoice = ($this->invoiceFor)('10', '850');

        app(RecordCustomerReceipt::class)->handle(
            customer: $this->customer,
            moneyAccount: $unlinked,
            receivedOn: '2026-08-04',
            allocations: [['invoice' => $invoice, 'amount' => '100']],
            createdBy: $this->user,
        );
    })->throws(MappingNotFound::class);
});
