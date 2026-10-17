<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Support\LedgerIntegrity;
use App\Modules\Accounting\Support\SubledgerReconciliation;
use App\Modules\Accounting\Support\VoucherAccounts;
use App\Modules\Foundation\database\seeders\ApprovalWorkflowSeeder;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\Actions\RecordStockOut;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Actions\ApproveLandedCost;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Actions\PlanLandedCostAllocation;
use App\Modules\Purchasing\Actions\PostLandedCost;
use App\Modules\Purchasing\Actions\RecordGoodsReceipt;
use App\Modules\Purchasing\Actions\SaveLandedCost;
use App\Modules\Purchasing\Actions\SubmitLandedCost;
use App\Modules\Purchasing\Enums\LandedCostStatus;
use App\Modules\Purchasing\Models\LandedCost;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Support\LandedCostPolicy;
use App\Support\ApprovalCards;
use App\Support\ApprovalInbox;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Narrow landed cost (Phase 1, 17 Oct 2026, ChatGPT ruling Q4 to Q6).
 *
 * The cases the plan names: packaging on a 25 kg receipt fully on hand raises
 * the batch and posts Dr Inventory / Cr Payable; after 5 kg consumed the same
 * cost splits 800 inventory / 200 cost of sales; freight stays an expense and
 * touches no batch; a bank charge capitalised needs a reason; a consumed share
 * of 12,000 is refused for an Owner decision; a cost over three batches sums
 * to the paisa.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-17 10:00:00');

    $this->business = Business::create(['name' => 'Salt Co', 'slug' => 'salt-co', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['purchasing.landed_cost.view', 'purchasing.landed_cost.manage', 'purchasing.landed_cost.post', 'purchasing.po.approve'] as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $role = Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    $role->givePermissionTo(['purchasing.landed_cost.view', 'purchasing.landed_cost.manage', 'purchasing.landed_cost.post', 'purchasing.po.approve']);

    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->user->assignRole($role);

    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(InventoryAccountMappingSeeder::class);
    (new ApprovalWorkflowSeeder)->run();
    TenantScope::enter($this->business->id);

    $this->kg = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Kilogram', 'abbreviation' => 'kg']);
    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Savar', 'code' => 'SAV']);
    $this->salt = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $this->kg->id,
        'sku' => 'SALT-BULK',
        'name' => 'Salt, bulk',
        'type' => ProductType::RawMaterial,
    ]);
    $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'Chattogram Salt', 'code' => 'SUP-001']);
    $this->packager = Supplier::create(['business_id' => $this->business->id, 'name' => 'Dhaka Packaging', 'code' => 'SUP-002']);

    // 25 kg of salt at 20.00/kg, received on 10 Oct.
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

    $this->batchOf = fn ($receipt, int $index = 0) => StockBatch::find($receipt->lines[$index]->stockMovement->batch_id);

    $this->save = fn ($receipt, array $lines, string $on = '2026-10-15') => app(SaveLandedCost::class)->handle($receipt, $on, $lines, $this->user);

    $this->postAll = function (LandedCost $lc) {
        $lc = app(SubmitLandedCost::class)->handle($lc, $this->user);

        return app(PostLandedCost::class)->handle($lc->fresh(['approvalRequest', 'lines']), $this->user);
    };

    $this->balanceOn = fn (string $slug) => (int) JournalLine::where('account_id', Account::bySlug($slug)->id)->sum('amount_minor');
    $this->balanceOnCode = fn (string $code) => (int) JournalLine::where('account_id', Account::where('code', $code)->firstOrFail()->id)->sum('amount_minor');

    // ⚠ `$extra + [...]`: PHP's array union keeps the LEFT side, so the overrides must come first.
    $this->packaging = fn (string $amount, array $extra = []) => $extra + [
        'cost_type' => 'packaging', 'treatment' => null, 'override_reason' => null, 'allocation_method' => 'value',
        'goods_receipt_line_id' => null, 'amount' => $amount, 'supplier_id' => $this->packager->id,
        'money_account_id' => null, 'expense_account_id' => null, 'description' => 'Jute sacks',
    ];
});

it('capitalises packaging onto a batch fully on hand: cost up by 40/kg and Dr Inventory Cr Payable', function () {
    $receipt = ($this->receiptOf)([[$this->salt, '25', '20.00']]);
    $batch = ($this->batchOf)($receipt);

    $lc = ($this->save)($receipt, [($this->packaging)('1000')]);

    expect($lc->status)->toBe(LandedCostStatus::Draft)
        ->and($lc->number)->toStartWith('LC-2026-27-');

    $lc = ($this->postAll)($lc);
    $batch->refresh();

    // 1,000 over 25 kg = 40.00/kg more (0.04 per gram): 20.00 becomes 60.00.
    expect($lc->status)->toBe(LandedCostStatus::Posted)
        ->and((string) $batch->unit_cost)->toBe('60.0000')
        ->and((float) $batch->current_quantity)->toBe(25.0);

    // The revaluation primitive: quantity 0, value_delta the on-hand share, reason landed_cost.
    $movement = StockMovement::where('batch_id', $batch->id)->where('quantity', '0.0000')->sole();
    expect((string) $movement->value_delta)->toBe('1000.0000')
        ->and($movement->reason->value)->toBe('landed_cost')
        ->and((string) $movement->unit_cost)->toBe('60.0000');

    // Dr Inventory 1,000 / Cr Accounts Payable 1,000 with the packager as party; nothing on write-off.
    expect(($this->balanceOn)('stock_on_hand'))->toBe(100000 + 50000)
        ->and(($this->balanceOn)('accounts_payable'))->toBe(-100000)
        ->and(($this->balanceOn)('inventory_write_off'))->toBe(0)
        ->and(($this->balanceOn)('landed_cost_consumed'))->toBe(0);

    $payable = JournalLine::where('account_id', Account::bySlug('accounts_payable')->id)->first();
    expect($payable->party->is($this->packager))->toBeTrue();

    $journal = Journal::find($lc->journal_id);
    expect($journal->balance()->isZero())->toBeTrue()
        ->and($journal->source_id)->toBe($lc->id);

    // The evidence, frozen.
    $allocation = $lc->lines->first()->allocations->sole();
    expect((int) $allocation->share_paisa)->toBe(100000)
        ->and((int) $allocation->on_hand_share_paisa)->toBe(100000)
        ->and((int) $allocation->consumed_share_paisa)->toBe(0)
        ->and((string) $allocation->new_unit_cost)->toBe('60.0000')
        ->and($allocation->stock_movement_id)->toBe($movement->id);

    expect(LedgerIntegrity::verifyAllBusinesses()['problems'])->toBeEmpty()
        ->and(SubledgerReconciliation::reconcileCurrentBusiness()['problems'])->toBeEmpty();
});

it('splits the cost between stock on hand and stock already consumed: 800 inventory, 200 cost of sales', function () {
    $receipt = ($this->receiptOf)([[$this->salt, '25', '20.00']]);
    $batch = ($this->batchOf)($receipt);

    app(RecordStockOut::class)->handle(product: $this->salt, warehouse: $this->warehouse, quantity: '5', performedBy: $this->user, occurredAt: '2026-10-12');

    $plan = app(PlanLandedCostAllocation::class)->handle(($this->save)($receipt, [($this->packaging)('1000')]));
    $allocation = $plan->lines[array_key_first($plan->lines)]['allocations'][0];

    expect($plan->isPostable())->toBeTrue()
        ->and($allocation['quantity_on_hand'])->toBe('20.0000')
        ->and($allocation['quantity_consumed'])->toBe('5.0000')
        ->and($allocation['on_hand_share_paisa'])->toBe(80000)
        ->and($allocation['consumed_share_paisa'])->toBe(20000)
        // 800 over the 20 kg still here: still 40/kg more.
        ->and($allocation['new_unit_cost'])->toBe('60.0000');

    ($this->postAll)(LandedCost::first());
    $batch->refresh();

    expect((string) $batch->unit_cost)->toBe('60.0000')
        // Received 500 plus the 800 on-hand share. ⚠ A bare RecordStockOut
        // (no sale, no production run behind it) posts no journal by design,
        // so the 100 that left is not in the ledger here; the split is what
        // this case proves, and the reconciliation is proved by the others.
        ->and(($this->balanceOn)('stock_on_hand'))->toBe(50000 + 80000)
        ->and(($this->balanceOn)('landed_cost_consumed'))->toBe(20000)
        ->and(($this->balanceOn)('accounts_payable'))->toBe(-100000)
        // The 5 kg that left did so at 20.00: history does not move.
        ->and((string) StockMovement::where('direction', 'out')->sole()->unit_cost)->toBe('20.0000');

    // The ledger's inventory debit equals the movement value_delta to the paisa.
    $revaluation = StockMovement::where('batch_id', $batch->id)->where('quantity', '0.0000')->sole();
    expect((string) $revaluation->value_delta)->toBe('800.0000');
});

it('expenses freight by default: 5300 is debited and no batch changes', function () {
    $receipt = ($this->receiptOf)([[$this->salt, '25', '20.00']]);
    $batch = ($this->batchOf)($receipt);

    $lc = ($this->save)($receipt, [[
        'cost_type' => 'freight', 'treatment' => null, 'override_reason' => null, 'allocation_method' => null,
        'goods_receipt_line_id' => null, 'amount' => '350', 'supplier_id' => $this->supplier->id,
        'money_account_id' => null, 'expense_account_id' => null, 'description' => 'Truck from Chattogram',
    ]]);

    expect($lc->lines->first()->treatment->value)->toBe('expense');

    ($this->postAll)($lc);
    $batch->refresh();

    expect((string) $batch->unit_cost)->toBe('20.0000')
        ->and(StockMovement::where('batch_id', $batch->id)->count())->toBe(1)
        ->and(($this->balanceOnCode)('5300'))->toBe(35000)
        ->and(($this->balanceOn)('accounts_payable'))->toBe(-35000)
        ->and(($this->balanceOn)('stock_on_hand'))->toBe(50000);
});

it('refuses to capitalise a bank charge without a reason, and records the override with one', function () {
    $receipt = ($this->receiptOf)([[$this->salt, '25', '20.00']]);

    $line = [
        'cost_type' => 'bank_charge', 'treatment' => 'capitalize', 'override_reason' => '', 'allocation_method' => 'value',
        'goods_receipt_line_id' => null, 'amount' => '50', 'supplier_id' => $this->supplier->id,
        'money_account_id' => null, 'expense_account_id' => null, 'description' => null,
    ];

    try {
        ($this->save)($receipt, [$line]);
        $this->fail('A bank charge capitalised without a reason was accepted.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('lines.0.override_reason')
            ->and($e->errors()['lines.0.override_reason'][0])->toContain('by default');
    }

    $lc = ($this->save)($receipt, [['override_reason' => 'Owner approved: the charge was the bank draft for this consignment only'] + $line]);

    expect($lc->lines->first()->treatment->value)->toBe('capitalize')
        ->and($lc->lines->first()->overridesDefault())->toBeTrue()
        ->and($lc->lines->first()->override_reason)->toContain('Owner approved');

    // Expensed by default, the same charge goes to 6471 Bank Charge with no reason needed.
    $expensed = ($this->save)($receipt, [['treatment' => null] + $line]);
    expect($expensed->lines->first()->treatment->value)->toBe('expense')
        ->and($expensed->lines->first()->override_reason)->toBeNull();

    ($this->postAll)($expensed);
    expect(($this->balanceOnCode)('6471'))->toBe(5000);
});

it('refuses a consumed share above Tk 10,000: it needs an Owner accounting decision', function () {
    $receipt = ($this->receiptOf)([[$this->salt, '25', '20.00']]);
    app(RecordStockOut::class)->handle(product: $this->salt, warehouse: $this->warehouse, quantity: '20', performedBy: $this->user, occurredAt: '2026-10-12');

    // 15,000 over 25 kg with 20 kg gone: 12,000 belongs to stock already used.
    $lc = ($this->save)($receipt, [($this->packaging)('15000')]);
    $plan = app(PlanLandedCostAllocation::class)->handle($lc);

    expect($plan->consumed->minor)->toBe(1200000)
        ->and($plan->isPostable())->toBeFalse()
        ->and($plan->refusals[0])->toContain(LandedCostPolicy::OWNER_DECISION)
        ->and($plan->refusals[0])->toContain('Tk 12,000');

    expect(fn () => app(SubmitLandedCost::class)->handle($lc, $this->user))
        ->toThrow(RuntimeException::class, LandedCostPolicy::OWNER_DECISION);

    // Nothing was written: no approval request, no journal, the batch untouched.
    expect($lc->fresh()->approvalRequest)->toBeNull()
        ->and(Journal::count())->toBe(1)
        ->and((string) ($this->batchOf)($receipt)->fresh()->unit_cost)->toBe('20.0000');

    // Within the limit it goes through: 12,500 over the same receipt consumes exactly 10,000.
    $within = ($this->save)($receipt, [($this->packaging)('12500')]);
    expect(app(PlanLandedCostAllocation::class)->handle($within)->isPostable())->toBeTrue();
});

it('refuses a closed month and a date before the goods arrived', function () {
    $receipt = ($this->receiptOf)([[$this->salt, '25', '20.00']]);

    $early = ($this->save)($receipt, [($this->packaging)('100')], '2026-10-05');
    $plan = app(PlanLandedCostAllocation::class)->handle($early);
    expect($plan->isPostable())->toBeFalse()
        ->and($plan->refusals[0])->toContain('before the goods arrived');

    AccountingPeriod::closeThrough(Carbon::parse('2026-10-31'), $this->user);

    $closed = ($this->save)($receipt, [($this->packaging)('100')], '2026-10-15');
    $plan = app(PlanLandedCostAllocation::class)->handle($closed);
    expect($plan->isPostable())->toBeFalse()
        ->and(implode(' ', $plan->refusals))->toContain('closed month')
        ->and(implode(' ', $plan->refusals))->toContain(LandedCostPolicy::OWNER_DECISION);
});

it('puts the leftover paisa on the largest share so three batches sum exactly to 1,000', function () {
    $pepper = Product::create(['business_id' => $this->business->id, 'unit_of_measure_id' => $this->kg->id, 'sku' => 'PEP', 'name' => 'Pepper', 'type' => ProductType::RawMaterial]);
    $cumin = Product::create(['business_id' => $this->business->id, 'unit_of_measure_id' => $this->kg->id, 'sku' => 'CUM', 'name' => 'Cumin', 'type' => ProductType::RawMaterial]);

    // Three lines of equal value (300 each): 1,000 / 3 does not divide.
    $receipt = ($this->receiptOf)([[$this->salt, '10', '30.00'], [$pepper, '3', '100.00'], [$cumin, '1', '300.00']]);

    $lc = ($this->postAll)(($this->save)($receipt, [($this->packaging)('1000')]));
    $allocations = $lc->lines->first()->allocations->sortBy('id')->values();

    expect($allocations)->toHaveCount(3)
        ->and($allocations->map(fn ($a) => (int) $a->share_paisa)->all())->toBe([33334, 33333, 33333])
        ->and($allocations->sum('share_paisa'))->toBe(100000)
        ->and($allocations->sum('on_hand_share_paisa'))->toBe(100000);

    // Each batch restated from its own share: 333.34 over 10 kg, 333.33 over 3 kg, 333.33 over 1 kg.
    expect($allocations->map(fn ($a) => (string) $a->new_unit_cost)->all())->toBe(['63.3340', '211.1100', '633.3300']);

    expect(($this->balanceOn)('stock_on_hand'))->toBe(90000 + 100000)
        ->and(($this->balanceOn)('accounts_payable'))->toBe(-100000)
        ->and(Journal::find($lc->journal_id)->balance()->isZero())->toBeTrue()
        ->and(SubledgerReconciliation::reconcileCurrentBusiness()['problems'])->toBeEmpty();
});

it('lands a direct line on the one receipt line it names and refuses one that put nothing into stock', function () {
    $pepper = Product::create(['business_id' => $this->business->id, 'unit_of_measure_id' => $this->kg->id, 'sku' => 'PEP', 'name' => 'Pepper', 'type' => ProductType::RawMaterial]);
    $receipt = ($this->receiptOf)([[$this->salt, '10', '30.00'], [$pepper, '3', '100.00']]);
    $pepperLine = $receipt->lines[1];

    $lc = ($this->postAll)(($this->save)($receipt, [($this->packaging)('90', ['allocation_method' => 'direct', 'goods_receipt_line_id' => $pepperLine->id])]));
    $allocation = $lc->lines->first()->allocations->sole();

    expect($allocation->goods_receipt_line_id)->toBe($pepperLine->id)
        ->and((int) $allocation->share_paisa)->toBe(9000)
        ->and((string) ($this->batchOf)($receipt, 1)->fresh()->unit_cost)->toBe('130.0000')
        ->and((string) ($this->batchOf)($receipt, 0)->fresh()->unit_cost)->toBe('30.0000');

    expect(fn () => ($this->save)($receipt, [($this->packaging)('90', ['allocation_method' => 'direct', 'goods_receipt_line_id' => 999999])]))
        ->toThrow(ValidationException::class);
});

it('reaches the approval inbox when the submitter may not post, and the card states its facts', function () {
    $receipt = ($this->receiptOf)([[$this->salt, '25', '20.00']]);

    $clerk = User::factory()->create();
    $clerk->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $clerk->givePermissionTo('purchasing.landed_cost.manage');

    $lc = app(SaveLandedCost::class)->handle($receipt, '2026-10-15', [($this->packaging)('1000')], $clerk);
    $lc = app(SubmitLandedCost::class)->handle($lc, $clerk);

    expect($lc->isAwaitingApproval())->toBeTrue()
        ->and($lc->approvalRequest->workflow_id)->not->toBeNull();

    $pending = app(ApprovalInbox::class)->pendingActionableFor($this->user);
    expect($pending->pluck('approvable_id')->all())->toContain($lc->id);

    $card = app(ApprovalCards::class)->present($pending->first(fn ($r) => $r->approvable_id === $lc->id));
    expect($card['type'])->toBe('Landed cost')
        ->and(collect($card['details'])->pluck('label')->all())->toContain('Added to stock value')
        ->and($card['approve_url'])->toContain("/landed-costs/{$lc->id}/approve");

    // Posting before approval is refused; after the Owner approves it posts.
    expect(fn () => app(PostLandedCost::class)->handle($lc, $this->user))->toThrow(RuntimeException::class, 'not approved');

    $lc = app(ApproveLandedCost::class)->handle($lc, $this->user);
    expect($lc->status)->toBe(LandedCostStatus::Approved);

    $lc = app(PostLandedCost::class)->handle($lc, $this->user);
    expect($lc->status)->toBe(LandedCostStatus::Posted);
});

it('keeps the typed expense heads usable by ordinary expense vouchers (PUR-002 shared heads)', function () {
    $freight = Account::where('code', '5300')->firstOrFail();
    $bank = Account::where('code', '6471')->firstOrFail();

    expect(VoucherAccounts::refusal($freight, Carbon::today()))->toBeNull()
        ->and(VoucherAccounts::refusal($bank, Carbon::today()))->toBeNull()
        // The feature's own head stays its own.
        ->and(VoucherAccounts::refusal(Account::bySlug('landed_cost_consumed'), Carbon::today()))->not->toBeNull();
});
