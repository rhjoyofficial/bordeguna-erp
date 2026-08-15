<?php

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\CustomerStatement;
use App\Modules\Accounting\Support\CustomerStatementLine;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\CustomerReceiptAllocation;
use App\Modules\Sales\Models\CustomerRefund;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReturn;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * One customer's account, told in documents and checked by the ledger.
 *
 * ⚠⚠ The properties worth testing: the TOTAL ORDER (C-CST-3, same-day documents
 * come back in a fixed order or two readers see two different running
 * balances), the WINDOW SPLIT (opening carries everything before `from`),
 * ALLOCATION INDEPENDENCE (C-CST-2, allocating a receipt must not move the
 * statement), and the TIE-OUT in both directions (C-CST-1, agree when
 * documents and postings match, flag when a document has no posting).
 *
 * See CUSTOMER-STATEMENT-ARCHITECTURE.md.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-16 10:00:00');

    $this->business = Business::create([
        'name' => 'Statement Co',
        'slug' => 'statement-co',
        'fiscal_year_start_month' => 7,
    ]);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Permission::findOrCreate('accounting.view', 'web');

    $reader = Role::firstOrCreate(['name' => 'Reader', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    $reader->givePermissionTo('accounting.view');

    $outsider = Role::firstOrCreate(['name' => 'Outsider', 'business_id' => $this->business->id, 'guard_name' => 'web']);

    (new ChartOfAccountsSeeder)->run();
    (new SalesAccountMappingSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);

    $this->reader = User::factory()->create();
    $this->reader->businesses()->attach($this->business->id);
    $this->reader->assignRole($reader);

    $this->outsider = User::factory()->create();
    $this->outsider->businesses()->attach($this->business->id);
    $this->outsider->assignRole($outsider);

    $this->customer = Customer::create([
        'business_id' => $this->business->id,
        'name' => 'Rahman Traders',
        'code' => 'RAHMAN',
    ]);

    $this->bank = MoneyAccount::create([
        'business_id' => $this->business->id,
        'name' => 'BRAC Bank',
        'type' => 'bank',
        'account_id' => Account::bySlug('cash_at_bank')->id,
    ]);

    $this->receivable = Account::bySlug('accounts_receivable');
    $this->capital = Account::bySlug('owners_capital');

    // ⚠ Documents WITHOUT postings, on purpose: half the tests need the two
    // sides to disagree. `postAr` adds the ledger side when a test wants
    // agreement.
    $this->invoice = function (string $total, string $date) {
        static $sequence = 0;
        $sequence++;

        return SalesInvoice::create([
            'business_id' => $this->business->id,
            'customer_id' => $this->customer->id,
            'invoice_number' => 'INV-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
            'invoice_date' => $date,
            'subtotal' => $total,
            'total' => $total,
            'created_by' => $this->reader->id,
        ]);
    };

    $this->receipt = fn (string $amount, string $date, ?string $reference = null) => CustomerReceipt::create([
        'business_id' => $this->business->id,
        'customer_id' => $this->customer->id,
        'money_account_id' => $this->bank->id,
        'received_on' => $date,
        'amount' => $amount,
        'reference' => $reference,
        'created_by' => $this->reader->id,
    ]);

    $this->return = function (string $total, string $date) {
        static $sequence = 0;
        $sequence++;

        // ⚠ A return points at a delivery by schema; one bare delivery serves
        // every return in this file - the statement never reads it.
        $this->warehouse ??= Warehouse::create([
            'business_id' => $this->business->id,
            'name' => 'Main',
            'code' => 'MAIN',
        ]);
        $this->delivery ??= Delivery::create([
            'business_id' => $this->business->id,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'delivery_number' => 'DN-0001',
            'delivered_at' => '2026-07-01 09:00:00',
            'created_by' => $this->reader->id,
        ]);

        return SalesReturn::create([
            'business_id' => $this->business->id,
            'customer_id' => $this->customer->id,
            'delivery_id' => $this->delivery->id,
            'return_number' => 'SR-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
            'returned_at' => $date,
            'credit_total' => $total,
            'created_by' => $this->reader->id,
        ]);
    };

    $this->refund = fn (string $amount, string $date, SalesReturn $return) => CustomerRefund::create([
        'business_id' => $this->business->id,
        'customer_id' => $this->customer->id,
        'sales_return_id' => $return->id,
        'money_account_id' => $this->bank->id,
        'refunded_on' => $date,
        'amount' => $amount,
        'created_by' => $this->reader->id,
    ]);

    $this->postAr = function (string $amount, string $date, bool $debit = true) {
        app(PostJournal::class)->handle(new JournalDraft(
            date: Carbon::parse($date),
            lines: $debit
                ? [
                    JournalLineDraft::debit($this->receivable->id, Money::ofTaka($amount), null, $this->customer),
                    JournalLineDraft::credit($this->capital->id, Money::ofTaka($amount)),
                ]
                : [
                    JournalLineDraft::credit($this->receivable->id, Money::ofTaka($amount), null, $this->customer),
                    JournalLineDraft::debit($this->capital->id, Money::ofTaka($amount)),
                ],
        ), $this->reader);
    };

    $this->statement = fn (string $from = '2026-07-01', string $to = '2026-08-31') => CustomerStatement::for(
        $this->customer,
        Carbon::parse($from)->startOfDay(),
        Carbon::parse($to)->endOfDay(),
    );
});

afterEach(fn () => Carbon::setTestNow());

// ─── C-CST-3. THE ORDER AND THE RUNNING BALANCE ─────────────────────────────

it('runs the balance through all four document kinds in order', function () {
    ($this->invoice)('10000', '2026-07-05');
    ($this->receipt)('4000', '2026-07-10');
    $return = ($this->return)('1000', '2026-07-12');
    ($this->refund)('500', '2026-07-15', $return);

    $statement = ($this->statement)();

    expect($statement->lines->map(fn (CustomerStatementLine $line) => $line->kind)->all())
        ->toBe(['invoice', 'receipt', 'return', 'refund'])
        ->and($statement->lines->map(fn (CustomerStatementLine $line) => $line->balance->minor)->all())
        ->toBe([1000000, 600000, 500000, 550000])
        ->and($statement->closing()->minor)->toBe(550000);
});

it('orders same-day documents charge before settlement, never by chance', function () {
    // ⚠ Both on ONE day. Date alone cannot order these; the kind rank can.
    ($this->receipt)('4000', '2026-07-05');
    ($this->invoice)('10000', '2026-07-05');

    $kinds = ($this->statement)()->lines->map(fn (CustomerStatementLine $line) => $line->kind)->all();

    expect($kinds)->toBe(['invoice', 'receipt']);
});

// ─── THE WINDOW SPLIT ───────────────────────────────────────────────────────

it('opens with everything before the window and lists none of it', function () {
    ($this->invoice)('9000', '2026-06-20');   // before the window
    ($this->receipt)('2000', '2026-07-10');

    $statement = ($this->statement)();

    expect($statement->opening->minor)->toBe(900000)
        ->and($statement->lines)->toHaveCount(1)
        ->and($statement->closing()->minor)->toBe(700000);
});

// ─── C-CST-2. ALLOCATION INDEPENDENCE ───────────────────────────────────────

it('reads identically whether or not the receipt is allocated to the invoice', function () {
    $invoice = ($this->invoice)('10000', '2026-07-05');
    $receipt = ($this->receipt)('4000', '2026-07-10');

    $before = ($this->statement)();

    CustomerReceiptAllocation::create([
        'business_id' => $this->business->id,
        'customer_receipt_id' => $receipt->id,
        'sales_invoice_id' => $invoice->id,
        'amount' => '4000',
    ]);

    $after = ($this->statement)();

    expect($after->closing()->minor)->toBe($before->closing()->minor)
        ->and($after->lines)->toHaveCount($before->lines->count());
});

// ─── C-CST-1. THE TIE-OUT ───────────────────────────────────────────────────

it('agrees with the ledger when every document has its posting', function () {
    ($this->invoice)('10000', '2026-07-05');
    ($this->postAr)('10000', '2026-07-05');

    ($this->receipt)('4000', '2026-07-10');
    ($this->postAr)('4000', '2026-07-10', debit: false);

    $statement = ($this->statement)();

    expect($statement->hasIndependentCheck())->toBeTrue()
        ->and($statement->isConsistent())->toBeTrue()
        ->and($statement->ledgerClosing()->minor)->toBe(600000);
});

it('flags a document that never reached the ledger', function () {
    ($this->invoice)('10000', '2026-07-05');   // no posting

    $statement = ($this->statement)();

    expect($statement->hasIndependentCheck())->toBeTrue()
        ->and($statement->isConsistent())->toBeFalse();
});

it('checks the opening end of the window too, not just the closing', function () {
    // Documents and postings agree INSIDE the window; the pre-window invoice
    // has no posting, so opening disagrees while movement agrees. A
    // closing-only check would... also fail here (the discrepancy carries
    // through), so the sharper case: pre-window disagreement exactly offset by
    // an in-window one. Only an opening check catches that.
    ($this->invoice)('9000', '2026-06-20');            // doc, no posting
    ($this->postAr)('9000', '2026-07-05');             // posting, no doc, in window
    ($this->invoice)('9000', '2026-07-05');            // doc + posting pair
    ($this->postAr)('9000', '2026-07-05');

    $statement = ($this->statement)();

    // Closing agrees (18,000 both sides); opening does not (9,000 vs 0).
    expect($statement->closing()->minor)->toBe(1800000)
        ->and($statement->ledgerClosing()->minor)->toBe(1800000)
        ->and($statement->isConsistent())->toBeFalse();
});

// ─── THE SCREEN ─────────────────────────────────────────────────────────────

it('shows the statement with formatted figures and the tie-out', function () {
    ($this->invoice)('10000', '2026-07-05');
    ($this->postAr)('10000', '2026-07-05');
    ($this->receipt)('4000', '2026-07-10', 'bKash TrxID 8801');
    ($this->postAr)('4000', '2026-07-10', debit: false);

    $this->actingAs($this->reader)
        ->get("/accounting/customer-statement?customer={$this->customer->id}&from=2026-07-01&to=2026-08-31")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('accounting/customer-statement/index')
            ->where('customer.name', 'Rahman Traders')
            ->where('lines.0.label', 'Invoice INV-0001')
            ->where('lines.0.charge', '10,000.00')
            ->where('lines.1.label', 'Payment received')
            ->where('lines.1.reference', 'bKash TrxID 8801')
            ->where('lines.1.settlement', '4,000.00')
            ->where('lines.1.balance', '6,000.00')
            ->where('summary.closing', '6,000.00')
            ->where('summary.closing_side', 'debit')
            ->where('summary.checked', true)
            ->where('summary.consistent', true)
        );
});

it('asks for a customer before showing anything', function () {
    $this->actingAs($this->reader)
        ->get('/accounting/customer-statement')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('customer', null)
            ->where('lines', [])
            ->where('summary', null)
        );
});

it('keeps the statement away from a user who cannot view accounting', function () {
    $this->actingAs($this->outsider)->get('/accounting/customer-statement')->assertForbidden();
});
