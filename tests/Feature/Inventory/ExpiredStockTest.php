<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Foundation\Actions\RejectApprovable;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\Actions\ApproveInventoryAdjustment;
use App\Modules\Inventory\Actions\ProposeExpiredStockWriteOffs;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\Actions\RecordStockOut;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\AdjustmentDirection;
use App\Modules\Inventory\Enums\InventoryAdjustmentType;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Support\Carbon;
use Spatie\Permission\PermissionRegistrar;

/**
 * C-INV-3, folded into the one adjustment workflow rather than built beside it.
 *
 * ⚠⚠ Expired stock was SOLD. `StockBatchStatus::Expired` exists as an enum case
 * and NOTHING in the codebase ever set it - the consumable scope filtered on
 * `status = active` and never once compared `expiry_date` to today. So a batch
 * that expired last year stayed active, stayed consumable, and went out of the
 * door, while the enum case made the whole thing look handled.
 *
 * The fix has two halves and only one of them is the workflow:
 *
 *  (a) UNAVAILABLE, by DATE. True whether or not any sweep has run, which is why
 *      it is the half that actually protects a customer.
 *  (b) The WRITE-OFF, which proposes an adjustment and lets a human approve it.
 *      It never posts on its own - Joy: expired stock leaves only through an
 *      authorised write-off.
 *
 * See INVENTORY-ADJUSTMENT-WORKFLOW-ARCHITECTURE.md §6.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Expiry Co', 'slug' => 'expiry-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $this->product = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-EXP',
        'name' => 'Seed packet',
        'type' => ProductType::RawMaterial,
    ]);
    Reason::create([
        'business_id' => $this->business->id,
        'context' => 'inventory.stock_adjustment',
        'name' => 'Expired',
        'is_active' => true,
    ]);
    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);

    Carbon::setTestNow('2026-08-02 09:00:00');

    $this->receive = fn (string $qty, ?string $expiry) => app(RecordStockIn::class)->handle(
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: $qty,
        unitCost: '100',
        performedBy: $this->user,
        expiryDate: $expiry,
        occurredAt: '2026-07-01 09:00:00',
    );
});

afterEach(function () {
    Carbon::setTestNow();
});

it('will not sell stock that has expired', function () {
    // ⚠ THE DEFECT. Before this the whole 10 was consumable and went out.
    ($this->receive)('10', '2026-07-15');

    expect(fn () => app(RecordStockOut::class)->handle(
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '1',
        performedBy: $this->user,
    ))->toThrow(InsufficientStockException::class);
});

it('skips the expired layer and consumes the good one behind it', function () {
    ($this->receive)('10', '2026-07-15');   // expired
    ($this->receive)('5', '2026-12-31');    // still good

    app(RecordStockOut::class)->handle(
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '5',
        performedBy: $this->user,
    );

    $batches = StockBatch::orderBy('id')->get();

    // ⚠ The expired batch is untouched, not quietly consumed to satisfy the
    // withdrawal. It still holds its 10 units until somebody writes them off.
    expect((float) $batches[0]->current_quantity)->toBe(10.0)
        ->and((float) $batches[1]->current_quantity)->toBe(0.0);
});

it('treats stock expiring today as still good', function () {
    // A batch is usable through the whole of its expiry date. Off-by-one here
    // destroys a day of perfectly sellable stock every time.
    ($this->receive)('10', '2026-08-02');

    app(RecordStockOut::class)->handle(
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '10',
        performedBy: $this->user,
    );

    expect((float) StockBatch::sum('current_quantity'))->toBe(0.0);
});

it('leaves stock with no expiry date alone', function () {
    ($this->receive)('10', null);

    app(RecordStockOut::class)->handle(
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '10',
        performedBy: $this->user,
    );

    expect((float) StockBatch::sum('current_quantity'))->toBe(0.0);
});

it('proposes a write-off for expired stock instead of posting one', function () {
    // ⚠ Joy: expired stock leaves only through an AUTHORISED write-off. The
    // sweep raises the proposal; a human approves it. It travels the ordinary
    // adjustment pipeline, so it gets the same approval, audit trail and
    // movements as everything else.
    ($this->receive)('10', '2026-07-15');

    app(ProposeExpiredStockWriteOffs::class)->handle();

    $adjustment = InventoryAdjustment::sole();

    expect($adjustment->type)->toBe(InventoryAdjustmentType::Expired)
        ->and($adjustment->direction)->toBe(AdjustmentDirection::Decrease)
        ->and((float) $adjustment->quantity)->toBe(10.0)
        ->and($adjustment->approvalRequest->isPending())->toBeTrue()
        // Nothing posted. The stock is still on the books, just unsellable.
        ->and((float) StockBatch::sum('current_quantity'))->toBe(10.0);
});

it('does not stack a new proposal every time it runs', function () {
    // ⚠ A nightly job that duplicates is the all-or-nothing seeder bug with a
    // clock attached, and this project has been bitten by that shape twice.
    ($this->receive)('10', '2026-07-15');

    app(ProposeExpiredStockWriteOffs::class)->handle();
    app(ProposeExpiredStockWriteOffs::class)->handle();
    app(ProposeExpiredStockWriteOffs::class)->handle();

    expect(InventoryAdjustment::count())->toBe(1);
});

it('proposes nothing when there is nothing expired', function () {
    ($this->receive)('10', '2026-12-31');
    ($this->receive)('5', null);

    app(ProposeExpiredStockWriteOffs::class)->handle();

    expect(InventoryAdjustment::count())->toBe(0);
});

it('sweeps every business when run with no tenant context, the way the scheduler does', function () {
    // ⚠⚠ THE TRAP THIS ALMOST SHIPPED WITH. StockBatch is business-scoped, and a
    // scheduled run has no BusinessContext, so BusinessScope forces `1 = 0` and
    // the sweep would have found NOTHING - silently, nightly, for ever. The
    // tests above all passed because beforeEach had set a context.
    //
    // This is the same family as W1, where queued jobs restored the tenant but
    // not its permissions: code that is correct inside a request and wrong
    // outside one. So this test runs it the way the scheduler will - cold.
    ($this->receive)('10', '2026-07-15');

    $second = Business::create(['name' => 'Second Co', 'slug' => 'second-co']);
    app(BusinessContext::class)->set($second->id);

    $otherWarehouse = Warehouse::create(['business_id' => $second->id, 'name' => 'Other', 'code' => 'OTHER']);
    $otherUnit = UnitOfMeasure::create(['business_id' => $second->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $otherProduct = Product::create([
        'business_id' => $second->id,
        'unit_of_measure_id' => $otherUnit->id,
        'sku' => 'SKU-EXP2',
        'name' => 'Other seed',
        'type' => ProductType::RawMaterial,
    ]);

    app(RecordStockIn::class)->handle(
        product: $otherProduct,
        warehouse: $otherWarehouse,
        quantity: '7',
        unitCost: '50',
        performedBy: $this->user,
        expiryDate: '2026-07-20',
        occurredAt: '2026-07-01 09:00:00',
    );

    // Cold: exactly what the scheduler hands it. ⚠ Through TenantScope::leave()
    // rather than by hand - it clears the permission team id too, and clearing
    // only half is the defect W1 existed to end.
    TenantScope::leave();

    app(ProposeExpiredStockWriteOffs::class)->handle();

    expect(InventoryAdjustment::withoutGlobalScopes()->count())->toBe(2);
});

it('writes off the expired batch it names, not the good stock behind it', function () {
    // ⚠⚠ THE DEFECT. Approval used to post the write-off through FIFO, which
    // skips expired stock, so it took the GOOD batch and left the expired one
    // full - to be proposed again the next night.
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(InventoryAccountMappingSeeder::class);
    TenantScope::enter($this->business->id);

    ($this->receive)('10', '2026-07-15');   // expired, at 100
    app(RecordStockIn::class)->handle(
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '5',
        unitCost: '300',
        performedBy: $this->user,
        expiryDate: '2026-12-31',
        occurredAt: '2026-07-01 09:00:00',
    );

    app(ProposeExpiredStockWriteOffs::class)->handle();
    $adjustment = InventoryAdjustment::sole();

    app(ApproveInventoryAdjustment::class)->handle($adjustment, $this->user);

    $batches = StockBatch::orderBy('id')->get();

    expect((float) $batches[0]->current_quantity)->toBe(0.0)
        ->and((float) $batches[1]->current_quantity)->toBe(5.0);

    // Valued at the expired batch's own cost: 10 at 100 = 1,000 out of stock.
    $journal = Journal::where('source_type', $adjustment->getMorphClass())->sole();
    $stockOnHand = (int) JournalLine::where('account_id', Account::bySlug('stock_on_hand')->id)->sum('amount_minor');

    expect($journal->balance()->isZero())->toBeTrue()
        ->and($stockOnHand)->toBe(-100000)
        ->and((float) $adjustment->fresh()->estimated_value)->toBe(1000.0);

    // Emptied, so the next sweep has nothing to propose.
    app(ProposeExpiredStockWriteOffs::class)->handle();
    expect(InventoryAdjustment::count())->toBe(1);
});

it('approves an expired write-off when expired stock is all that is left', function () {
    ($this->receive)('10', '2026-07-15');

    app(ProposeExpiredStockWriteOffs::class)->handle();

    app(ApproveInventoryAdjustment::class)->handle(InventoryAdjustment::sole(), $this->user);

    expect((float) StockBatch::sum('current_quantity'))->toBe(0.0);
});

it('refuses an expired write-off for more than the batch still holds', function () {
    ($this->receive)('10', '2026-07-15');
    app(ProposeExpiredStockWriteOffs::class)->handle();
    $adjustment = InventoryAdjustment::sole();

    // Stands in for a batch that shrank while the proposal sat in the queue.
    $adjustment->forceFill(['quantity' => '14'])->save();

    expect(fn () => app(ApproveInventoryAdjustment::class)->handle($adjustment->fresh(), $this->user))
        ->toThrow(InsufficientStockException::class);

    expect((float) StockBatch::sum('current_quantity'))->toBe(10.0);
});

it('still posts an older proposal that names its batch only as its source', function () {
    // ⚠ Proposals raised before the fix carry the batch only in source_id.
    ($this->receive)('10', '2026-07-15');
    app(RecordStockIn::class)->handle(
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '5',
        unitCost: '300',
        performedBy: $this->user,
        expiryDate: '2026-12-31',
        occurredAt: '2026-07-01 09:00:00',
    );
    app(ProposeExpiredStockWriteOffs::class)->handle();
    $adjustment = InventoryAdjustment::sole();
    $adjustment->forceFill(['stock_batch_id' => null])->save();

    app(ApproveInventoryAdjustment::class)->handle($adjustment->fresh(), $this->user);

    $batches = StockBatch::orderBy('id')->get();
    expect((float) $batches[0]->current_quantity)->toBe(0.0)
        ->and((float) $batches[1]->current_quantity)->toBe(5.0);
});

// ⚠ Bug hunt 16 Oct 2026 (02 L4, 03 L2): a rejected proposal was raised again,
// and the approvers told again, every night.
it('does not propose again a write-off an approver rejected, until the batch changes', function () {
    ($this->receive)('10', '2026-07-15');
    app(ProposeExpiredStockWriteOffs::class)->handle();

    $reviewer = User::factory()->create();
    app(RejectApprovable::class)->handle(InventoryAdjustment::sole(), $reviewer, 'Re-labelled, still good');

    app(ProposeExpiredStockWriteOffs::class)->handle();
    app(ProposeExpiredStockWriteOffs::class)->handle();

    expect(InventoryAdjustment::count())->toBe(1);

    // Once the batch holds a different quantity, it is a new question.
    StockBatch::query()->update(['current_quantity' => '7']);
    app(ProposeExpiredStockWriteOffs::class)->handle();

    expect(InventoryAdjustment::count())->toBe(2);
});
