<?php

use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Sales\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Support\SalesOrderActions;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\SalesWorld;

/**
 * ⭐ Corrections wave 1: a sales order (FINAL-PLAN 3.2 "Sales order"): Edit
 * while nothing left (approval runs again when the value goes up or the
 * customer or items change), Cancel with a reason, Copy, and on a partly
 * delivered order the undelivered part only, or Close short.
 */
beforeEach(function () {
    $this->s = SalesWorld::make();
    $this->order = $this->s->order('10', '850');
});

afterEach(fn () => Carbon::setTestNow());

function orderForm(SalesOrder $order, array $changes = [], array $line = []): array
{
    $form = SalesOrderActions::form($order->fresh(), false);
    $form['lines'] = array_map(fn ($l) => array_replace(['id' => $l['id'], 'product_id' => $l['product_id'], 'quantity' => $l['quantity'], 'unit_price' => $l['unit_price'], 'description' => $l['description']], $line), $form['lines']);
    unset($form['id'], $form['order_number'], $form['mode']);

    return array_replace($form, ['address' => null], $changes);
}

it('edits the order date, quantity and price while nothing left; a value going up runs approval again', function () {
    expect($this->order->approvalRequest->status)->toBe(ApprovalStatus::Approved);

    $this->actingAs($this->s->cashier)
        ->put(route('sales.orders.update', $this->order), orderForm($this->order, ['ordered_at' => '2026-10-09'], ['quantity' => '12']))
        ->assertSessionHasNoErrors()->assertRedirect();

    $order = $this->order->fresh(['lines', 'approvalRequest']);
    expect($order->ordered_at->toDateString())->toBe('2026-10-09')
        ->and((float) $order->lines->first()->quantity_ordered)->toBe(12.0)
        ->and((float) $order->lines->first()->unit_price)->toBe(850.0)
        ->and($order->approvalRequest->status)->toBe(ApprovalStatus::Pending)
        ->and(Activity::query()->where('log_name', 'corrections')->where('event', 'edited')->count())->toBe(1);
});

it('a value going down keeps the approval and is logged for the Owner', function () {
    $this->actingAs($this->s->w->manager)
        ->put(route('sales.orders.update', $this->order), orderForm($this->order, [], ['quantity' => '8']))
        ->assertSessionHasNoErrors();

    expect($this->order->fresh()->approvalRequest->status)->toBe(ApprovalStatus::Approved)
        ->and(Activity::query()->where('log_name', 'corrections')->where('event', 'value_lowered_after_approval')->count())->toBe(1);
});

it('a price change needs the override permission', function () {
    $this->actingAs($this->s->cashier)
        ->put(route('sales.orders.update', $this->order), orderForm($this->order, [], ['unit_price' => '700']))
        ->assertSessionHasErrors('lines.0.unit_price');

    $this->actingAs($this->s->w->manager)
        ->put(route('sales.orders.update', $this->order), orderForm($this->order, [], ['unit_price' => '840']))
        ->assertSessionHasNoErrors();

    expect((float) $this->order->fresh()->lines->first()->unit_price)->toBe(840.0);
});

it('partly delivered: a Manager edits the undelivered part only; the cashier cannot', function () {
    $this->s->challan($this->order, '4');

    $this->actingAs($this->s->cashier)
        ->put(route('sales.orders.update', $this->order), orderForm($this->order, [], ['quantity' => '6']))
        ->assertForbidden();

    $this->actingAs($this->s->w->manager)
        ->put(route('sales.orders.update', $this->order), orderForm($this->order, [], ['quantity' => '3']))
        ->assertSessionHasErrors('lines.0.quantity');

    $this->actingAs($this->s->w->manager)
        ->put(route('sales.orders.update', $this->order), orderForm($this->order, [], ['unit_price' => '800']))
        ->assertSessionHasErrors('lines.0.unit_price');

    $this->actingAs($this->s->w->manager)
        ->put(route('sales.orders.update', $this->order), orderForm($this->order, [], ['quantity' => '6']))
        ->assertSessionHasNoErrors();

    $order = $this->order->fresh('lines');
    expect((float) $order->lines->first()->quantity_ordered)->toBe(6.0)
        ->and($order->status)->toBe(SalesOrderStatus::PartiallyDelivered);
});

it('cancel and close short need a reason of five letters', function () {
    $this->actingAs($this->s->w->manager)->post(route('sales.orders.cancel', $this->order), ['reason' => 'no'])->assertSessionHasErrors('reason');
    $this->actingAs($this->s->w->manager)->post(route('sales.orders.cancel', $this->order), ['reason' => 'Customer called to cancel'])->assertSessionHas('success');

    expect($this->order->fresh()->status)->toBe(SalesOrderStatus::Cancelled)
        ->and(Activity::query()->where('log_name', 'corrections')->where('event', 'cancelled')->count())->toBe(1);

    $actions = collect(SalesOrderActions::for($this->order->fresh(), $this->s->w->manager));
    expect($actions->firstWhere('action', 'edit')['allowed'])->toBeFalse()
        ->and($actions->firstWhere('action', 'copy')['allowed'])->toBeTrue();
});

it('Copy opens the same form on a new order with the same lines', function () {
    $this->actingAs($this->s->w->manager)
        ->get(route('sales.orders.index', ['copy' => $this->order->id]))
        ->assertInertia(fn ($page) => $page->where('copy_order.mode', 'copy')->where('copy_order.id', null)->where('copy_order.lines.0.product_id', $this->s->honey->id));
});

it('shows at most two primary buttons by stage', function () {
    $nothing = collect(SalesOrderActions::for($this->order, $this->s->w->manager));
    expect($nothing->where('primary', true)->pluck('action')->all())->toBe(['edit', 'cancel']);

    $this->s->challan($this->order, '4');
    $part = collect(SalesOrderActions::for($this->order->fresh(), $this->s->w->manager));
    expect($part->where('primary', true)->pluck('action')->all())->toBe(['edit', 'close']);
});
