<?php

use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\DocumentStage;
use App\Modules\Foundation\Corrections\RecordHistory;
use App\Modules\Purchasing\Corrections\PurchaseOrderActionPolicy;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\ReceiptWorld;

/**
 * ⭐ Purchase order actions (corrections wave 1, 19 Oct 2026; FINAL-PLAN 2
 * and 3.3): Withdraw while waiting, Edit while approved and nothing received
 * (approval runs again when the total goes up or the supplier or items
 * change), Cancel with a reason, Copy, and Fix details after receipt.
 */
beforeEach(function () {
    $this->r = ReceiptWorld::make('2026-10-19 10:00:00');
    $this->policy = app(PurchaseOrderActionPolicy::class);
    $this->as = fn ($user) => $this->actingAs($user)->withSession(['current_business_id' => $this->r->w->business->id]);
    $this->payload = fn (PurchaseOrder $order, array $over = []) => array_merge([
        'supplier_id' => $order->supplier_id,
        'warehouse_id' => $order->warehouse_id,
        'order_date' => $order->orderDateString(),
        'lines' => $order->lines->map(fn ($l) => ['product_id' => $l->product_id, 'quantity' => (string) (float) $l->quantity_ordered, 'unit_cost' => (string) (float) $l->unit_cost])->all(),
    ], $over);
});

afterEach(fn () => Carbon::setTestNow());

it('withdraws a waiting order to a draft, only by its sender, and Edit sends it again', function () {
    $r = $this->r;
    $order = $r->approved([[$r->d->salt, '10', '20.00']], $r->buyer);
    expect($order->approvalRequest->status->value)->toBe('pending')
        ->and($this->policy->stageOf($order))->toBe(DocumentStage::Waiting);

    // Not the sender: refused with a sentence.
    $other = $r->w->member('Staff', 'Other buyer');
    ($this->as)($other)->post(route('purchasing.purchase-orders.withdraw', $order), ['reason_text' => 'Wrong supplier'])
        ->assertSessionHas('error');

    // No reason: refused by the server.
    ($this->as)($r->buyer)->post(route('purchasing.purchase-orders.withdraw', $order), ['reason_text' => 'no'])
        ->assertSessionHasErrors('reason_text');

    ($this->as)($r->buyer)->post(route('purchasing.purchase-orders.withdraw', $order), ['reason_text' => 'Price is wrong, fixing it'])
        ->assertSessionHas('success');

    $order->refresh();
    expect($order->approvalRequest->status->value)->toBe('returned')
        ->and($this->policy->stageOf($order))->toBe(DocumentStage::Draft)
        ->and(Activity::query()->where('event', 'withdrawn')->count())->toBe(1);

    // A second withdraw (double click) is refused.
    ($this->as)($r->buyer)->post(route('purchasing.purchase-orders.withdraw', $order), ['reason_text' => 'Price is wrong, fixing it'])
        ->assertSessionHas('error');

    // Edit a draft: no reason needed, and it goes for approval again.
    ($this->as)($r->buyer)->put(route('purchasing.purchase-orders.update', $order), ($this->payload)($order->load('lines'), [
        'lines' => [['product_id' => $r->d->salt->id, 'quantity' => '10', 'unit_cost' => '18']],
    ]))->assertSessionHasNoErrors();

    expect($order->fresh()->approvalRequest->status->value)->toBe('pending');
});

it('edits an approved order as the Buyer: approval runs again when the total goes up, not when it goes down', function () {
    $r = $this->r;
    $order = $r->approved([[$r->d->salt, '10', '20.00']]);
    expect($this->policy->stageOf($order))->toBe(DocumentStage::ApprovedNoEffect);

    // A reason is required on an approved order.
    ($this->as)($r->buyer)->put(route('purchasing.purchase-orders.update', $order), ($this->payload)($order))
        ->assertSessionHasErrors('reason_text');

    // Down: stays approved, the approvers are told, the report lists it.
    ($this->as)($r->buyer)->put(route('purchasing.purchase-orders.update', $order), ($this->payload)($order, [
        'lines' => [['product_id' => $r->d->salt->id, 'quantity' => '10', 'unit_cost' => '15']],
        'reason_text' => 'Supplier gave a better rate',
    ]))->assertSessionHasNoErrors();

    expect($order->fresh()->approvalRequest->status->value)->toBe('approved')
        ->and(Activity::query()->where('event', 'value_lowered_after_approval')->count())->toBe(1);

    // Up: back to waiting for approval, with the reason on the request.
    ($this->as)($r->buyer)->put(route('purchasing.purchase-orders.update', $order), ($this->payload)($order->fresh('lines'), [
        'order_date' => '2026-10-10',
        'lines' => [['product_id' => $r->d->salt->id, 'quantity' => '12', 'unit_cost' => '15']],
        'reason_text' => 'Two more bags needed',
    ]))->assertSessionHasNoErrors();

    $order->refresh();
    expect($order->approvalRequest->status->value)->toBe('pending')
        ->and($order->approvalRequest->review_note)->toContain('Two more bags needed')
        ->and($order->orderDateString())->toBe('2026-10-10');
});

it('re-runs approval when the supplier or the items change, even at a lower total', function () {
    $r = $this->r;
    $order = $r->approved([[$r->d->salt, '10', '20.00']]);
    $other = Supplier::create(['business_id' => $r->w->business->id, 'name' => 'Khulna Salt', 'code' => 'SUP-002']);

    ($this->as)($r->buyer)->put(route('purchasing.purchase-orders.update', $order), ($this->payload)($order, [
        'supplier_id' => $other->id,
        'lines' => [['product_id' => $r->d->salt->id, 'quantity' => '10', 'unit_cost' => '19']],
        'reason_text' => 'Ordered from Khulna instead',
    ]))->assertSessionHasNoErrors();

    expect($order->fresh()->approvalRequest->status->value)->toBe('pending');

    approveIfPending($order->fresh()->approvalRequest, $r->w->owner);

    ($this->as)($r->buyer)->put(route('purchasing.purchase-orders.update', $order), ($this->payload)($order->fresh('lines'), [
        'lines' => [['product_id' => $r->d->sugar->id, 'quantity' => '1', 'unit_cost' => '1']],
        'reason_text' => 'Sugar, not salt',
    ]))->assertSessionHasNoErrors();

    expect($order->fresh()->approvalRequest->status->value)->toBe('pending');
});

it('does not edit an order once goods arrived; Fix details changes dates and the note, logged with a reason', function () {
    $r = $this->r;
    $order = $r->received([[$r->d->salt, '10', '20.00']], '2026-10-15');
    expect($this->policy->stageOf($order))->toBe(DocumentStage::Done)
        ->and(collect($this->policy->actionsFor($order, $r->buyer)->actions)->pluck('action')->all())->not->toContain(DocumentAction::Edit);

    ($this->as)($r->buyer)->post(route('purchasing.purchase-orders.fix-details', $order), [
        'order_date' => '2026-10-01', 'expected_date' => '2026-10-14', 'note' => 'Placed by phone', 'reason_text' => 'Order was placed on 1 Oct',
    ])->assertSessionHasNoErrors()->assertSessionHas('success');

    $order->refresh();
    expect($order->orderDateString())->toBe('2026-10-01')
        ->and($order->note)->toBe('Placed by phone')
        ->and($order->details_fixed_at)->not->toBeNull();

    $entry = collect(RecordHistory::for($order, $r->w->owner)['entries'])->firstWhere('kind', 'fix_details');
    expect($entry['reason'])->toBe('Order was placed on 1 Oct');

    // The Buyer cannot change the supplier; it is the approver's.
    $other = Supplier::create(['business_id' => $r->w->business->id, 'name' => 'Khulna Salt', 'code' => 'SUP-002']);
    ($this->as)($r->buyer)->post(route('purchasing.purchase-orders.fix-details', $order), [
        'order_date' => '2026-10-01', 'supplier_id' => $other->id, 'reason_text' => 'Typed the wrong supplier',
    ])->assertSessionHasErrors('fix_details');
});

it('changes the supplier after receipt while unbilled: the GRNI credit is posted again naming the right one', function () {
    $r = $this->r;
    $order = $r->received([[$r->d->salt, '10', '20.00']], '2026-10-15');
    $other = Supplier::create(['business_id' => $r->w->business->id, 'name' => 'Khulna Salt', 'code' => 'SUP-002']);
    $grni = AccountMapping::resolve('inventory.goods_received_not_invoiced', Carbon::parse('2026-10-15'))->id;

    ($this->as)($r->w->manager)->post(route('purchasing.purchase-orders.fix-details', $order), [
        'order_date' => $order->orderDateString(), 'supplier_id' => $other->id, 'reason_text' => 'Typed the wrong supplier',
    ])->assertSessionHasNoErrors();

    $byParty = fn (Supplier $s) => (int) JournalLine::query()->where('account_id', $grni)->where('party_type', $s->getMorphClass())->where('party_id', $s->id)->sum('amount_minor');

    expect($order->fresh()->supplier_id)->toBe($other->id)
        ->and($byParty($r->d->supplier))->toBe(0)
        ->and($byParty($other))->toBe(-20_000)
        ->and($r->breaks())->toBe([]);

    // Once billed, no more.
    $r->d->supplier = $other;
    $r->d->bill($order->fresh('lines'), null, 'INV-S');
    ($this->as)($r->w->manager)->post(route('purchasing.purchase-orders.fix-details', $order), [
        'order_date' => $order->orderDateString(), 'supplier_id' => Supplier::query()->where('code', 'SUP-001')->value('id'), 'reason_text' => 'Back to the first one',
    ])->assertSessionHasErrors('fix_details');
});

it('cancels with a reason only, and Copy opens a new order filled from the old one', function () {
    $r = $this->r;
    $order = $r->approved([[$r->d->salt, '10', '20.00']]);

    ($this->as)($r->w->manager)->post(route('purchasing.purchase-orders.cancel', $order), [])->assertSessionHasErrors('reason_text');
    ($this->as)($r->buyer)->post(route('purchasing.purchase-orders.cancel', $order), ['reason_text' => 'Not needed any more'])->assertForbidden();

    ($this->as)($r->w->manager)->post(route('purchasing.purchase-orders.cancel', $order), ['reason_text' => 'Not needed any more'])
        ->assertSessionHas('success');

    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Cancelled)
        ->and($this->policy->actionsFor($order->fresh(), $r->w->manager)->actions)->toBe([]);

    ($this->as)($r->buyer)->get(route('purchasing.purchase-orders.create', ['copy' => $order->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('purchasing/purchase-orders/form')
            ->where('copy.id', null)
            ->where('copy.copied_from', $order->po_number)
            ->where('copy.lines.0.product_id', $r->d->salt->id));
});

it('shows the stage buttons on the order page, greyed with a sentence when blocked', function () {
    $r = $this->r;
    $order = $r->received([[$r->d->salt, '10', '20.00']], '2026-10-15');

    ($this->as)($r->w->manager)->get(route('purchasing.purchase-orders.show', $order))
        ->assertInertia(fn (Assert $page) => $page
            ->where('correction.actions.stage', 'done')
            ->where('correction.actions.actions.0.action', 'fix_details')
            ->where('correction.actions.actions.1.action', 'cancel')
            ->where('correction.actions.actions.1.allowed', false)
            ->where('correction.actions.actions.1.blockers.0.code', 'stock_received'));
});
