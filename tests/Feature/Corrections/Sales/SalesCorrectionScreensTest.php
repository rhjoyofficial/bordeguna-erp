<?php

use App\Modules\Foundation\Corrections\CorrectionOutcome;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Sales\Corrections\SalesInvoiceActionPolicy;
use App\Modules\Sales\Models\SalesCreditNote;
use Illuminate\Support\Carbon;
use Tests\Support\SalesWorld;

/**
 * ⭐ Corrections wave 1: the sales correction screens render with what the
 * server allows, and a request above the limit executes when the second
 * person approves it from the inbox.
 */
beforeEach(function () {
    $this->s = SalesWorld::make();
    $this->invoice = $this->s->invoice($this->s->challan(null, '10'), '850');
    $this->receipt = $this->s->receive($this->invoice, '1000');
});

afterEach(fn () => Carbon::setTestNow());

it('the invoice page carries its correction buttons and the Correct page opens pre-filled', function () {
    $this->actingAs($this->s->w->financeB)
        ->get(route('sales.invoices.show', $this->invoice))
        ->assertInertia(fn ($page) => $page
            ->where('corrections.actions.0.action', 'correct')
            ->where('corrections.actions.1.action', 'reverse')
            ->where('corrections.counter_sale', false)
            ->where('corrections.price_credit.allowed', true)->where('corrections.extra_charge.allowed', true));

    $this->actingAs($this->s->w->financeB)
        ->get(route('sales.invoices.correct', $this->invoice))
        ->assertInertia(fn ($page) => $page->component('sales/invoices/correct')->where('invoice.lines.0.quantity', '10')->where('invoice.lines.0.unit_price', '850'));

    $this->actingAs($this->s->cashier)->get(route('sales.invoices.correct', $this->invoice))->assertForbidden();
});

it('the receipts list carries Reverse, Correct and Re-allocate, and the Correct page opens pre-filled', function () {
    $this->actingAs($this->s->w->financeB)
        ->get(route('sales.receipts.index'))
        ->assertInertia(fn ($page) => $page
            ->where('receipts.data.0.actions.0.action', 'correct')
            ->where('receipts.data.0.can_reallocate', true));

    $this->actingAs($this->s->w->financeB)
        ->get(route('sales.receipts.correct', $this->receipt))
        ->assertInertia(fn ($page) => $page->component('sales/receipts/correct')->where('receipt.amount', '1000.00'));
});

it('the order page and the challans list carry their buttons', function () {
    $order = $this->s->order('5');
    $this->s->challan($order, '2');

    $this->actingAs($this->s->w->manager)
        ->get(route('sales.orders.show', $order))
        ->assertInertia(fn ($page) => $page
            ->where('order_actions.0.action', 'edit')
            ->where('order_actions.1.action', 'close')
            ->where('challans.0.actions.0.action', 'reverse'));

    $this->actingAs($this->s->w->manager)
        ->get(route('sales.deliveries.index'))
        ->assertInertia(fn ($page) => $page->has('deliveries.data.0.actions'));
});

it('a credit note above the limit executes when the second person approves it', function () {
    $big = $this->s->invoice($this->s->challan(null, '30'), '850');
    $asked = $this->s->submit($this->s->input(SalesInvoiceActionPolicy::TYPE, $big, DocumentAction::Reverse), $this->s->w->financeB);
    expect($asked->outcome)->toBe(CorrectionOutcome::Requested);

    $this->actingAs($this->s->w->financeB)->post(route('corrections.requests.approve', $asked->request))->assertForbidden();
    $this->actingAs($this->s->w->financeC)->post(route('corrections.requests.approve', $asked->request))->assertSessionHas('success');

    expect($big->fresh()->isReversed())->toBeTrue()
        ->and(SalesCreditNote::query()->where('sales_invoice_id', $big->id)->count())->toBe(1)
        ->and($this->s->reconciliationProblems())->toBe([]);
});
