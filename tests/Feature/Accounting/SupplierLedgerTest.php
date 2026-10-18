<?php

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Actions\PostVoucher;
use App\Modules\Accounting\Actions\RecordOpeningBalanceDocument;
use App\Modules\Accounting\Actions\SaveVoucherDraft;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Enums\JournalType;
use App\Modules\Accounting\Enums\VoucherType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\SubledgerReconciliation;
use App\Modules\Accounting\Support\SupplierLedger;
use App\Modules\Accounting\Support\UntaggedGrni;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Actions\RecordGoodsReceipt;
use App\Modules\Purchasing\Actions\RecordPurchaseReturn;
use App\Modules\Purchasing\Actions\RecordSupplierBill;
use App\Modules\Purchasing\Actions\RecordSupplierCreditNote;
use App\Modules\Purchasing\Actions\RecordSupplierPayment;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ The supplier ledger VIEW (A1, ChatGPT ruling P1, tests 1-7, 18 Oct 2026):
 * one supplier across Accounts Payable (2110), Goods Received Not Invoiced
 * (2120, now tagged to the supplier) and Advances to Suppliers (1160), each
 * tied out to its own control account; the "2110-<id>" head opens it in the
 * General Ledger and stays non-postable.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-20 10:00:00');

    $this->business = Business::create(['name' => 'Ledger View Co', 'slug' => 'ledger-view-co', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Permission::findOrCreate('accounting.view', 'web');
    $reader = Role::firstOrCreate(['name' => 'Reader', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    $reader->givePermissionTo('accounting.view');

    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);

    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);
    $this->user->assignRole($reader);

    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);
    $this->product = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'JAR-500',
        'name' => 'Glass jar 500ml',
        'type' => ProductType::RawMaterial,
    ]);
    $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'Dhaka Glassware', 'code' => 'SUP-001']);
    $this->supplier->refresh();
    $this->other = Supplier::create(['business_id' => $this->business->id, 'name' => 'Padma Oil', 'code' => 'SUP-002']);

    $this->bank = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'BRAC Bank', 'type' => 'bank', 'is_active' => true,
        'account_id' => Account::bySlug('cash_at_bank')->id,
    ]);

    $this->orderFrom = function (Supplier $supplier, string $quantity = '100') {
        $order = app(CreatePurchaseOrder::class)->handle(
            supplier: $supplier,
            warehouse: $this->warehouse,
            lines: [['product' => $this->product, 'quantity' => $quantity, 'unit_cost' => '25.00']],
            createdBy: $this->user,
        );
        approveIfPending($order->approvalRequest, $this->user);

        return $order->fresh(['lines', 'approvalRequest']);
    };

    $this->order = ($this->orderFrom)($this->supplier);
    $this->line = $this->order->lines->first();

    $this->receive = fn (string $quantity, $order = null) => app(RecordGoodsReceipt::class)->handle(
        $order ?? $this->order,
        [['line' => ($order ?? $this->order)->lines->first(), 'quantity' => $quantity]],
        $this->user,
    );
    $this->bill = fn (string $quantity, string $number = 'INV-1') => app(RecordSupplierBill::class)->handle(
        supplier: $this->supplier,
        billNumber: $number,
        billDate: Carbon::parse('2026-08-12'),
        lines: [['line' => $this->line, 'quantity' => $quantity, 'unitCost' => '25.00']],
        createdBy: $this->user,
        purchaseOrder: $this->order,
    );

    $this->ledger = fn (string $from = '2026-07-01', string $to = '2026-08-31') => SupplierLedger::for(
        $this->supplier->fresh(), Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay(),
    );
    $this->account = fn (SupplierLedger $ledger, string $role) => $ledger->accounts->firstWhere('role', $role);
    $this->partyBalance = fn (string $slug, Supplier $supplier) => (int) JournalLine::query()
        ->where('account_id', Account::bySlug($slug)->id)
        ->where('party_type', $supplier->getMorphClass())->where('party_id', $supplier->id)
        ->sum('amount_minor');
});

afterEach(fn () => Carbon::setTestNow());

// 1. Bill, payment and credit note reconcile to 2110, every line naming the supplier.
it('ties the supplier bill, payment and credit note out to Accounts Payable for that supplier', function () {
    ($this->receive)('40');
    $bill = ($this->bill)('40');
    app(RecordSupplierPayment::class)->handle(
        supplier: $this->supplier,
        moneyAccount: $this->bank,
        paidOn: '2026-08-14',
        allocations: [['bill' => $bill, 'amount' => '500']],
        createdBy: $this->user,
        reference: 'CHQ-1',
    );
    $return = app(RecordPurchaseReturn::class)->handle($this->order, [['line' => $this->line, 'quantity' => '10']], $this->user);
    app(RecordSupplierCreditNote::class)->handle(
        supplier: $this->supplier,
        purchaseReturn: $return,
        creditNoteNumber: 'CN-1',
        creditDate: '2026-08-16',
        allocations: [['bill' => $bill, 'amount' => '250']],
        createdBy: $this->user,
    );

    $ledger = ($this->ledger)();
    $payable = ($this->account)($ledger, SupplierLedger::ROLE_PAYABLE);

    // 1,000 billed, 500 paid, 250 credited: 250 still owed.
    expect($ledger->billedPayable()->format())->toBe('250.00')
        ->and($payable['closing'])->toBe(($this->partyBalance)('accounts_payable', $this->supplier))
        ->and($payable['closing'])->toBe($payable['ledger_closing'])
        ->and(JournalLine::where('account_id', Account::bySlug('accounts_payable')->id)->whereNull('party_id')->count())->toBe(0)
        ->and(collect($ledger->toArray()['lines'])->where('role', 'payable')->pluck('document.label')->all())
        ->toBe(['Supplier bill', 'Supplier payment', 'Supplier credit note']);

    // The receipt, bill, return and credit note all name the supplier on GRNI
    // too, and clear it exactly: nothing left received and not billed.
    expect(JournalLine::where('account_id', Account::bySlug('goods_received_not_invoiced')->id)->whereNull('party_id')->count())->toBe(0)
        ->and($ledger->receivedNotBilled()->isZero())->toBeTrue();

    $reconciliation = SubledgerReconciliation::reconcileCurrentBusiness();
    expect(collect($reconciliation['problems'])->filter(fn ($p) => str_contains($p, 'Accounts Payable') || str_contains($p, 'Goods Received'))->all())->toBe([]);
});

// 2. An advance through the existing payment voucher is a 1160 line, not an AP invoice.
it('shows a payment-voucher advance in the advances section, never as a bill', function () {
    app(PostVoucher::class)->handle(app(SaveVoucherDraft::class)->handle(VoucherType::Payment, [
        'voucher_date' => '2026-08-05', 'money_account_id' => $this->bank->id,
        'lines' => [['account_id' => $this->supplier->account_id, 'amount' => '1000']],
    ], $this->user), $this->user);

    $ledger = ($this->ledger)();
    $lines = collect($ledger->toArray()['lines']);

    expect($ledger->advances()->format())->toBe('1,000.00')
        ->and($ledger->billedPayable()->isZero())->toBeTrue()
        ->and($lines->where('role', 'advances'))->toHaveCount(1)
        ->and($lines->where('role', 'payable'))->toHaveCount(0)
        ->and($lines->firstWhere('role', 'advances')['document']['label'])->toBe('Payment voucher')
        ->and(($this->account)($ledger, SupplierLedger::ROLE_ADVANCES)['ledger_closing'])->toBe(100_000);
});

// 3 and 4. A receipt is supplier-tagged GRNI; the bill clears it exactly once.
it('tags the receipt to the supplier so the view shows received not billed, and the bill clears it exactly once', function () {
    ($this->receive)('40');

    $grni = JournalLine::where('account_id', Account::bySlug('goods_received_not_invoiced')->id)->sole();
    expect($grni->party->is($this->supplier))->toBeTrue();

    $ledger = ($this->ledger)();
    $line = collect($ledger->toArray()['lines'])->firstWhere('role', 'grni');

    expect($ledger->receivedNotBilled()->format())->toBe('1,000.00')
        ->and($ledger->billedPayable()->isZero())->toBeTrue()
        ->and($line['document']['label'])->toBe('Goods received')
        ->and($line['po_number'])->toBe($this->order->po_number);

    // Bill 30 of the 40: 250 still received and not billed, nothing doubled.
    ($this->bill)('30');
    expect(($this->ledger)()->receivedNotBilled()->format())->toBe('250.00')
        ->and(($this->ledger)()->billedPayable()->format())->toBe('750.00');

    ($this->bill)('10', 'INV-2');
    $ledger = ($this->ledger)();
    expect($ledger->receivedNotBilled()->isZero())->toBeTrue()
        ->and($ledger->billedPayable()->format())->toBe('1,000.00')
        ->and(($this->partyBalance)('goods_received_not_invoiced', $this->supplier))->toBe(0);
});

// 5. The combined view reconciles per control account, never in total.
it('reconciles each control account separately to the supplier balance on it', function () {
    ($this->receive)('40');
    ($this->bill)('20');
    app(PostVoucher::class)->handle(app(SaveVoucherDraft::class)->handle(VoucherType::Payment, [
        'voucher_date' => '2026-08-15', 'money_account_id' => $this->bank->id,
        // 500 settles the bill, the rest is an advance.
        'lines' => [['account_id' => $this->supplier->account_id, 'amount' => '800']],
    ], $this->user), $this->user);

    $ledger = ($this->ledger)();

    foreach ([['payable', 'accounts_payable'], ['grni', 'goods_received_not_invoiced'], ['advances', 'supplier_advances']] as [$role, $slug]) {
        $row = ($this->account)($ledger, $role);
        expect($row['closing'])->toBe(($this->partyBalance)($slug, $this->supplier), $role)
            ->and($row['closing'])->toBe($row['ledger_closing'], $role);
    }

    expect($ledger->isConsistent())->toBeTrue()
        ->and($ledger->toArray()['consistent'])->toBeTrue();
});

// 6. Opening payable and opening advance show, against Opening Balance Equity.
it('carries an opening payable and an opening advance into the view as the opening balance', function () {
    app(RecordOpeningBalanceDocument::class)->bill($this->supplier, 'OB-1', '2026-06-30', null, '5000', $this->user);
    app(PostJournal::class)->handle(new JournalDraft(
        date: Carbon::parse('2026-06-30'),
        lines: [
            JournalLineDraft::debit(Account::bySlug('supplier_advances')->id, Money::ofTaka('2000'), 'Opening advance', $this->supplier),
            JournalLineDraft::credit(Account::bySlug('opening_balance_equity')->id, Money::ofTaka('2000')),
        ],
        memo: 'Opening advance',
        type: JournalType::OpeningBalance,
    ), $this->user);

    $ledger = ($this->ledger)();
    $array = $ledger->toArray();

    expect(($this->account)($ledger, 'payable')['opening'])->toBe(-500_000)
        ->and(($this->account)($ledger, 'advances')['opening'])->toBe(200_000)
        ->and($array['opening_exposure'])->toBe('3,000.00')
        ->and($ledger->isConsistent())->toBeTrue();

    // Opened on the day itself, the lines are listed as opening balances.
    $onTheDay = ($this->ledger)('2026-06-30', '2026-08-31')->toArray();
    expect(collect($onTheDay['lines'])->pluck('document.label')->unique()->values()->all())->toBe(['Opening balance']);
});

// 7. Net exposure never hides the gross components.
it('shows billed, received-not-billed and advances gross beside the net exposure', function () {
    ($this->receive)('40');      // 1,000 received
    ($this->bill)('20');         // 500 billed of it
    app(PostVoucher::class)->handle(app(SaveVoucherDraft::class)->handle(VoucherType::Payment, [
        'voucher_date' => '2026-08-15', 'money_account_id' => $this->bank->id,
        'lines' => [['account_id' => $this->supplier->account_id, 'amount' => '2500']],
    ], $this->user), $this->user);   // 500 pays the bill, 2,000 is an advance

    $cards = ($this->ledger)()->toArray()['cards'];

    expect($cards['billed_payable'])->toBe(['amount' => '0.00', 'negative' => false])
        ->and($cards['received_not_billed'])->toBe(['amount' => '500.00', 'negative' => false])
        ->and($cards['advances'])->toBe(['amount' => '2,000.00', 'negative' => false])
        // 0 + 500 - 2,000: the net says the supplier holds 1,500 of ours, and
        // the three gross figures beside it say why.
        ->and($cards['net_exposure'])->toBe(['amount' => '1,500.00', 'negative' => true])
        // 60 of 100 still to come, a commitment and not a liability.
        ->and($cards['open_commitments'])->toBe('1,500.00');
});

it('keeps an order that started untagged on the untagged path and lists it, through its order, as an exception', function () {
    // An order received BEFORE GRNI named its supplier: its receipt journal
    // has an untagged GRNI line (written here directly, as history is).
    $old = ($this->orderFrom)($this->supplier);
    $receipt = GoodsReceipt::create(['business_id' => $this->business->id, 'purchase_order_id' => $old->id, 'received_by' => $this->user->id, 'received_at' => '2026-07-10']);
    app(PostJournal::class)->handle(new JournalDraft(
        date: Carbon::parse('2026-07-10'),
        lines: [
            JournalLineDraft::debit(Account::bySlug('stock_on_hand')->id, Money::ofTaka('300')),
            JournalLineDraft::credit(Account::bySlug('goods_received_not_invoiced')->id, Money::ofTaka('300'), 'Received not invoiced'),
        ],
        memo: "Goods received #{$receipt->id}",
        source: $receipt,
    ), $this->user);

    // A new delivery on that same order stays untagged, so the order clears
    // on one path; a new order is tagged.
    ($this->receive)('4', $old);
    ($this->receive)('10');

    $grni = Account::bySlug('goods_received_not_invoiced')->id;
    expect(JournalLine::where('account_id', $grni)->whereNull('party_id')->count())->toBe(2)
        ->and(JournalLine::where('account_id', $grni)->whereNotNull('party_id')->count())->toBe(1);

    $rows = UntaggedGrni::rows();
    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('supplier_id')->unique()->all())->toBe([$this->supplier->id])
        ->and($rows->first()['po_number'])->toBe($old->po_number)
        ->and($rows->sum('received_minor'))->toBe(40_000);

    // The view keeps the tagged figure and says what sits outside it.
    $view = ($this->ledger)()->toArray();
    expect($view['cards']['received_not_billed']['amount'])->toBe('250.00')
        ->and($view['untagged_grni'])->toBe(['amount' => '400.00', 'lines' => 2]);

    // The reconciliation names the open untagged history as a warning.
    expect(collect(SubledgerReconciliation::reconcileCurrentBusiness()['warnings'])->filter(fn ($w) => str_contains($w, 'naming no supplier'))->values()->all())
        ->toHaveCount(1);

    $this->actingAs($this->user)->get('/accounting/grni-untagged')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('accounting/grni-untagged/index')
            ->has('rows', 2)
            ->where('suppliers.0.supplier', 'Dhaka Glassware')
            ->where('total', '400.00'));
});

// ⚠⚠ Deliberate change of behaviour (18 Oct 2026): the General Ledger used to
// roll a supplier's "2110-<id>" head up like any heading, and as nothing is
// ever posted to it that showed an empty table. It now opens the supplier's
// ledger view; the head itself stays non-postable.
it('opens the supplier ledger view for a supplier head in the General Ledger, and on its own page', function () {
    ($this->receive)('40');
    ($this->bill)('40');

    $head = Account::find($this->supplier->account_id);
    expect($head->is_postable)->toBeFalse();

    $this->actingAs($this->user)
        ->get('/accounting/general-ledger?account='.$head->id.'&from=2026-07-01&to=2026-08-31')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('accounting/general-ledger/index')
            ->missing('rollup')
            ->where('supplier_ledger.supplier.name', 'Dhaka Glassware')
            ->where('supplier_ledger.cards.billed_payable.amount', '1,000.00')
            ->has('supplier_ledger.lines', 3)
            ->where('supplier_ledger.consistent', true));

    // Another heading still rolls up as before.
    $this->actingAs($this->user)
        ->get('/accounting/general-ledger?account='.Account::where('code', '2100')->sole()->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('rollup')->missing('supplier_ledger'));

    $this->actingAs($this->user)->get('/accounting/supplier-ledger')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('accounting/supplier-ledger/index')->where('ledger', null)->has('suppliers', 2));

    $this->actingAs($this->user)->get('/accounting/supplier-ledger?supplier='.$this->supplier->id.'&from=2026-07-01&to=2026-08-31')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('ledger.supplier.id', $this->supplier->id)->has('ledger.accounts', 3));

    // The statement carries the GRNI and advances sections and their tie-out.
    $this->actingAs($this->user)->get('/accounting/supplier-statement?supplier='.$this->supplier->id.'&from=2026-07-01&to=2026-08-31')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('ledger.cards.billed_payable.amount', '1,000.00')->where('summary.consistent', true));
});

it('keeps the supplier ledger away from a user who cannot read the books', function () {
    $outsider = User::factory()->create();
    $outsider->businesses()->attach($this->business->id);

    $this->actingAs($outsider)->get('/accounting/supplier-ledger')->assertForbidden();
    $this->actingAs($outsider)->get('/accounting/grni-untagged')->assertForbidden();
});
