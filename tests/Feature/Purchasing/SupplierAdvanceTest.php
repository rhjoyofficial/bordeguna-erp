<?php

use App\Models\User;
use App\Modules\Accounting\Actions\PostVoucher;
use App\Modules\Accounting\Actions\RecordPartyAdjustment;
use App\Modules\Accounting\Actions\ReverseVoucher;
use App\Modules\Accounting\Actions\SaveVoucherDraft;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Enums\VoucherType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Models\PartyAdjustment;
use App\Modules\Accounting\Support\Ageing;
use App\Modules\Accounting\Support\SubledgerReconciliation;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Actions\ApplySupplierAdvances;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Actions\RecordGoodsReceipt;
use App\Modules\Purchasing\Actions\RecordSupplierAdvance;
use App\Modules\Purchasing\Actions\RecordSupplierBill;
use App\Modules\Purchasing\Actions\RecordSupplierPayment;
use App\Modules\Purchasing\Actions\RefundSupplierAdvance;
use App\Modules\Purchasing\Actions\ReverseSupplierAdvance;
use App\Modules\Purchasing\Actions\ReverseSupplierAdvanceApplication;
use App\Modules\Purchasing\Actions\ReverseSupplierAdvanceRefund;
use App\Modules\Purchasing\Actions\ReverseSupplierBill;
use App\Modules\Purchasing\Actions\ReverseSupplierPayment;
use App\Modules\Purchasing\database\seeders\PurchasingPermissionsSeeder;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierAdvance;
use App\Modules\Purchasing\Models\SupplierAdvanceApplication;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierPayment;
use App\Modules\Purchasing\Models\WithholdingRate;
use App\Modules\Purchasing\Support\SupplierAdvanceAgeing;
use App\Modules\Purchasing\Support\SupplierAdvanceBook;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Supplier advances (supplier finance plan A2, ruling P2, 18 Oct 2026): paid
 * before the bill (Dr 1160 / Cr bank), applied to bills (Dr 2110 / Cr 1160),
 * refunded (Dr bank / Cr 1160), opened at cutover (Dr 1160 / Cr 3900), each
 * reversed by a reason in dependency order. Ruling tests 8 to 16, adapted.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-20 10:00:00');

    $this->business = Business::create(['name' => 'Advance Co', 'slug' => 'advance-co', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);

    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);

    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(InventoryAccountMappingSeeder::class);
    TenantScope::enter($this->business->id);

    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Piece', 'abbreviation' => 'pc']);
    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Savar', 'code' => 'SAV']);
    $this->product = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'JAR-500', 'name' => 'Glass jar 500ml', 'type' => ProductType::RawMaterial,
    ]);
    $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'Padma Glass', 'code' => 'SUP-001', 'supplier_type' => 'goods']);
    $this->bank = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'BRAC Bank', 'type' => MoneyAccountType::Bank,
        'account_id' => Account::bySlug('cash_at_bank')->id,
    ]);

    // An approved order, nothing received yet.
    $this->order = function (string $quantity = '100', string $cost = '20.00'): PurchaseOrder {
        $order = app(CreatePurchaseOrder::class)->handle(
            supplier: $this->supplier, warehouse: $this->warehouse,
            lines: [['product' => $this->product, 'quantity' => $quantity, 'unit_cost' => $cost]],
            createdBy: $this->user,
        );
        approveIfPending($order->approvalRequest, $this->user);

        return $order->fresh(['lines', 'approvalRequest']);
    };

    // Receives the whole order and records the supplier's bill for it.
    $this->bill = function (PurchaseOrder $order, string $number, string $date = '2026-08-15'): SupplierBill {
        $line = $order->lines->first();
        app(RecordGoodsReceipt::class)->handle($order, [['line' => $line, 'quantity' => (string) $line->quantity_ordered]], $this->user);

        return app(RecordSupplierBill::class)->handle(
            supplier: $this->supplier, billNumber: $number, billDate: Carbon::parse($date),
            lines: [['line' => $line->fresh(), 'quantity' => (string) $line->quantity_ordered, 'unitCost' => (string) $line->unit_cost]],
            createdBy: $this->user, purchaseOrder: $order,
        );
    };

    $this->advance = fn (string $amount, string $date = '2026-08-01', ?PurchaseOrder $po = null, ?WithholdingRate $tds = null) => app(RecordSupplierAdvance::class)->pay(
        supplier: $this->supplier, moneyAccount: $this->bank, paidOn: $date, amount: $amount,
        createdBy: $this->user, purchaseOrder: $po, tdsRate: $tds,
    );

    $this->apply = fn (SupplierBill $bill, array $rows, string $on = '2026-08-15') => app(ApplySupplierAdvances::class)->handle(
        $bill, array_map(fn ($row) => ['advance' => $row[0], 'amount' => $row[1]], $rows), $on, $this->user,
    );

    // A party's balance on a system head, signed paisa (debit positive).
    $this->balance = fn (string $slug, ?Supplier $party = null) => (int) JournalLine::query()
        ->where('account_id', Account::bySlug($slug)->id)
        ->when($party, fn ($q) => $q->where('party_type', $party->getMorphClass())->where('party_id', $party->id))
        ->sum('amount_minor');
});

afterEach(fn () => Carbon::setTestNow());

// ── 8. An advance with no purchase order ────────────────────────────────────

it('posts an advance without a purchase order: Dr 1160 with the supplier, Cr the bank, its own number', function () {
    $advance = ($this->advance)('5000');

    expect($advance->number)->toBe('SADV-2026-27-0001')
        ->and($advance->open()->format())->toBe('5,000.00')
        ->and(($this->balance)('supplier_advances', $this->supplier))->toBe(500_000)
        ->and(($this->balance)('cash_at_bank'))->toBe(-500_000)
        // An asset, not a payable: Accounts Payable is untouched.
        ->and(($this->balance)('accounts_payable'))->toBe(0);

    $journal = Journal::query()->where('source_type', $advance->getMorphClass())->where('source_id', $advance->id)->sole();
    expect($journal->lines)->toHaveCount(2);
});

it('refuses an advance dated in the future, into a closed month, or against another supplier\'s order', function () {
    expect(fn () => ($this->advance)('100', '2026-08-21'))->toThrow(ValidationException::class);

    $other = Supplier::create(['business_id' => $this->business->id, 'name' => 'Other', 'code' => 'SUP-009']);
    $order = app(CreatePurchaseOrder::class)->handle(
        supplier: $other, warehouse: $this->warehouse,
        lines: [['product' => $this->product, 'quantity' => '1', 'unit_cost' => '1.00']], createdBy: $this->user,
    );
    expect(fn () => ($this->advance)('100', '2026-08-01', $order))->toThrow(ValidationException::class);

    AccountingPeriod::create(['business_id' => $this->business->id, 'closed_through' => '2026-07-31', 'closed_by' => $this->user->id]);
    expect(fn () => ($this->advance)('100', '2026-07-30'))->toThrow(PeriodClosedException::class)
        ->and(SupplierAdvance::count())->toBe(0);
});

// ── 9. Linked to a purchase order, shown there, applied once ────────────────

it('shows an advance on its purchase order and applies it once', function () {
    $order = ($this->order)();
    $advance = ($this->advance)('1500', '2026-08-01', $order);

    expect(SupplierAdvanceBook::forPurchaseOrder($order))->toMatchArray(['paid' => '1500.00', 'applied' => '0.00', 'open' => '1500.00']);

    $bill = ($this->bill)($order, 'PG-101');
    ($this->apply)($bill, [[$advance, '1500']]);

    expect(SupplierAdvanceBook::forPurchaseOrder($order))->toMatchArray(['paid' => '1500.00', 'applied' => '1500.00', 'open' => '0.00'])
        ->and($bill->fresh()->outstanding()->format())->toBe('500.00')
        ->and(($this->balance)('supplier_advances', $this->supplier))->toBe(0)
        ->and(($this->balance)('accounts_payable', $this->supplier))->toBe(-50_000);

    // A second application of the same advance finds nothing left.
    expect(fn () => ($this->apply)($bill, [[$advance->fresh(), '1']]))->toThrow(ValidationException::class)
        ->and(SupplierAdvanceApplication::count())->toBe(1);

    // The purchase order page carries the card.
    Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'web', 'business_id' => $this->business->id]);
    (new PurchasingPermissionsSeeder)->run();
    setPermissionsTeamId($this->business->id);
    $this->user->assignRole('Manager');

    $this->actingAs($this->user)->get("/purchasing/purchase-orders/{$order->id}")
        ->assertInertia(fn (Assert $page) => $page->where('advances.applied', '1500.00')->where('can_record_advance', true));
});

// ── 10. Several advances on one bill, linked first then oldest ──────────────

it('proposes the order\'s own advances first, then the oldest, and applies several to one bill', function () {
    $order = ($this->order)('100', '30.00'); // 3,000
    $oldest = ($this->advance)('800', '2026-08-01');
    $newer = ($this->advance)('900', '2026-08-05');
    $linked = ($this->advance)('1000', '2026-08-10', $order);

    $bill = ($this->bill)($order, 'PG-102');
    $proposal = SupplierAdvanceBook::proposalFor($bill);

    expect(array_column($proposal, 'key'))->toBe(["advance:{$linked->id}", "advance:{$oldest->id}", "advance:{$newer->id}"])
        ->and(array_map(fn ($p) => $p['amount']->toTaka(), $proposal))->toBe(['1000.00', '800.00', '900.00']);

    $applications = ($this->apply)($bill, [[$linked, '1000'], [$oldest, '800'], [$newer, '900']]);

    expect($applications)->toHaveCount(3)
        ->and($applications->pluck('number')->all())->toBe(['SAAP-2026-27-0001', 'SAAP-2026-27-0002', 'SAAP-2026-27-0003'])
        ->and($bill->fresh()->outstanding()->format())->toBe('300.00')
        ->and(($this->balance)('supplier_advances', $this->supplier))->toBe(0);
});

it('refuses to apply more than the bill owes, or before the bill or the advance existed', function () {
    $order = ($this->order)(); // 2,000
    $advance = ($this->advance)('5000');
    $bill = ($this->bill)($order, 'PG-103');

    expect(fn () => ($this->apply)($bill, [[$advance, '2000.01']]))->toThrow(ValidationException::class)
        ->and(fn () => ($this->apply)($bill, [[$advance, '100']], '2026-08-14'))->toThrow(ValidationException::class);

    $late = ($this->advance)('100', '2026-08-18');
    expect(fn () => ($this->apply)($bill, [[$late, '100']], '2026-08-16'))->toThrow(ValidationException::class)
        ->and(SupplierAdvanceApplication::count())->toBe(0)
        ->and($bill->fresh()->outstanding()->format())->toBe('2,000.00');
});

// ── 11. Partial advance, partial bill, partial payment ─────────────────────

it('leaves the right open balances after a partial application and a partial payment', function () {
    $order = ($this->order)(); // 2,000
    $advance = ($this->advance)('1200');
    $bill = ($this->bill)($order, 'PG-104');

    ($this->apply)($bill, [[$advance, '700']]);
    app(RecordSupplierPayment::class)->handle($this->supplier, $this->bank, '2026-08-16', [['bill' => $bill->fresh(), 'amount' => '1000']], $this->user);

    expect($advance->fresh()->open()->format())->toBe('500.00')
        ->and($bill->fresh()->outstanding()->format())->toBe('300.00')
        ->and(($this->balance)('supplier_advances', $this->supplier))->toBe(50_000)
        ->and(($this->balance)('accounts_payable', $this->supplier))->toBe(-30_000)
        // The payables ageing still ties to Accounts Payable.
        ->and(Ageing::payables(Carbon::today())->tiesOut())->toBeTrue()
        ->and(collect(SubledgerReconciliation::reconcileCurrentBusiness()['problems'])->filter(fn ($p) => str_contains($p, 'Accounts Payable'))->all())->toBe([]);
});

// ── 12. Overpayment: confirmed, then a traceable advance ────────────────────

it('refuses an overpayment unless confirmed, then keeps the excess as an advance on the same journal', function () {
    $order = ($this->order)(); // 2,000
    $bill = ($this->bill)($order, 'PG-105');
    $pay = fn (bool $confirm) => app(RecordSupplierPayment::class)->handle(
        $this->supplier, $this->bank, '2026-08-16', [['bill' => $bill, 'amount' => '2500']], $this->user,
        reference: 'BRAC-778', excessToAdvance: $confirm,
    );

    expect(fn () => $pay(false))->toThrow(ValidationException::class)
        ->and(SupplierPayment::count())->toBe(0);

    $payment = $pay(true);
    $advance = SupplierAdvance::query()->sole();

    expect((string) $payment->amount)->toStartWith('2000')
        ->and($advance->kind)->toBe('overpayment')
        ->and($advance->supplier_payment_id)->toBe($payment->id)
        ->and($advance->money()->format())->toBe('500.00')
        ->and($bill->fresh()->outstanding()->minor)->toBe(0)
        ->and(($this->balance)('accounts_payable', $this->supplier))->toBe(0)
        ->and(($this->balance)('supplier_advances', $this->supplier))->toBe(50_000)
        ->and(($this->balance)('cash_at_bank'))->toBe(-250_000);

    // One remittance, one bank line: the advance rides on the payment's journal.
    $journal = Journal::query()->where('source_type', $payment->getMorphClass())->where('source_id', $payment->id)->sole();
    expect($journal->lines->where('account_id', Account::bySlug('cash_at_bank')->id))->toHaveCount(1)
        ->and(Journal::query()->where('source_type', $advance->getMorphClass())->count())->toBe(0);

    // The payment cannot be reversed while its advance is in use...
    $next = ($this->bill)(($this->order)('10', '20.00'), 'PG-106'); // 200
    $application = ($this->apply)($next, [[$advance, '200']], '2026-08-16')->first();
    expect(fn () => app(ReverseSupplierPayment::class)->handle($payment, $this->user, 'Wrong amount'))->toThrow(ValidationException::class);

    // ...and once that is undone, both go together.
    app(ReverseSupplierAdvanceApplication::class)->handle($application, $this->user, 'Applied by mistake');
    app(ReverseSupplierPayment::class)->handle($payment->fresh(), $this->user, 'Wrong amount');

    expect($advance->fresh()->isReversed())->toBeTrue()
        ->and($advance->fresh()->reversal_journal_id)->toBe($payment->fresh()->reversal_journal_id)
        ->and(($this->balance)('supplier_advances', $this->supplier))->toBe(0)
        ->and(($this->balance)('cash_at_bank'))->toBe(0);
});

// ── 13. Refunds, full and partial, never more than is open ─────────────────

it('refunds an advance in part and in full, never more than is left', function () {
    $advance = ($this->advance)('1000');
    $refund = fn (string $amount) => app(RefundSupplierAdvance::class)->handle($this->supplier, $advance, $this->bank, '2026-08-12', $amount, $this->user);

    $first = $refund('400');
    expect($first->number)->toBe('SARF-2026-27-0001')
        ->and($advance->fresh()->open()->format())->toBe('600.00');

    expect(fn () => $refund('600.01'))->toThrow(ValidationException::class);

    $refund('600');
    expect($advance->fresh()->open()->minor)->toBe(0)
        ->and(($this->balance)('supplier_advances', $this->supplier))->toBe(0)
        ->and(($this->balance)('cash_at_bank'))->toBe(0);

    // A reversed refund puts the money back on the advance.
    app(ReverseSupplierAdvanceRefund::class)->handle($first, $this->user, 'Bank returned it');
    expect($advance->fresh()->open()->format())->toBe('400.00');
});

// ── 14. A payment over several bills keeps its manual allocation ────────────

it('keeps a manual allocation across several bills when one of them was partly settled by an advance', function () {
    $one = ($this->bill)(($this->order)('50', '20.00'), 'PG-107'); // 1,000
    $two = ($this->bill)(($this->order)('50', '20.00'), 'PG-108'); // 1,000
    $advance = ($this->advance)('600');
    ($this->apply)($one, [[$advance, '600']]);

    $payment = app(RecordSupplierPayment::class)->handle($this->supplier, $this->bank, '2026-08-16', [
        ['bill' => $one->fresh(), 'amount' => '100'],
        ['bill' => $two, 'amount' => '900'],
    ], $this->user);

    expect($payment->allocations->pluck('amount', 'supplier_bill_id')->map(fn ($a) => (string) $a)->all())
        ->toBe([$one->id => '100.0000', $two->id => '900.0000'])
        ->and($one->fresh()->outstanding()->format())->toBe('300.00')
        ->and($two->fresh()->outstanding()->format())->toBe('100.00');
});

// ── 15. TDS at the advance, not again on the same base ──────────────────────

it('holds TDS back at the advance and never again on the same base at settlement', function () {
    $tds = WithholdingRate::create(['business_id' => $this->business->id, 'kind' => 'tds', 'supplier_type' => 'goods', 'name' => 'Supply of goods', 'rate' => '5', 'effective_from' => '2026-07-01']);
    $order = ($this->order)('100', '100.00'); // 10,000
    $advance = ($this->advance)('4000', '2026-08-01', $order, $tds);

    expect($advance->netPaid()->format())->toBe('3,800.00')
        ->and(($this->balance)('tds_payable'))->toBe(-20_000)
        ->and(($this->balance)('supplier_advances', $this->supplier))->toBe(400_000);

    $bill = ($this->bill)($order, 'PG-109');
    $application = ($this->apply)($bill, [[$advance, '4000']])->first();
    expect((string) $application->tds_covered)->toStartWith('200');

    // The rest of the bill is paid with the same rule: TDS only on what is left.
    app(RecordSupplierPayment::class)->handle($this->supplier, $this->bank, '2026-08-16', [['bill' => $bill->fresh(), 'amount' => '6000']], $this->user, tdsRate: $tds);

    expect($bill->fresh()->outstanding()->minor)->toBe(0)
        // 5% of 10,000 in all, never 5% of 14,000.
        ->and(($this->balance)('tds_payable'))->toBe(-50_000)
        ->and(($this->balance)('cash_at_bank'))->toBe(-950_000)
        ->and(($this->balance)('accounts_payable', $this->supplier))->toBe(0);
});

// ── 16. VDS stays off ──────────────────────────────────────────────────────

it('refuses VDS on an advance while VAT is off', function () {
    $vds = WithholdingRate::create(['business_id' => $this->business->id, 'kind' => 'vds', 'supplier_type' => null, 'name' => 'Any supply', 'rate' => '2.5', 'effective_from' => '2026-07-01']);

    expect(fn () => app(RecordSupplierAdvance::class)->pay($this->supplier, $this->bank, '2026-08-01', '1000', $this->user, vdsRate: $vds))
        ->toThrow(ValidationException::class)
        ->and(SupplierAdvance::count())->toBe(0);
});

// ── Reversal order ──────────────────────────────────────────────────────────

it('reverses in dependency order: the application first, then the advance', function () {
    $order = ($this->order)();
    $advance = ($this->advance)('1000');
    $bill = ($this->bill)($order, 'PG-110');
    $application = ($this->apply)($bill, [[$advance, '1000']])->first();

    expect(fn () => app(ReverseSupplierAdvance::class)->handle($advance, $this->user, 'Wrong supplier'))->toThrow(ValidationException::class)
        // A bill settled by an advance cannot be reversed underneath it either.
        ->and(fn () => app(ReverseSupplierBill::class)->handle($bill, $this->user, 'Typo'))->toThrow(DomainException::class);

    app(ReverseSupplierAdvanceApplication::class)->handle($application, $this->user, 'Wrong bill');

    expect($bill->fresh()->outstanding()->format())->toBe('2,000.00')
        ->and(PartyAdjustment::query()->where('kind', 'advance_applied')->count())->toBe(0)
        ->and($application->fresh()->isReversed())->toBeTrue();

    app(ReverseSupplierAdvance::class)->handle($advance->fresh(), $this->user, 'Wrong supplier');

    expect($advance->fresh()->isReversed())->toBeTrue()
        ->and(($this->balance)('supplier_advances', $this->supplier))->toBe(0)
        ->and(($this->balance)('cash_at_bank'))->toBe(0)
        ->and(fn () => app(ReverseSupplierAdvance::class)->handle($advance->fresh(), $this->user, 'Again'))->toThrow(ValidationException::class);
});

it('refuses an application into a closed month', function () {
    $order = ($this->order)();
    $advance = ($this->advance)('1000');
    $bill = ($this->bill)($order, 'PG-111');

    AccountingPeriod::create(['business_id' => $this->business->id, 'closed_through' => '2026-08-15', 'closed_by' => $this->user->id]);

    expect(fn () => ($this->apply)($bill, [[$advance, '500']], '2026-08-15'))->toThrow(PeriodClosedException::class);

    ($this->apply)($bill, [[$advance, '500']], '2026-08-16');
    expect($bill->fresh()->outstanding()->format())->toBe('1,500.00');
});

// ── Advances a Payment voucher made before this ─────────────────────────────

it('applies the 1160 balance a Payment voucher left, and keeps the voucher until that is undone', function () {
    $this->supplier->refresh();
    $voucher = app(PostVoucher::class)->handle(app(SaveVoucherDraft::class)->handle(VoucherType::Payment, [
        'voucher_date' => '2026-08-02', 'money_account_id' => $this->bank->id,
        'lines' => [['account_id' => $this->supplier->account_id, 'amount' => '1500']],
    ], $this->user), $this->user);

    expect(SupplierAdvanceBook::onAccount($this->supplier)->format())->toBe('1,500.00')
        ->and(SupplierAdvanceBook::available($this->supplier)->first()['advance'])->toBeNull();

    $bill = ($this->bill)(($this->order)(), 'PG-112'); // 2,000
    expect(SupplierAdvanceBook::proposalFor($bill)[0]['key'])->toBe('on_account');

    $application = ($this->apply)($bill, [[null, '1200']])->first();

    expect($application->isOnAccount())->toBeTrue()
        ->and($application->source_note)->toContain($voucher->journal->journal_number)
        ->and(SupplierAdvanceBook::onAccount($this->supplier)->format())->toBe('300.00')
        ->and($bill->fresh()->outstanding()->format())->toBe('800.00')
        ->and(($this->balance)('supplier_advances', $this->supplier))->toBe(30_000);

    expect(fn () => ($this->apply)($bill->fresh(), [[null, '300.01']]))->toThrow(ValidationException::class);

    // The voucher cannot be reversed under an application that used it.
    expect(fn () => app(ReverseVoucher::class)->handle($voucher->fresh(), $this->user, 'Wrong supplier'))->toThrow(ValidationException::class);

    app(ReverseSupplierAdvanceApplication::class)->handle($application, $this->user, 'Wrong bill');
    app(ReverseVoucher::class)->handle($voucher->fresh(), $this->user, 'Wrong supplier');

    expect(SupplierAdvanceBook::onAccount($this->supplier)->minor)->toBe(0)
        ->and(($this->balance)('supplier_advances', $this->supplier))->toBe(0);
});

// ── Ageing ─────────────────────────────────────────────────────────────────

it('ages advances apart from payables and ties the total to 1160', function () {
    ($this->advance)('1000', '2026-08-20');                // current
    $old = ($this->advance)('2000', '2026-07-10');         // 41 days: 31-60
    $order = ($this->order)();
    $bill = ($this->bill)($order, 'PG-113');
    ($this->apply)($bill, [[$old, '500']]);

    $ageing = SupplierAdvanceAgeing::asAt(Carbon::parse('2026-08-20'));
    $row = $ageing['rows'][0];

    expect($row['buckets']['current'])->toBe('1,000.00')
        ->and($row['buckets']['days_31_60'])->toBe('1,500.00')
        ->and($row['total'])->toBe('2,500.00')
        ->and($ageing['control']['ties_out'])->toBeTrue()
        ->and($ageing['control']['balance'])->toBe('2,500.00');

    // As at a date before the application, the old advance is still whole.
    $before = SupplierAdvanceAgeing::asAt(Carbon::parse('2026-08-14'));
    expect($before['rows'][0]['total'])->toBe('2,000.00')
        ->and($before['control']['ties_out'])->toBeTrue();

    // And the AP ageing knows nothing of the asset.
    expect(Ageing::payables(Carbon::parse('2026-08-20'))->total()->absolute()->format())->toBe('1,500.00');
});

// ── Opening advances ───────────────────────────────────────────────────────

it('enters an opening advance: Dr 1160 with the supplier, Cr 3900, removable until used', function () {
    $advance = app(RecordSupplierAdvance::class)->opening($this->supplier, 'OLD-ADV-7', '2026-07-01', '3000', $this->user);

    expect($advance->kind)->toBe('opening')
        ->and(($this->balance)('supplier_advances', $this->supplier))->toBe(300_000)
        ->and(($this->balance)('opening_balance_equity'))->toBe(-300_000)
        ->and(fn () => app(ReverseSupplierAdvance::class)->handle($advance, $this->user, 'x'))->toThrow(ValidationException::class);

    $bill = ($this->bill)(($this->order)(), 'PG-114');
    $application = ($this->apply)($bill, [[$advance, '1000']])->first();

    expect(fn () => app(RecordSupplierAdvance::class)->removeOpening($advance, $this->user))->toThrow(ValidationException::class);

    app(ReverseSupplierAdvanceApplication::class)->handle($application, $this->user, 'Wrong bill');
    app(RecordSupplierAdvance::class)->removeOpening($advance->fresh(), $this->user);

    expect($advance->fresh()->isReversed())->toBeTrue()
        ->and(($this->balance)('supplier_advances', $this->supplier))->toBe(0)
        ->and(($this->balance)('opening_balance_equity'))->toBe(0);
});

// ── Screens and permissions ─────────────────────────────────────────────────

it('records an advance and applies it through the screens, for whoever may pay a supplier', function () {
    Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'web', 'business_id' => $this->business->id]);
    Role::firstOrCreate(['name' => 'Staff', 'guard_name' => 'web', 'business_id' => $this->business->id]);
    (new PurchasingPermissionsSeeder)->run();
    setPermissionsTeamId($this->business->id);
    $this->user->assignRole('Manager');

    $staff = User::factory()->create();
    $staff->businesses()->attach($this->business->id);
    $staff->assignRole('Staff');

    $order = ($this->order)();
    $form = [
        'supplier_id' => $this->supplier->id, 'money_account_id' => $this->bank->id, 'purchase_order_id' => $order->id,
        'paid_on' => '2026-08-03', 'amount' => '750', 'reference' => 'BRAC-1',
    ];

    $this->actingAs($staff)->post('/purchasing/advances', $form)->assertForbidden();

    $this->actingAs($this->user)->get('/purchasing/advances/new?purchase_order_id='.$order->id)
        ->assertInertia(fn (Assert $page) => $page->component('purchasing/advances/create')->where('selectedSupplierId', $this->supplier->id));

    $this->actingAs($this->user)->post('/purchasing/advances', $form)->assertSessionHasNoErrors();
    $advance = SupplierAdvance::sole();

    $this->actingAs($this->user)->get("/purchasing/advances/{$advance->id}")
        ->assertInertia(fn (Assert $page) => $page->where('advance.open', '750.00')->where('can_reverse', true));

    $bill = ($this->bill)($order, 'PG-115');

    $this->actingAs($this->user)->get("/purchasing/bills/{$bill->id}")
        ->assertInertia(fn (Assert $page) => $page->where('advances.can_apply', true)->where('advances.proposal.0.key', "advance:{$advance->id}")
            ->where('advances.proposal.0.amount', '750.00'));

    $this->actingAs($this->user)->post("/purchasing/bills/{$bill->id}/advances", [
        'applied_on' => '2026-08-15', 'advances' => [['key' => "advance:{$advance->id}", 'amount' => '750']],
    ])->assertSessionHasNoErrors();

    expect($bill->fresh()->outstanding()->format())->toBe('1,250.00');

    $this->actingAs($this->user)->get("/purchasing/suppliers/{$this->supplier->id}/advances")
        ->assertInertia(fn (Assert $page) => $page->component('purchasing/advances/supplier')->has('applications', 1));

    // The ageing sits behind accounting.view, which a Manager does not hold.
    $this->user->givePermissionTo(Permission::firstOrCreate(['name' => 'accounting.view', 'guard_name' => 'web']));
    $this->actingAs($this->user)->get('/accounting/payables-ageing')->assertOk()->assertInertia(fn (Assert $page) => $page->has('advance_ageing.rows'));
});

it('applies advances while the bill is saved, and leaves no bill when an advance is refused', function () {
    Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'web', 'business_id' => $this->business->id]);
    (new PurchasingPermissionsSeeder)->run();
    setPermissionsTeamId($this->business->id);
    $this->user->assignRole('Manager');

    $order = ($this->order)();
    $advance = ($this->advance)('900', '2026-08-01', $order);
    $line = $order->lines->first();
    app(RecordGoodsReceipt::class)->handle($order, [['line' => $line, 'quantity' => '100']], $this->user);

    $this->actingAs($this->user)->get("/purchasing/bills/new/{$order->id}")
        ->assertInertia(fn (Assert $page) => $page->where('advances.0.key', "advance:{$advance->id}"));

    $form = fn (string $amount) => [
        'purchase_order_id' => $order->id, 'bill_number' => 'PG-116', 'bill_date' => '2026-08-15',
        'lines' => [['purchase_order_line_id' => $line->id, 'quantity' => '100', 'unit_cost' => '20.00']],
        'advances' => [['key' => "advance:{$advance->id}", 'amount' => $amount]],
    ];

    $this->actingAs($this->user)->post('/purchasing/bills', $form('901'))->assertSessionHasErrors();
    expect(SupplierBill::count())->toBe(0);

    $this->actingAs($this->user)->post('/purchasing/bills', $form('900'))->assertSessionHasNoErrors();
    expect(SupplierBill::sole()->outstanding()->format())->toBe('1,100.00')
        ->and($advance->fresh()->open()->minor)->toBe(0);
});

it('keeps an advance application out of the manual adjustments screen', function () {
    $order = ($this->order)();
    $advance = ($this->advance)('100');
    $bill = ($this->bill)($order, 'PG-117');
    $application = ($this->apply)($bill, [[$advance, '100']])->first();

    expect(fn () => app(RecordPartyAdjustment::class)->remove($application->adjustment, $this->user))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(RecordPartyAdjustment::class)->handle('advance_applied', '2026-08-15', null, $this->supplier, [], [$bill->id => '1'], 'x', $this->user))
        ->toThrow(ValidationException::class)
        ->and(DB::table('party_adjustments')->count())->toBe(1);
});
