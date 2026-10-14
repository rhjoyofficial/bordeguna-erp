<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Support\Ageing;
use App\Modules\Accounting\Support\LedgerIntegrity;
use App\Modules\Accounting\Support\SubledgerReconciliation;
use App\Modules\Accounting\Support\SupplierStatement;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Actions\RecordExpenseVoucher;
use App\Modules\Purchasing\Actions\RecordGoodsReceipt;
use App\Modules\Purchasing\Actions\RecordPurchaseReturn;
use App\Modules\Purchasing\Actions\RecordSupplierBill;
use App\Modules\Purchasing\Actions\RecordSupplierCreditNote;
use App\Modules\Purchasing\Actions\RecordSupplierPayment;
use App\Modules\Purchasing\Actions\ReverseExpenseVoucher;
use App\Modules\Purchasing\Actions\ReverseSupplierBill;
use App\Modules\Purchasing\Actions\ReverseSupplierCreditNote;
use App\Modules\Purchasing\Actions\ReverseSupplierPayment;
use App\Modules\Purchasing\database\seeders\PurchasingPermissionsSeeder;
use App\Modules\Purchasing\Enums\RecurringOccurrenceStatus;
use App\Modules\Purchasing\Models\RecurringExpense;
use App\Modules\Purchasing\Models\RecurringExpenseOccurrence;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierCreditNote;
use App\Modules\Purchasing\Models\SupplierPayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * PUR-003 (owner decision 14 Oct 2026): a supplier payment, a supplier credit
 * note and an expense voucher recorded in Purchasing are corrected by
 * REVERSING them, as a bill already is.
 *
 * A reason is required; the reversal is dated today unless the user chooses
 * the original date and that period is still open. The reversal is a mirror
 * journal through ReverseJournal, the document is kept and marked, and what it
 * settled is restored: a reversed payment reopens the bills it paid, a reversed
 * credit note puts the bill's balance and the return's claim back.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-10 10:00:00');

    $this->business = Business::create(['name' => 'Undo Co', 'slug' => 'undo-co', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new PurchasingPermissionsSeeder)->run();
    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    TenantScope::enter($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->person = function (string $role): User {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $user;
    };
    $this->user = ($this->person)('Owner');

    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);
    $product = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'JAR-500', 'name' => 'Glass jar 500ml', 'type' => ProductType::RawMaterial,
    ]);
    $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'Dhaka Glassware', 'code' => 'SUP-001']);

    $order = app(CreatePurchaseOrder::class)->handle(
        supplier: $this->supplier, warehouse: $warehouse,
        lines: [['product' => $product, 'quantity' => '100', 'unit_cost' => '25.00']],
        createdBy: $this->user,
    );
    approveIfPending($order->approvalRequest, $this->user);
    $this->order = $order->fresh(['lines']);
    $this->line = $this->order->lines->first();

    // Received 40 and billed 1,000 on 3 Aug; 10 (250) went back after the bill.
    app(RecordGoodsReceipt::class)->handle($this->order, [['line' => $this->line, 'quantity' => '40']], $this->user);
    $this->bill = app(RecordSupplierBill::class)->handle(
        supplier: $this->supplier, billNumber: 'INV-9001', billDate: '2026-08-03',
        lines: [['line' => $this->line, 'quantity' => '40', 'unitCost' => '25.00']],
        createdBy: $this->user, purchaseOrder: $this->order,
    );
    $this->return = app(RecordPurchaseReturn::class)->handle($this->order, [['line' => $this->line, 'quantity' => '10']], $this->user);

    $this->cash = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'Office cash box',
        'type' => MoneyAccountType::Cash, 'account_id' => Account::bySlug('cash_in_hand')->id,
    ]);

    $this->pay = fn (string $amount = '600') => app(RecordSupplierPayment::class)->handle(
        supplier: $this->supplier, moneyAccount: $this->cash, paidOn: '2026-08-03',
        allocations: [['bill' => $this->bill->fresh(), 'amount' => $amount]], createdBy: $this->user,
    );
    $this->credit = fn (string $number = 'CN-1') => app(RecordSupplierCreditNote::class)->handle(
        supplier: $this->supplier, purchaseReturn: $this->return->fresh(), creditNoteNumber: $number,
        creditDate: '2026-08-03', allocations: [['bill' => $this->bill->fresh(), 'amount' => '250']], createdBy: $this->user,
    );
    $this->spend = fn () => app(RecordExpenseVoucher::class)->handle(
        moneyAccount: $this->cash, spentOn: '2026-08-03',
        lines: [['account' => Account::where('code', '6051')->sole(), 'amount' => '1800.00', 'description' => 'August rent']],
        createdBy: $this->user, paidTo: 'Landlord',
    );

    $this->balanceOn = fn (string $slug) => (int) JournalLine::where('account_id', Account::bySlug($slug)->id)->sum('amount_minor');
    $this->booksAgree = fn () => expect(LedgerIntegrity::verifyAllBusinesses()['problems'])->toBe([])
        ->and(SubledgerReconciliation::reconcileAllBusinesses()['problems'])->toBe([])
        ->and(Ageing::payables(Carbon::today())->total()->minor)->toBe(-($this->balanceOn)('accounts_payable'));
});

afterEach(fn () => Carbon::setTestNow());

it('reverses a supplier payment: the bank and Accounts Payable come back and the bill is open again', function () {
    $payment = ($this->pay)('600');

    expect($this->bill->fresh()->outstanding()->toTaka())->toBe('400.00');

    $reversed = app(ReverseSupplierPayment::class)->handle($payment, $this->user, 'Paid the wrong supplier');

    expect(($this->balanceOn)('accounts_payable'))->toBe(-100000)
        ->and(($this->balanceOn)('cash_in_hand'))->toBe(0)
        ->and($this->bill->fresh()->outstanding()->toTaka())->toBe('1000.00')
        ->and($reversed->isReversed())->toBeTrue()
        ->and($reversed->reversal_reason)->toBe('Paid the wrong supplier')
        ->and($reversed->reversed_by)->toBe($this->user->id)
        // Dated today by default, never quietly back-dated.
        ->and($reversed->reversed_on->toDateString())->toBe('2026-08-10')
        ->and($reversed->reversalJournal->journal_date->toDateString())->toBe('2026-08-10')
        ->and($reversed->reversalJournal->reverses_journal_id)->not->toBeNull()
        // What it settled is kept for the record.
        ->and(canonicalJson($reversed->reversed_allocations))->toBe(canonicalJson([['supplier_bill_id' => $this->bill->id, 'bill_number' => 'INV-9001', 'amount' => '600.00']]))
        ->and($reversed->allocations()->count())->toBe(0);

    ($this->booksAgree)();

    // The bill it froze can now be corrected too.
    app(ReverseSupplierBill::class)->handle($this->bill->fresh(), $this->user, 'Wrong supplier');
    ($this->booksAgree)();

    // The supplier statement shows both the payment and its reversal and still ties to the ledger.
    $statement = SupplierStatement::for($this->supplier, Carbon::parse('2026-08-01'), Carbon::today());
    expect($statement->isConsistent())->toBeTrue()
        ->and($statement->lines->pluck('kind')->all())->toContain('payment_reversed');

    expect(Activity::query()->where('subject_type', $payment->getMorphClass())->where('subject_id', $payment->id)->where('description', 'reversed')->exists())->toBeTrue();
});

it('needs a reason, reverses once, and leaves a voucher-made payment to its voucher', function () {
    $payment = ($this->pay)();

    expect(fn () => app(ReverseSupplierPayment::class)->handle($payment, $this->user, '  '))
        ->toThrow(ValidationException::class, 'Say why');

    app(ReverseSupplierPayment::class)->handle($payment, $this->user, 'Typed 600 for 60');

    expect(fn () => app(ReverseSupplierPayment::class)->handle($payment->fresh(), $this->user, 'Again'))
        ->toThrow(ValidationException::class, 'already reversed');

    $fromVoucher = ($this->pay)('100');
    // As if a payment voucher had made it (finance decision C3).
    $voucherId = DB::table('vouchers')->insertGetId([
        'business_id' => $this->business->id, 'type' => 'payment', 'voucher_date' => '2026-08-03',
        'created_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $fromVoucher->forceFill(['voucher_id' => $voucherId])->saveQuietly();

    expect(fn () => app(ReverseSupplierPayment::class)->handle($fromVoucher->fresh(), $this->user, 'Wrong'))
        ->toThrow(ValidationException::class, 'Reverse the voucher');
});

it('reverses on the original date only when that period is open', function () {
    $payment = ($this->pay)();

    AccountingPeriod::create(['business_id' => $this->business->id, 'closed_through' => '2026-08-05', 'closed_by' => $this->user->id]);

    expect(fn () => app(ReverseSupplierPayment::class)->handle($payment, $this->user, 'Wrong bill', onOriginalDate: true))
        ->toThrow(PeriodClosedException::class);

    expect($payment->fresh()->isReversed())->toBeFalse();

    AccountingPeriod::query()->delete();

    $reversed = app(ReverseSupplierPayment::class)->handle($payment, $this->user, 'Wrong bill', onOriginalDate: true);

    expect($reversed->reversed_on->toDateString())->toBe('2026-08-03')
        ->and($reversed->reversalJournal->journal_date->toDateString())->toBe('2026-08-03');

    ($this->booksAgree)();
});

it('reverses a supplier credit note: the bill owes again and the return can be credited again', function () {
    $credit = ($this->credit)();

    expect(($this->balanceOn)('goods_received_not_invoiced'))->toBe(0);

    $reversed = app(ReverseSupplierCreditNote::class)->handle($credit, $this->user, 'Supplier cancelled the credit note');

    expect(($this->balanceOn)('goods_received_not_invoiced'))->toBe(25000)
        ->and(($this->balanceOn)('accounts_payable'))->toBe(-100000)
        ->and($this->bill->fresh()->outstanding()->toTaka())->toBe('1000.00')
        ->and($this->return->fresh()->creditedTotal()->toTaka())->toBe('0.00')
        ->and($reversed->isReversed())->toBeTrue()
        ->and(canonicalJson($reversed->reversed_allocations))->toBe(canonicalJson([['supplier_bill_id' => $this->bill->id, 'bill_number' => 'INV-9001', 'amount' => '250.00']]));

    ($this->booksAgree)();

    // The corrected credit note.
    ($this->credit)('CN-2');

    expect(SupplierCreditNote::query()->whereNull('reversed_at')->count())->toBe(1);
    ($this->booksAgree)();
});

it('reverses an expense voucher and reopens the standing-cost month it settled', function () {
    $voucher = ($this->spend)();

    $standing = RecurringExpense::query()->create([
        'business_id' => $this->business->id, 'name' => 'Office rent', 'expense_account_id' => Account::where('code', '6051')->sole()->id,
        'amount' => '1800', 'frequency' => 'monthly', 'day_of_month' => 3, 'start_date' => '2026-08-01', 'created_by' => $this->user->id,
    ]);
    $month = RecurringExpenseOccurrence::query()->create([
        'business_id' => $this->business->id, 'recurring_expense_id' => $standing->id, 'due_on' => '2026-08-03',
        'period_label' => 'August 2026', 'expected_amount' => '1800', 'status' => RecurringOccurrenceStatus::Recorded, 'expense_voucher_id' => $voucher->id,
        'settled_by' => $this->user->id, 'settled_at' => now(), 'source_key' => 'test-1',
    ]);

    $reversed = app(ReverseExpenseVoucher::class)->handle($voucher, $this->user, 'Rent was paid by bank, not cash');

    expect((int) JournalLine::where('account_id', Account::where('code', '6051')->sole()->id)->sum('amount_minor'))->toBe(0)
        ->and(($this->balanceOn)('cash_in_hand'))->toBe(0)
        ->and($reversed->isReversed())->toBeTrue()
        ->and($month->fresh()->status)->toBe(RecurringOccurrenceStatus::Due)
        ->and($month->fresh()->expense_voucher_id)->toBeNull();

    expect(LedgerIntegrity::verifyAllBusinesses()['problems'])->toBe([]);
});

it('reverses through the screens for whoever may record the document, and nobody else', function () {
    $payment = ($this->pay)();
    $credit = ($this->credit)();
    $voucher = ($this->spend)();
    $staff = ($this->person)('Staff');

    $this->actingAs($staff)->post("/purchasing/payments/{$payment->id}/reverse", ['reason' => 'No'])->assertForbidden();
    $this->actingAs($staff)->post("/purchasing/credit-notes/{$credit->id}/reverse", ['reason' => 'No'])->assertForbidden();
    $this->actingAs($staff)->post("/purchasing/expenses/{$voucher->id}/reverse", ['reason' => 'No'])->assertForbidden();

    $this->actingAs($this->user)->post("/purchasing/payments/{$payment->id}/reverse", ['reason' => ''])
        ->assertSessionHasErrors('reason');

    $this->actingAs($this->user)->post("/purchasing/payments/{$payment->id}/reverse", ['reason' => 'Wrong amount'])
        ->assertSessionHas('success');
    $this->actingAs($this->user)->post("/purchasing/credit-notes/{$credit->id}/reverse", ['reason' => 'Cancelled', 'on_original_date' => true])
        ->assertSessionHas('success');
    $this->actingAs($this->user)->post("/purchasing/expenses/{$voucher->id}/reverse", ['reason' => 'Duplicate'])
        ->assertSessionHas('success');

    $props = fn (string $url) => $this->actingAs($this->user)->get($url)->assertOk()->original->getData()['page']['props'];

    expect($props("/purchasing/payments/{$payment->id}")['payment']['reversal']['reason'])->toBe('Wrong amount')
        ->and($props("/purchasing/payments/{$payment->id}")['can_reverse'])->toBeFalse()
        ->and($props("/purchasing/credit-notes/{$credit->id}")['creditNote']['reversal']['on'])->toBe('2026-08-03')
        ->and(collect($props('/purchasing/expenses')['vouchers']['data'])->firstWhere('id', $voucher->id)['reversal']['reason'])->toBe('Duplicate')
        ->and(SupplierPayment::query()->whereNull('reversed_at')->count())->toBe(0)
        ->and(Journal::query()->whereNotNull('reverses_journal_id')->count())->toBe(3);

    ($this->booksAgree)();
});
