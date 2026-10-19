<?php

use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Corrections\RecordHistory;
use App\Modules\Inventory\Actions\ProposeExpiredStockWriteOffs;
use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\ReceiptWorld;

/**
 * ⭐ Goods receipt Fix batch details (corrections wave 1, 19 Oct 2026;
 * FINAL-PLAN example 2): honey typed expiry 03/2026, the jar says 03/2028.
 * The batch is relabelled, the waiting write-off proposal closes, nothing
 * else moves, and the History says who, when and why.
 */
beforeEach(function () {
    $this->r = ReceiptWorld::make('2026-10-19 10:00:00');
    $this->post = fn ($user, $receipt, array $body) => $this->actingAs($user)
        ->withSession(['current_business_id' => $this->r->w->business->id])
        ->post(route('purchasing.receipts.fix-batch-details', $receipt), $body);
});

afterEach(fn () => Carbon::setTestNow());

it('fixes a wrong expiry, closes the expired write-off proposal, and moves nothing else', function () {
    $r = $this->r;
    $receipt = $r->receiptOf($r->received([[$r->d->salt, '25', '20.00']], '2026-10-01'));
    $line = $receipt->lines->first();
    $batch = StockBatch::find($line->stockMovement->batch_id);
    $batch->forceFill(['expiry_date' => '2026-10-10'])->save(); // the typo: already "expired"

    app(ProposeExpiredStockWriteOffs::class)->handle();
    $proposal = InventoryAdjustment::query()->sole();
    expect($proposal->approvalRequest->status->value)->toBe('pending');

    $movements = StockMovement::count();
    $journals = Journal::count();

    ($this->post)($r->staff, $receipt, [
        'reason_text' => 'Typo, the label says 2028',
        'supplier_challan_number' => 'CH-77',
        'lines' => [['id' => $line->id, 'batch_number' => 'HONEY-01', 'manufactured_date' => '2026-09-01', 'expiry_date' => '2028-03-31']],
    ])->assertSessionHasNoErrors()->assertSessionHas('success');

    $batch->refresh();
    expect($batch->expiry_date->toDateString())->toBe('2028-03-31')
        ->and($batch->batch_number)->toBe('HONEY-01')
        ->and($receipt->fresh()->supplier_challan_number)->toBe('CH-77')
        ->and($receipt->fresh()->details_fixed_at)->not->toBeNull()
        ->and($proposal->fresh()->approvalRequest->status->value)->toBe('rejected')
        ->and($proposal->fresh()->approvalRequest->review_note)->toContain('expiry was corrected')
        ->and(StockMovement::count())->toBe($movements)
        ->and(Journal::count())->toBe($journals);

    $history = RecordHistory::for($receipt->fresh(), $r->w->owner);
    expect(collect($history['entries'])->pluck('kind')->all())->toContain('fix_batch_details')
        ->and(Activity::query()->where('event', 'fix_batch_details')->sole()->properties['reason'])->toBe('Typo, the label says 2028');
});

it('requires a reason, refuses impossible dates, and refuses a challan used on another receipt', function () {
    $r = $this->r;
    $receipt = $r->receiptOf($r->received([[$r->d->salt, '5', '20.00']], '2026-10-01'));
    $other = $r->receiptOf($r->received([[$r->d->sugar, '5', '20.00']], '2026-10-02'));
    $other->forceFill(['supplier_challan_number' => 'CH-1'])->save();
    $line = $receipt->lines->first();

    ($this->post)($r->staff, $receipt, ['reason_text' => 'ok', 'lines' => []])->assertSessionHasErrors('reason_text');

    ($this->post)($r->staff, $receipt, [
        'reason_text' => 'Label typed wrong',
        'lines' => [['id' => $line->id, 'batch_number' => 'X', 'manufactured_date' => '2026-09-01', 'expiry_date' => '2026-08-01']],
    ])->assertSessionHasErrors('lines.0.expiry_date');

    ($this->post)($r->staff, $receipt, ['reason_text' => 'Challan typed wrong', 'supplier_challan_number' => 'ch-1 '])
        ->assertSessionHasErrors('supplier_challan_number');
});

it('is refused on a reversed receipt and for a user who may not receive', function () {
    $r = $this->r;
    $receipt = $r->receiptOf($r->received([[$r->d->salt, '5', '20.00']], '2026-10-01'));

    ($this->post)($r->w->financeB, $receipt, ['reason_text' => 'Label typed wrong', 'note' => 'x'])->assertForbidden();

    $r->reverse($receipt, $r->w->manager);

    ($this->post)($r->staff, $receipt->fresh(), ['reason_text' => 'Label typed wrong', 'note' => 'x'])->assertSessionHasErrors('receipt');
});

it('works in a closed month: no money moves', function () {
    $r = $this->r;
    $receipt = $r->receiptOf($r->received([[$r->d->salt, '5', '20.00']], '2026-09-10'));
    $r->w->closeThrough('2026-09-30');

    ($this->post)($r->staff, $receipt, ['reason_text' => 'Challan number missing', 'supplier_challan_number' => 'CH-9'])
        ->assertSessionHasNoErrors();

    expect($receipt->fresh()->supplier_challan_number)->toBe('CH-9');
});
