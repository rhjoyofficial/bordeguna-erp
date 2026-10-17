<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Enums\AgeingBucket;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\Ageing;
use App\Modules\Accounting\Support\AgeingRow;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierPayment;
use App\Modules\Purchasing\Models\SupplierPaymentAllocation;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\CustomerReceiptAllocation;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReturn;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Who owes what, and for how long.
 *
 * ⚠⚠ THE TEST THAT MATTERS MOST is the as-at one (C-GLA-8): an invoice paid
 * AFTER the report date must still show as outstanding on that date. Reusing
 * `SalesInvoice::outstanding()` would pass every other assertion in this file
 * and fail only that one, which is exactly why it is written out.
 *
 * ⚠ The bucket boundaries are asserted day by day. An off-by-one moves a
 * document into the wrong column without changing a single total, which is the
 * hardest kind of wrong to notice.
 *
 * See GENERAL-LEDGER-AND-AGEING-ARCHITECTURE.md.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-13 10:00:00');

    $this->business = Business::create([
        'name' => 'Ageing Co',
        'slug' => 'ageing-co',
        'fiscal_year_start_month' => 7,
    ]);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Permission::findOrCreate('accounting.view', 'web');

    $reader = Role::firstOrCreate(['name' => 'Reader', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    $reader->givePermissionTo('accounting.view');

    $outsider = Role::firstOrCreate(['name' => 'Outsider', 'business_id' => $this->business->id, 'guard_name' => 'web']);

    (new ChartOfAccountsSeeder)->run();
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

    $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'Sundarban Apiaries']);

    $this->bank = MoneyAccount::create([
        'business_id' => $this->business->id,
        'name' => 'BRAC Bank',
        'type' => 'bank',
        'account_id' => Account::bySlug('cash_at_bank')->id,
    ]);

    $this->invoice = function (string $total, string $invoiceDate, ?string $dueDate = null, ?Customer $customer = null) {
        static $sequence = 0;
        $sequence++;

        return SalesInvoice::create([
            'business_id' => $this->business->id,
            'customer_id' => ($customer ?? $this->customer)->id,
            'invoice_number' => 'INV-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
            'invoice_date' => $invoiceDate,
            'due_date' => $dueDate,
            'subtotal' => $total,
            'total' => $total,
            'created_by' => $this->reader->id,
        ]);
    };

    $this->receipt = function (string $amount, string $receivedOn, array $allocations = []) {
        $receipt = CustomerReceipt::create([
            'business_id' => $this->business->id,
            'customer_id' => $this->customer->id,
            'money_account_id' => $this->bank->id,
            'received_on' => $receivedOn,
            'amount' => $amount,
            'created_by' => $this->reader->id,
        ]);

        foreach ($allocations as [$invoice, $allocated]) {
            CustomerReceiptAllocation::create([
                'business_id' => $this->business->id,
                'customer_receipt_id' => $receipt->id,
                'sales_invoice_id' => $invoice->id,
                'amount' => $allocated,
            ]);
        }

        return $receipt;
    };

    $this->ar = fn (string $asAt = '2026-08-13') => Ageing::receivables(Carbon::parse($asAt)->startOfDay());
});

afterEach(fn () => Carbon::setTestNow());

// ─── C-GLA-8. THE AS-AT RULE ────────────────────────────────────────────────

it('does not subtract a receipt made after the report date', function () {
    $invoice = ($this->invoice)('10000', '2026-07-01', '2026-07-15');

    // Paid in full on 5 August. TODAY the invoice is settled.
    ($this->receipt)('10000', '2026-08-05', [[$invoice, '10000']]);

    // ⚠⚠ As at 31 July it was NOT paid, and an ageing that says otherwise has
    // just understated every overdue bucket in a month-end report. This is the
    // assertion that fails if the model's own outstanding() is reused.
    $july = ($this->ar)('2026-07-31');

    expect($july->total()->minor)->toBe(1000000)
        ->and($july->rows->first()->bucket(AgeingBucket::Days1To30)->minor)->toBe(1000000);

    // And as at today it is gone, with nothing left on account.
    $today = ($this->ar)('2026-08-13');

    expect($today->total()->minor)->toBe(0)
        ->and($today->rows)->toBeEmpty();
});

it('leaves an invoice raised after the report date out of it entirely', function () {
    ($this->invoice)('4000', '2026-08-20', '2026-08-20');

    expect(($this->ar)('2026-08-13')->total()->minor)->toBe(0);
});

it('treats a receipt allocated to a not-yet-raised invoice as money on account', function () {
    $future = ($this->invoice)('5000', '2026-08-20', '2026-08-20');
    ($this->receipt)('5000', '2026-08-01', [[$future, '5000']]);

    $ageing = ($this->ar)('2026-08-13');
    $row = $ageing->rows->first();

    // ⚠ The allocation is dropped with the invoice it points at, so the receipt
    // reads as the prepayment it was on that date rather than as a reduction of
    // an invoice that did not exist yet.
    expect($row->documents)->toBeEmpty()
        ->and($row->onAccount->minor)->toBe(-500000)
        ->and($ageing->total()->minor)->toBe(-500000);
});

// ─── C-GLA-10 AND THE BUCKET BOUNDARIES ─────────────────────────────────────

it('files a document by days past its due date, on every boundary', function () {
    // Due dates chosen so that on 2026-08-13 the document is exactly N days
    // overdue: 0, 1, 30, 31, 60, 61, 90, 91.
    $cases = [
        ['2026-08-13', 0, AgeingBucket::Current],
        ['2026-08-12', 1, AgeingBucket::Days1To30],
        ['2026-07-14', 30, AgeingBucket::Days1To30],
        ['2026-07-13', 31, AgeingBucket::Days31To60],
        ['2026-06-14', 60, AgeingBucket::Days31To60],
        ['2026-06-13', 61, AgeingBucket::Days61To90],
        ['2026-05-15', 90, AgeingBucket::Days61To90],
        ['2026-05-14', 91, AgeingBucket::Days90Plus],
    ];

    foreach ($cases as [$dueDate]) {
        ($this->invoice)('100', '2026-05-01', $dueDate);
    }

    $documents = ($this->ar)('2026-08-13')->rows->first()->documents
        ->keyBy(fn ($document) => $document->basisDate()->toDateString());

    foreach ($cases as [$dueDate, $days, $bucket]) {
        expect($documents[$dueDate]->daysOverdue)->toBe($days)
            ->and($documents[$dueDate]->bucket())->toBe($bucket);
    }
});

it('files a document not yet due as current, not as a negative age', function () {
    ($this->invoice)('100', '2026-08-01', '2026-09-30');

    $document = ($this->ar)('2026-08-13')->rows->first()->documents->first();

    expect($document->bucket())->toBe(AgeingBucket::Current)
        ->and($document->daysOverdue)->toBeLessThan(0);
});

it('treats a missing due date as due on the day the invoice was raised', function () {
    ($this->invoice)('100', '2026-07-20', null);

    $ageing = ($this->ar)('2026-08-13');
    $document = $ageing->rows->first()->documents->first();

    expect($document->usesFallbackDate())->toBeTrue()
        ->and($document->basisDate()->toDateString())->toBe('2026-07-20')
        ->and($document->daysOverdue)->toBe(24)
        ->and($ageing->usesFallbackDates())->toBeTrue();
});

// ─── C-GLA-11. MONEY ON ACCOUNT ─────────────────────────────────────────────

it('shows an unallocated receipt on account and still totals correctly', function () {
    $invoice = ($this->invoice)('10000', '2026-07-01', '2026-07-15');
    ($this->receipt)('4000', '2026-07-20', [[$invoice, '3000']]);   // 1,000 left over

    $ageing = ($this->ar)('2026-08-13');
    $row = $ageing->rows->first();

    expect($row->documents->first()->outstanding->minor)->toBe(700000)
        ->and($row->onAccount->minor)->toBe(-100000)
        // ⚠⚠ 10,000 invoiced less 4,000 received. The identity that makes the
        // report agree with the balance sheet.
        ->and($row->total()->minor)->toBe(600000)
        ->and($ageing->total()->minor)->toBe(600000);
});

it('keeps a party whose overdue invoice is exactly offset by money on account', function () {
    $invoice = ($this->invoice)('5000', '2026-07-01', '2026-07-01');
    $future = ($this->invoice)('5000', '2026-09-01', '2026-09-01');
    ($this->receipt)('5000', '2026-07-20', [[$future, '5000']]);

    $ageing = ($this->ar)('2026-08-13');

    // ⚠ Nets to zero, and is precisely the row somebody needs to see: the fix is
    // an allocation nobody has made. Testing the TOTAL for zero would have
    // hidden it.
    expect($ageing->rows)->toHaveCount(1)
        ->and($ageing->total()->minor)->toBe(0)
        ->and($ageing->rows->first()->bucket(AgeingBucket::Days31To60)->minor)->toBe(500000)
        ->and($ageing->rows->first()->onAccount->minor)->toBe(-500000);

    expect($invoice->fresh())->not->toBeNull();
});

// ─── C-GLA-13 AND C-GLA-14 ──────────────────────────────────────────────────

it('drops a settled invoice but keeps an over-allocated one, negative', function () {
    $settled = ($this->invoice)('1000', '2026-07-01', '2026-07-01');
    $overpaid = ($this->invoice)('1000', '2026-07-02', '2026-07-02');

    ($this->receipt)('2500', '2026-07-10', [[$settled, '1000'], [$overpaid, '1500']]);

    $row = ($this->ar)('2026-08-13')->rows->first();

    expect($row->documents)->toHaveCount(1)
        ->and($row->documents->first()->number)->toBe($overpaid->invoice_number)
        ->and($row->documents->first()->outstanding->minor)->toBe(-50000)
        ->and($row->total()->minor)->toBe(-50000);
});

it('omits a party with nothing outstanding and counts it', function () {
    $quiet = Customer::create([
        'business_id' => $this->business->id,
        'name' => 'Quiet Store',
        'code' => 'QUIET',
    ]);

    $invoice = ($this->invoice)('1000', '2026-07-01', '2026-07-01', $quiet);

    CustomerReceiptAllocation::create([
        'business_id' => $this->business->id,
        'customer_receipt_id' => CustomerReceipt::create([
            'business_id' => $this->business->id,
            'customer_id' => $quiet->id,
            'money_account_id' => $this->bank->id,
            'received_on' => '2026-07-10',
            'amount' => '1000',
            'created_by' => $this->reader->id,
        ])->id,
        'sales_invoice_id' => $invoice->id,
        'amount' => '1000',
    ]);

    ($this->invoice)('500', '2026-07-01', '2026-07-01');

    $ageing = ($this->ar)('2026-08-13');

    expect($ageing->rows)->toHaveCount(1)
        ->and($ageing->rows->first()->partyName)->toBe('Rahman Traders')
        ->and($ageing->partiesOmitted)->toBe(1);
});

// ─── C-GLA-12. THE TIE-OUT ──────────────────────────────────────────────────

it('reports a disagreement with the control account rather than hiding it', function () {
    (new SalesAccountMappingSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);

    // A document with NO posting behind it: the ledger says nothing is owed and
    // the documents say 5,000 is. The report must say they disagree.
    ($this->invoice)('5000', '2026-07-01', '2026-07-01');

    $ageing = ($this->ar)('2026-08-13');

    expect($ageing->controlBalance)->not->toBeNull()
        ->and($ageing->controlBalance->minor)->toBe(0)
        ->and($ageing->total()->minor)->toBe(500000)
        ->and($ageing->tiesOut())->toBeFalse()
        ->and($ageing->difference()->absolute()->minor)->toBe(500000);
});

it('cannot be checked at all when no control account is mapped', function () {
    ($this->invoice)('5000', '2026-07-01', '2026-07-01');

    $ageing = ($this->ar)('2026-08-13');

    // ⚠ No mapping, so no check - and `tiesOut()` says true rather than
    // reporting a break against a number that was never computed.
    expect($ageing->controlBalance)->toBeNull()
        ->and($ageing->controlAccountLabel)->toBeNull()
        ->and($ageing->tiesOut())->toBeTrue();
});

// ─── PAYABLES: THE MIRROR ───────────────────────────────────────────────────

it('ages what the business owes suppliers the same way, as at a date', function () {
    $bill = SupplierBill::create([
        'business_id' => $this->business->id,
        'supplier_id' => $this->supplier->id,
        'bill_number' => 'SB-001',
        'bill_date' => '2026-07-01',
        'due_date' => '2026-07-10',
        'total' => '8000',
        'created_by' => $this->reader->id,
    ]);

    $payment = SupplierPayment::create([
        'business_id' => $this->business->id,
        'supplier_id' => $this->supplier->id,
        'money_account_id' => $this->bank->id,
        'paid_on' => '2026-08-09',
        'amount' => '3000',
        'created_by' => $this->reader->id,
    ]);

    SupplierPaymentAllocation::create([
        'business_id' => $this->business->id,
        'supplier_payment_id' => $payment->id,
        'supplier_bill_id' => $bill->id,
        'amount' => '3000',
    ]);

    $before = Ageing::payables(Carbon::parse('2026-08-01')->startOfDay());
    $after = Ageing::payables(Carbon::parse('2026-08-13')->startOfDay());

    expect($before->total()->minor)->toBe(800000)
        ->and($before->rows->first()->bucket(AgeingBucket::Days1To30)->minor)->toBe(800000)
        ->and($after->total()->minor)->toBe(500000)
        ->and($after->rows->first()->partyName)->toBe('Sundarban Apiaries')
        ->and($after->rows->first()->bucket(AgeingBucket::Days31To60)->minor)->toBe(500000);
});

// ─── THE SCREENS ────────────────────────────────────────────────────────────

it('shows the receivables ageing with the figures the engine computed', function () {
    ($this->invoice)('12500', '2026-06-20', '2026-06-25');

    $this->actingAs($this->reader)
        ->get('/accounting/receivables-ageing')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('accounting/ageing/index')
            ->where('kind', 'receivables')
            ->where('label', 'Accounts Receivable')
            ->where('as_at', '2026-08-13')
            ->has('rows', 1)
            ->where('rows.0.party_name', 'Rahman Traders')
            ->where('rows.0.buckets.days_31_60', '12,500.00')
            ->where('rows.0.total', '12,500.00')
            ->where('totals.total', '12,500.00')
        );
});

it('shows the payables ageing on its own route', function () {
    SupplierBill::create([
        'business_id' => $this->business->id,
        'supplier_id' => $this->supplier->id,
        'bill_number' => 'SB-002',
        'bill_date' => '2026-08-12',
        'due_date' => '2026-08-30',
        'total' => '900',
        'created_by' => $this->reader->id,
    ]);

    $this->actingAs($this->reader)
        ->get('/accounting/payables-ageing')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('accounting/ageing/index')
            ->where('kind', 'payables')
            ->where('party_label', 'Supplier')
            ->where('rows.0.buckets.current', '900.00')
        );
});

it('honours the as-at date on the screen', function () {
    $invoice = ($this->invoice)('1000', '2026-07-01', '2026-07-01');
    ($this->receipt)('1000', '2026-08-05', [[$invoice, '1000']]);

    $this->actingAs($this->reader)
        ->get('/accounting/receivables-ageing?as_at=2026-07-31')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('totals.total', '1,000.00'));

    $this->actingAs($this->reader)
        ->get('/accounting/receivables-ageing')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('totals.total', '0.00')->has('rows', 0));
});

it('keeps both ageings away from a user who cannot view accounting', function () {
    $this->actingAs($this->outsider)->get('/accounting/receivables-ageing')->assertForbidden();
    $this->actingAs($this->outsider)->get('/accounting/payables-ageing')->assertForbidden();
});

it('sums the same figures whichever way the rows are read', function () {
    ($this->invoice)('1000', '2026-07-01', '2026-07-01');
    ($this->invoice)('2000', '2026-08-01', '2026-08-30');

    $ageing = ($this->ar)('2026-08-13');

    $byBucket = Money::sum(collect(AgeingBucket::ordered())->map(fn (AgeingBucket $bucket) => $ageing->bucket($bucket)));
    $byRow = Money::sum($ageing->rows->map(fn (AgeingRow $row) => $row->total()));

    expect($byBucket->plus($ageing->onAccount())->minor)->toBe($byRow->minor)
        ->and($byRow->minor)->toBe(300000);
});

// F33 (practice run 17 Oct 2026): a dealer's 807.50 return credit (we owe him)
// showed as "-807.50" in red under money owed to us. The headline now splits
// the two sides; the total that ties to 1140 is untouched.
it('presents a customer in credit after a return as money we owe, not a negative debt', function () {
    $dealer = Customer::create(['business_id' => $this->business->id, 'name' => 'Practice Dealer Store', 'code' => 'DEALER']);
    $warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);
    $delivery = Delivery::create([
        'business_id' => $this->business->id, 'customer_id' => $dealer->id, 'warehouse_id' => $warehouse->id,
        'delivery_number' => 'DN-1', 'delivered_at' => '2026-08-01 09:00:00', 'created_by' => $this->reader->id,
    ]);
    SalesReturn::create([
        'business_id' => $this->business->id, 'customer_id' => $dealer->id, 'delivery_id' => $delivery->id,
        'return_number' => 'SR-1', 'returned_at' => '2026-08-02', 'credit_total' => '807.50', 'created_by' => $this->reader->id,
    ]);
    ($this->invoice)('12500', '2026-08-01', '2026-08-31');

    $ageing = ($this->ar)();

    expect($ageing->total()->format())->toBe('11,692.50')
        ->and($ageing->totalOwing()->format())->toBe('12,500.00')
        ->and($ageing->totalInCredit()->format())->toBe('807.50');

    $this->actingAs($this->reader)
        ->get('/accounting/receivables-ageing')
        ->assertOk()
        ->assertInertia(function ($page) {
            $props = $page->toArray()['props'];
            $dealer = collect($props['rows'])->firstWhere('party_name', 'Practice Dealer Store');
            $rahman = collect($props['rows'])->firstWhere('party_name', 'Rahman Traders');

            expect($props['totals']['owing'])->toBe('12,500.00')
                ->and($props['totals']['in_credit'])->toBe('807.50')
                // The ledger tie-out figure stays signed and whole.
                ->and($props['totals']['total'])->toBe('11,692.50')
                ->and($dealer['in_credit'])->toBeTrue()
                ->and($dealer['total_absolute'])->toBe('807.50')
                ->and($rahman['in_credit'])->toBeFalse();
        });
});
