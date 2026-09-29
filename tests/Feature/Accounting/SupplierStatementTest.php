<?php

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\SupplierStatement;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierPayment;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * One supplier's account, told in documents: the mirror of the customer
 * statement. Owed = positive; a bill raises it, a payment lowers it; the
 * window opens with everything before `from`.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-16 10:00:00');

    $this->business = Business::create(['name' => 'Supplier Statement Co', 'slug' => 'supplier-statement-co', 'fiscal_year_start_month' => 7]);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Permission::findOrCreate('accounting.view', 'web');
    $reader = Role::firstOrCreate(['name' => 'Reader', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    $reader->givePermissionTo('accounting.view');

    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);

    $this->reader = User::factory()->create();
    $this->reader->businesses()->attach($this->business->id);
    $this->reader->assignRole($reader);

    $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'Padma Traders']);
    $this->bank = MoneyAccount::create([
        'business_id' => $this->business->id,
        'name' => 'BRAC Bank',
        'type' => 'bank',
        'account_id' => Account::bySlug('cash_at_bank')->id,
    ]);

    $this->bill = fn (string $total, string $date, string $number) => SupplierBill::create([
        'business_id' => $this->business->id,
        'supplier_id' => $this->supplier->id,
        'bill_number' => $number,
        'bill_date' => $date,
        'total' => $total,
        'created_by' => $this->reader->id,
    ]);

    $this->payment = fn (string $amount, string $date, ?string $reference = null) => SupplierPayment::create([
        'business_id' => $this->business->id,
        'supplier_id' => $this->supplier->id,
        'money_account_id' => $this->bank->id,
        'paid_on' => $date,
        'amount' => $amount,
        'reference' => $reference,
        'created_by' => $this->reader->id,
    ]);
});

it('carries everything before the window as the opening balance and runs the balance after it', function () {
    ($this->bill)('5000', '2026-07-05', 'SB-001');
    ($this->payment)('2000', '2026-07-20', 'CHQ-1');
    ($this->bill)('3000', '2026-08-03', 'SB-002');
    ($this->payment)('1000', '2026-08-10');

    $statement = SupplierStatement::for($this->supplier, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-16'));

    expect($statement->opening->format())->toBe('3,000.00')
        ->and($statement->charges->format())->toBe('3,000.00')
        ->and($statement->settlements->absolute()->format())->toBe('1,000.00')
        ->and($statement->closing()->format())->toBe('5,000.00')
        ->and($statement->lines->pluck('label')->all())->toBe(['Bill SB-002', 'Payment made'])
        ->and($statement->hasIndependentCheck())->toBeTrue()
        // Documents with no postings: the tie-out must SAY they disagree.
        ->and($statement->isConsistent())->toBeFalse();
});

it('puts same-day documents in a fixed order: bill, then payment', function () {
    ($this->payment)('500', '2026-08-05');
    ($this->bill)('900', '2026-08-05', 'SB-009');

    $statement = SupplierStatement::for($this->supplier, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-16'));

    expect($statement->lines->pluck('kind')->all())->toBe(['bill', 'payment'])
        ->and($statement->closing()->format())->toBe('400.00');
});

it('shows a supplier we have over-paid as a credit balance', function () {
    ($this->bill)('1000', '2026-08-02', 'SB-010');
    ($this->payment)('1500', '2026-08-04');

    $statement = SupplierStatement::for($this->supplier, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-16'));

    expect($statement->closing()->isCredit())->toBeTrue()
        ->and($statement->closing()->absolute()->format())->toBe('500.00');
});

it('renders the screen for someone who can read the books, with and without a supplier chosen', function () {
    ($this->bill)('1000', '2026-08-02', 'SB-011');

    $this->actingAs($this->reader)->get('/accounting/supplier-statement')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('accounting/supplier-statement/index')->where('supplier', null)->has('suppliers', 1));

    $this->actingAs($this->reader)->get('/accounting/supplier-statement?supplier='.$this->supplier->id.'&from=2026-08-01&to=2026-08-16')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('supplier.name', 'Padma Traders')->has('lines', 1)->where('summary.closing', '1,000.00'));
});

it('agrees with the ledger when the postings match the documents, and says so when they do not', function () {
    ($this->bill)('5000', '2026-07-05', 'SB-020');

    $payable = Account::bySlug('accounts_payable');
    $capital = Account::bySlug('owners_capital');
    $post = fn (string $date) => app(PostJournal::class)->handle(new JournalDraft(
        date: Carbon::parse($date),
        lines: [
            JournalLineDraft::debit($capital->id, Money::ofTaka('5000')),
            JournalLineDraft::credit($payable->id, Money::ofTaka('5000'), null, $this->supplier),
        ],
    ), $this->reader);

    expect(SupplierStatement::for($this->supplier, Carbon::parse('2026-07-01'), Carbon::parse('2026-08-16'))->isConsistent())->toBeFalse();

    $post('2026-07-05');

    $statement = SupplierStatement::for($this->supplier, Carbon::parse('2026-07-01'), Carbon::parse('2026-08-16'));

    expect($statement->hasIndependentCheck())->toBeTrue()
        ->and($statement->isConsistent())->toBeTrue()
        ->and($statement->ledgerClosing()->format())->toBe('5,000.00');
});
