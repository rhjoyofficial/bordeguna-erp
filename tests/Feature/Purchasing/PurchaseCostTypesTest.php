<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Support\SubledgerReconciliation;
use App\Modules\Accounting\Support\SupplierStatement;
use App\Modules\Foundation\database\seeders\ApprovalWorkflowSeeder;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Actions\PlanLandedCostAllocation;
use App\Modules\Purchasing\Actions\PostLandedCost;
use App\Modules\Purchasing\Actions\RecordExpenseVoucher;
use App\Modules\Purchasing\Actions\RecordGoodsReceipt;
use App\Modules\Purchasing\Actions\RecordSupplierPayment;
use App\Modules\Purchasing\Actions\ReverseSupplierBill;
use App\Modules\Purchasing\Actions\SaveLandedCost;
use App\Modules\Purchasing\Actions\SubmitLandedCost;
use App\Modules\Purchasing\Enums\LandedCostTreatment;
use App\Modules\Purchasing\Enums\LandedCostType;
use App\Modules\Purchasing\Models\LandedCost;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Support\LandedCostDoubleCount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Purchase cost types, allocation bases, double counting and the payable bill
 * (18 Oct 2026, Joy's decision D-A, IAS 2 aligned; finding P3).
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-18 10:00:00');

    $this->business = Business::create(['name' => 'Cost Types Co', 'slug' => 'cost-types-co', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $grants = ['purchasing.landed_cost.view', 'purchasing.landed_cost.manage', 'purchasing.landed_cost.post', 'purchasing.po.approve'];

    foreach ($grants as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $role = Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    $role->givePermissionTo($grants);

    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->user->assignRole($role);

    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(InventoryAccountMappingSeeder::class);
    (new ApprovalWorkflowSeeder)->run();
    TenantScope::enter($this->business->id);

    $unit = fn (string $name, string $abbreviation) => UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => $name, 'abbreviation' => $abbreviation]);
    $this->kg = $unit('Kilogram', 'kg');
    $this->g = $unit('Gram', 'g');
    $this->pcs = $unit('Pieces', 'pcs');

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Savar', 'code' => 'SAV']);
    $this->product = fn (string $name, UnitOfMeasure $unit, ?int $grams = null) => Product::create(array_filter([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => strtoupper(substr($name, 0, 4)).'-'.$unit->abbreviation,
        'name' => $name,
        'type' => ProductType::RawMaterial,
        'weight_grams' => $grams,
    ], fn ($value) => $value !== null));

    $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'Chattogram Salt', 'code' => 'SUP-001']);
    $this->carrier = Supplier::create(['business_id' => $this->business->id, 'name' => 'Sundarban Courier', 'code' => 'SUP-002']);
    $this->bank = MoneyAccount::create([
        'business_id' => $this->business->id,
        'name' => 'BRAC Bank',
        'type' => 'bank',
        'account_id' => Account::bySlug('cash_at_bank')->id,
    ]);

    $this->receiptOf = function (array $products, string $receivedOn = '2026-10-10') {
        $order = app(CreatePurchaseOrder::class)->handle(
            supplier: $this->supplier,
            warehouse: $this->warehouse,
            lines: array_map(fn (array $p) => ['product' => $p[0], 'quantity' => $p[1], 'unit_cost' => $p[2]], $products),
            createdBy: $this->user,
        );
        approveIfPending($order->approvalRequest, $this->user);
        $order = $order->fresh(['lines', 'approvalRequest']);

        return app(RecordGoodsReceipt::class)->handle(
            $order,
            $order->lines->map(fn ($line) => ['line' => $line, 'quantity' => (string) $line->quantity_ordered])->all(),
            $this->user,
            receivedOn: Carbon::parse($receivedOn),
            backdateReason: 'test',
        )->fresh('lines');
    };

    // ⚠ `$extra + [...]`: PHP's array union keeps the LEFT side, so the overrides come first.
    $this->costLine = fn (string $type, string $amount, array $extra = []) => $extra + [
        'cost_type' => $type, 'treatment' => null, 'override_reason' => null, 'allocation_method' => 'value',
        'goods_receipt_line_id' => null, 'amount' => $amount, 'supplier_id' => $this->carrier->id,
        'money_account_id' => null, 'expense_account_id' => null, 'description' => null,
    ];

    $this->save = fn ($receipt, array $lines, string $on = '2026-10-15') => app(SaveLandedCost::class)->handle($receipt, $on, $lines, $this->user);
    $this->plan = fn (LandedCost $lc) => app(PlanLandedCostAllocation::class)->handle($lc->fresh(['lines']));
    $this->postAll = function (LandedCost $lc) {
        $lc = app(SubmitLandedCost::class)->handle($lc, $this->user);

        return app(PostLandedCost::class)->handle($lc->fresh(['approvalRequest', 'lines']), $this->user);
    };
    $this->sharesOf = fn ($plan) => array_map(fn (array $a) => $a['share_paisa'], array_values($plan->lines)[0]['allocations']);
    $this->balanceOnCode = fn (string $code) => (int) JournalLine::where('account_id', Account::where('code', $code)->firstOrFail()->id)->sum('amount_minor');
    $this->balanceOn = fn (string $slug) => (int) JournalLine::where('account_id', Account::bySlug($slug)->id)->sum('amount_minor');
});

afterEach(fn () => Carbon::setTestNow());

it('seeds the IAS 2 default per type: carriage, unloading and packaging into stock; travel, admin labour, bank and other expensed', function () {
    $expected = [
        'packaging' => 'capitalize',
        'freight' => 'capitalize',
        'labour' => 'capitalize',
        'general_labour' => 'expense',
        'travel' => 'expense',
        'bank_charge' => 'expense',
        'other' => 'expense',
    ];

    expect(collect(LandedCostType::options())->pluck('default_treatment', 'value')->all())->toBe($expected)
        ->and(LandedCostType::Freight->label())->toBe('Carriage inward / freight')
        ->and(LandedCostType::Labour->label())->toBe('Unloading / handling labour')
        ->and(LandedCostType::GeneralLabour->label())->toBe('General / admin labour')
        ->and(LandedCostType::Travel->label())->toBe('Travel / market visit');

    $salt = ($this->product)('Salt', $this->kg);
    $receipt = ($this->receiptOf)([[$salt, '25', '20.00']]);

    // Every type saved with no treatment typed takes its default and records it.
    $lc = ($this->save)($receipt, array_map(fn (string $type) => ($this->costLine)($type, '10', $type === 'other' ? ['expense_account_id' => Account::where('code', '6058')->value('id')] : []), array_keys($expected)));

    foreach ($lc->lines as $line) {
        expect($line->treatment->value)->toBe($expected[$line->cost_type->value])
            ->and($line->default_treatment->value)->toBe($expected[$line->cost_type->value])
            ->and($line->overridesDefault())->toBeFalse()
            ->and($line->override_reason)->toBeNull();
    }

    // Posted: carriage, unloading and packaging raise the batch; the rest reach their heads.
    $lc = ($this->postAll)($lc);
    $batch = StockBatch::find($receipt->lines[0]->stockMovement->batch_id);

    // 30 capitalised over 25 kg: 20.00 + 1.20.
    expect((string) $batch->unit_cost)->toBe('21.2000')
        ->and(($this->balanceOnCode)('6096'))->toBe(1000)   // general labour
        ->and(($this->balanceOnCode)('6173'))->toBe(1000)   // travel / market visit
        ->and(($this->balanceOnCode)('6471'))->toBe(1000)   // bank charge
        ->and(($this->balanceOnCode)('6058'))->toBe(1000)   // other, on the head it named
        ->and(($this->balanceOnCode)('5300'))->toBe(0);     // carriage inward is stock cost now
});

it('spreads by quantity in stock units, to the exact paisa, and converts kg with g', function () {
    $salt = ($this->product)('Salt', $this->kg);
    $pepper = ($this->product)('Pepper', $this->kg);
    $cumin = ($this->product)('Cumin', $this->kg);

    // Equal value (300 each) but 10 kg against 30 kg: by quantity 250 / 750.
    $receipt = ($this->receiptOf)([[$salt, '10', '30.00'], [$pepper, '30', '10.00']]);
    $plan = ($this->plan)(($this->save)($receipt, [($this->costLine)('labour', '1000', ['allocation_method' => 'quantity'])]));
    $allocations = array_values($plan->lines)[0]['allocations'];

    expect($plan->isPostable())->toBeTrue()
        ->and(($this->sharesOf)($plan))->toBe([25000, 75000])
        ->and(array_values($plan->lines)[0]['basis'])->toBe('quantity')
        ->and($allocations[0]['basis_quantity'])->toBe('10.0000')
        ->and($allocations[0]['basis_unit'])->toBe('kg');

    // 100.00 over three single kilos does not divide: the leftover paisa lands on the first largest.
    $three = ($this->receiptOf)([[$salt, '1', '30.00'], [$pepper, '1', '10.00'], [$cumin, '1', '5.00']]);
    $plan = ($this->plan)(($this->save)($three, [($this->costLine)('labour', '100', ['allocation_method' => 'quantity'])]));
    expect(($this->sharesOf)($plan))->toBe([3334, 3333, 3333])
        ->and(array_sum(($this->sharesOf)($plan)))->toBe(10000);

    // 2 kg of salt and 3,000 g of sugar add up in grams: 40 / 60.
    $sugar = ($this->product)('Sugar', $this->g);
    $mixed = ($this->receiptOf)([[$salt, '2', '150.00'], [$sugar, '3000', '0.10']]);
    $lc = ($this->postAll)(($this->save)($mixed, [($this->costLine)('freight', '100', ['allocation_method' => 'quantity'])]));
    $posted = $lc->lines->first()->allocations->sortBy('id')->values();

    expect($posted->map(fn ($a) => (int) $a->share_paisa)->all())->toBe([4000, 6000])
        ->and((string) $posted[0]->basis_quantity)->toBe('2000.0000')
        ->and($posted[0]->basis_unit)->toBe('g')
        ->and(SubledgerReconciliation::reconcileCurrentBusiness()['problems'])->toBeEmpty();
});

it('refuses quantity when the receipt mixes units that do not add up, naming the lines', function () {
    $sugar = ($this->product)('Sugar', $this->g);
    $jar = ($this->product)('Jar', $this->pcs);
    $receipt = ($this->receiptOf)([[$sugar, '3000', '0.10'], [$jar, '20', '40.00']]);

    $plan = ($this->plan)(($this->save)($receipt, [($this->costLine)('labour', '500', ['allocation_method' => 'quantity'])]));

    expect($plan->isPostable())->toBeFalse()
        ->and($plan->refusals[0])->toContain('mixes units that do not convert')
        ->and($plan->refusals[0])->toContain('Sugar (g)')
        ->and($plan->refusals[0])->toContain('Jar (pcs)');
});

it('spreads by weight from the product weights, and refuses a product with no weight by name', function () {
    $salt = ($this->product)('Salt', $this->kg);
    $jar = ($this->product)('Jar', $this->pcs, 250);
    $label = ($this->product)('Label', $this->pcs);

    // 5 kg of salt (its unit says 5,000 g) and 20 jars at 250 g (5,000 g): half each.
    $receipt = ($this->receiptOf)([[$salt, '5', '20.00'], [$jar, '20', '40.00']]);
    $lc = ($this->postAll)(($this->save)($receipt, [($this->costLine)('freight', '1000.01', ['allocation_method' => 'weight'])]));
    $posted = $lc->lines->first()->allocations->sortBy('id')->values();

    expect($posted->map(fn ($a) => (int) $a->share_paisa)->all())->toBe([50001, 50000])
        ->and($posted->sum('share_paisa'))->toBe(100001)
        ->and((string) $posted[1]->basis_quantity)->toBe('5000.0000')
        ->and($posted[1]->basis_unit)->toBe('g');

    $missing = ($this->receiptOf)([[$jar, '10', '40.00'], [$label, '100', '1.00']]);
    $plan = ($this->plan)(($this->save)($missing, [($this->costLine)('freight', '300', ['allocation_method' => 'weight'])]));

    expect($plan->isPostable())->toBeFalse()
        ->and($plan->refusals[0])->toContain('no weight is set for Label')
        ->and($plan->refusals[0])->not->toContain('Jar');
});

it('needs a reason to override the default, and the record keeps both the default and the reason', function () {
    $salt = ($this->product)('Salt', $this->kg);
    $receipt = ($this->receiptOf)([[$salt, '25', '20.00']]);

    try {
        ($this->save)($receipt, [($this->costLine)('freight', '350', ['treatment' => 'expense'])]);
        $this->fail('Carriage inward expensed without a reason was accepted.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('lines.0.override_reason');
    }

    // Travel capitalised needs a reason too, and an "other" cost must say what it is.
    expect(fn () => ($this->save)($receipt, [($this->costLine)('travel', '200', ['treatment' => 'capitalize'])]))->toThrow(ValidationException::class);

    try {
        ($this->save)($receipt, [($this->costLine)('other', '200', ['treatment' => 'capitalize', 'override_reason' => 'Owner says so'])]);
        $this->fail('An unclassified cost was capitalised.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('lines.0.description');
    }

    $lc = ($this->postAll)(($this->save)($receipt, [($this->costLine)('freight', '350', ['treatment' => 'expense', 'override_reason' => 'Finance: the truck also carried office furniture'])]));
    $line = $lc->lines->first();

    expect($line->treatment)->toBe(LandedCostTreatment::Expense)
        ->and($line->defaultTreatment())->toBe(LandedCostTreatment::Capitalize)
        ->and($line->overridesDefault())->toBeTrue()
        ->and($line->override_reason)->toContain('office furniture')
        ->and(($this->balanceOnCode)('5300'))->toBe(35000);

    // The posted record on the screen says what the default was and why it was not used.
    $this->actingAs($this->user)->get('/purchasing/landed-costs')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('costTypes', 7)
            ->where('landedCosts.0.status', 'posted')
            ->where('landedCosts.0.lines.0.default_treatment', 'capitalize')
            ->where('landedCosts.0.lines.0.overrides_default', true)
            ->where('landedCosts.0.lines.0.override_reason', 'Finance: the truck also carried office furniture')
            ->etc());
});

it('warns when the same cost is already posted as an expense, and reverses nothing', function () {
    $salt = ($this->product)('Salt', $this->kg);
    $receipt = ($this->receiptOf)([[$salt, '25', '20.00']]);
    $poNumber = $receipt->purchaseOrder->po_number;

    $voucher = fn (string $code, string $amount, string $on, ?string $reference = null) => app(RecordExpenseVoucher::class)->handle(
        moneyAccount: $this->bank,
        spentOn: $on,
        lines: [['account' => Account::where('code', $code)->firstOrFail(), 'amount' => $amount, 'description' => 'Truck']],
        createdBy: $this->user,
        reference: $reference,
        checkBalance: false,
    );

    $freightVoucher = $voucher('5300', '980.00', '2026-10-11');          // the freight head, within 10%
    $voucher('5300', '5000.00', '2026-10-11');                           // same head, not a similar amount
    $voucher('6058', '1000.00', '2026-08-01');                           // long before the goods: out of the window
    $named = $voucher('6058', '995.00', '2026-10-12', "Paid for {$poNumber}"); // another head, but it names the order

    $lc = ($this->save)($receipt, [($this->costLine)('freight', '1000'), ($this->costLine)('travel', '300')]);
    $warnings = app(LandedCostDoubleCount::class)->warningsFor($lc->fresh(['lines', 'goodsReceipt']));
    $freightLine = $lc->lines->firstWhere('cost_type', LandedCostType::Freight);

    expect($warnings)->toHaveKey($freightLine->id)
        ->and($warnings[$freightLine->id])->toHaveCount(2)
        ->and($warnings[$freightLine->id][0])->toContain($freightVoucher->voucher_number)
        ->and($warnings[$freightLine->id][0])->toContain('capitalising it again counts it twice; reverse that voucher first through Corrections')
        ->and(implode(' ', $warnings[$freightLine->id]))->toContain($named->voucher_number)
        // The 300 travel line matches nothing of a similar amount.
        ->and($warnings)->not->toHaveKey($lc->lines->firstWhere('cost_type', LandedCostType::Travel)->id);

    // The popup reads the same evidence before saving; nothing was reversed.
    $this->actingAs($this->user)->getJson(route('purchasing.landed-costs.expense-check', $receipt->id))
        ->assertOk()
        ->assertJsonPath('similar_percent', 10)
        ->assertJsonCount(3, 'candidates');

    $this->actingAs($this->user)->get('/purchasing/landed-costs')
        ->assertInertia(fn ($page) => $page->where('landedCosts.0.lines.0.double_count.0', $warnings[$freightLine->id][0])->etc());

    expect($freightVoucher->fresh()->isReversed())->toBeFalse()
        ->and($named->fresh()->isReversed())->toBeFalse();
});

it('raises a supplier bill for what a landed cost owes, paid through the normal payment with AP tying', function () {
    $salt = ($this->product)('Salt', $this->kg);
    $receipt = ($this->receiptOf)([[$salt, '25', '20.00']]);

    $lc = ($this->postAll)(($this->save)($receipt, [
        ($this->costLine)('freight', '700'),
        ($this->costLine)('labour', '300'),
        // Already paid from the bank: no bill, nothing owed.
        ($this->costLine)('bank_charge', '50', ['supplier_id' => null, 'money_account_id' => $this->bank->id]),
    ]));

    $bill = SupplierBill::query()->where('landed_cost_id', $lc->id)->sole();

    expect($bill->supplier_id)->toBe($this->carrier->id)
        ->and($bill->bill_number)->toBe($lc->number)
        ->and($bill->purchase_order_id)->toBeNull()
        ->and($bill->lines()->count())->toBe(0)
        ->and($bill->outstanding()->minor)->toBe(100000)
        // Counted ONCE: Accounts Payable holds 1,000 for the carrier, not 2,000.
        ->and(($this->balanceOn)('accounts_payable'))->toBe(-100000)
        ->and(SubledgerReconciliation::reconcileCurrentBusiness()['problems'])->toBeEmpty();

    // The bill posts no journal of its own and offers itself to "Pay a supplier".
    expect(JournalLine::query()->whereHas('journal', fn ($q) => $q->where('source_type', $bill->getMorphClass())->where('source_id', $bill->id))->count())->toBe(0)
        ->and(SupplierBill::query()->owingWithOutstanding()->pluck('id')->all())->toContain($bill->id);

    app(RecordSupplierPayment::class)->handle(
        supplier: $this->carrier,
        moneyAccount: $this->bank,
        paidOn: '2026-10-18',
        allocations: [['bill' => $bill, 'amount' => '1000.00']],
        createdBy: $this->user,
        reference: 'TRX-LC-1',
    );

    $statement = SupplierStatement::for($this->carrier, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));

    expect($bill->fresh()->outstanding()->minor)->toBe(0)
        ->and(($this->balanceOn)('accounts_payable'))->toBe(0)
        ->and($statement->closing()->minor)->toBe(0)
        ->and($statement->isConsistent())->toBeTrue()
        ->and(SubledgerReconciliation::reconcileCurrentBusiness()['problems'])->toBeEmpty();

    // It cannot be reversed on its own: that would leave the landed cost's payable standing.
    $unpaid = ($this->postAll)(($this->save)($receipt, [($this->costLine)('freight', '100')]));
    expect(fn () => app(ReverseSupplierBill::class)->handle($unpaid->bills->sole(), $this->user))
        ->toThrow(DomainException::class, 'records a posted landed cost');
});

it('leaves a landed cost posted before today exactly as it was and stamps the default it was saved under', function () {
    $salt = ($this->product)('Salt', $this->kg);
    $receipt = ($this->receiptOf)([[$salt, '25', '20.00']]);

    $lc = ($this->postAll)(($this->save)($receipt, [
        ($this->costLine)('packaging', '1000'),
        ($this->costLine)('freight', '350', ['treatment' => 'expense', 'override_reason' => 'x']),
    ]));

    // As a 17 Oct document stood: no stored default, freight expensed by the old default, no bill.
    DB::table('landed_cost_lines')->update(['default_treatment' => null]);
    DB::table('landed_cost_lines')->where('cost_type', 'freight')->update(['override_reason' => null]);
    DB::table('supplier_bills')->where('landed_cost_id', $lc->id)->delete();

    $snapshot = fn () => [
        DB::table('landed_costs')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        DB::table('landed_cost_lines')->orderBy('id')->get(['id', 'cost_type', 'treatment', 'override_reason', 'allocation_method', 'amount_paisa', 'supplier_id'])->map(fn ($r) => (array) $r)->all(),
        DB::table('landed_cost_allocations')->orderBy('id')->get(['id', 'share_paisa', 'on_hand_share_paisa', 'consumed_share_paisa', 'new_unit_cost'])->map(fn ($r) => (array) $r)->all(),
        DB::table('journal_lines')->orderBy('id')->get(['id', 'account_id', 'amount_minor'])->map(fn ($r) => (array) $r)->all(),
    ];
    $before = $snapshot();

    (require base_path('app/Modules/Purchasing/database/migrations/2026_10_18_630000_landed_cost_types_bases_and_supplier_bills.php'))->up();

    $lines = $lc->fresh('lines')->lines;

    expect($snapshot())->toBe($before)
        ->and($lines->firstWhere('cost_type', LandedCostType::Packaging)->default_treatment)->toBe(LandedCostTreatment::Capitalize)
        ->and($lines->firstWhere('cost_type', LandedCostType::Freight)->default_treatment)->toBe(LandedCostTreatment::Expense)
        // Expensed under the old default, so it is not an unexplained override.
        ->and($lines->firstWhere('cost_type', LandedCostType::Freight)->overridesDefault())->toBeFalse()
        ->and(SupplierBill::query()->where('landed_cost_id', $lc->id)->exists())->toBeFalse()
        // Without a bill the old way still ties: the posted line counts on its own.
        ->and(SubledgerReconciliation::reconcileCurrentBusiness()['problems'])->toBeEmpty();
});
