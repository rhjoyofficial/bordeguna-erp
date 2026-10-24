<?php

use App\Modules\Accounting\database\seeders\VoucherWorkflowSeeder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Models\CommissionAgreement;
use App\Modules\Sales\Models\CommissionSettlement;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Support\CommissionTracker;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\SalesWorld;

/**
 * ⭐ Commission agreements, the screens (Joy's Q18, 25 Oct 2026): Sales >
 * Sales setup > Commission agreements (it replaces Targets) and the
 * commission report. Every write is a popup posting here; the rules are the
 * actions' (CommissionAgreementTest).
 */
beforeEach(function () {
    // The new pages are not in the built Vite manifest of this worktree.
    $this->withoutVite();
    $this->s = SalesWorld::make();
    $this->w = $this->s->w;
    (new VoucherWorkflowSeeder)->run();
    $this->w->enter($this->w->business);
    $this->agent = Supplier::create(['business_id' => $this->w->business->id, 'name' => 'Belal Agent', 'is_active' => true]);

    $this->as = fn ($user) => $this->actingAs($user)->withHeader('X-Business-Id', (string) $this->w->business->id);
    $this->deal = [
        'agreed_on' => '2026-10-01', 'party_type' => 'supplier', 'party_id' => $this->agent->id,
        'valid_from' => '2026-10-01', 'valid_to' => null, 'deal_type' => 'per_unit', 'rate' => '20',
        'counts_credited_sales' => true, 'counts_own_purchases' => false,
        'product_ids' => [$this->s->honey->id], 'category_ids' => [], 'tiers' => [],
    ];
});

it('makes an agreement from the popup, lists it with its progress, and opens its page', function () {
    ($this->as)($this->w->owner)->post(route('sales.commission.store'), $this->deal)->assertSessionHasNoErrors()->assertRedirect();

    $agreement = CommissionAgreement::query()->sole();
    expect($agreement->number)->toBe('CA-0001')->and($agreement->isActive())->toBeTrue();

    ($this->as)($this->w->owner)->get(route('sales.commission.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('sales/commission/index')
            ->has('agreements', 1)
            ->where('agreements.0.party_name', 'Belal Agent')
            ->where('agreements.0.progress.earned', '0.00')
            ->has('options.suppliers')
            ->has('legacyTargets', 0));

    ($this->as)($this->w->owner)->get(route('sales.commission.show', $agreement))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('sales/commission/show')->where('agreement.number', 'CA-0001')->has('settlements', 0));
});

it('settles from the popup, shows the settlement with its Reverse, pays it, and reports it by person', function () {
    ($this->as)($this->w->owner)->post(route('sales.commission.store'), $this->deal)->assertSessionHasNoErrors();
    $agreement = CommissionAgreement::query()->sole();

    $order = $this->s->order('5', date: '2026-10-05');
    $order->forceFill(['agent_type' => 'supplier', 'agent_id' => $this->agent->id])->save();
    $this->s->invoice($this->s->challan($order, '5', '2026-10-05'), '850', '2026-10-05');

    ($this->as)($this->w->financeA)->post(route('sales.commission.settle', $agreement), [
        'settled_on' => '2026-10-15', 'through_date' => '2026-10-15', 'method' => 'payable',
    ])->assertSessionHasNoErrors();

    $settlement = CommissionSettlement::query()->sole();

    ($this->as)($this->w->financeA)->get(route('sales.commission.show', $agreement))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('settlements.0.number', 'CS-0001')
            ->where('settlements.0.amount', '100.00')
            ->where('settlements.0.owed', '100.00')
            ->where('settlements.0.corrections.type', 'sales.commission_settlement')
            ->where('settlements.0.corrections.actions.0.action', 'reverse'));

    ($this->as)($this->w->financeA)->post(route('sales.commission.pay', $settlement), [
        'paid_on' => '2026-10-16', 'amount' => '100', 'money_account_id' => $this->w->cash->id,
    ])->assertSessionHasNoErrors();

    // A double click finds nothing owed and pays nothing twice.
    ($this->as)($this->w->financeA)->post(route('sales.commission.pay', $settlement), [
        'paid_on' => '2026-10-16', 'amount' => '100', 'money_account_id' => $this->w->cash->id,
    ])->assertSessionHasErrors('amount');
    expect(DB::table('commission_payments')->count())->toBe(1);

    ($this->as)($this->w->financeA)->get(route('sales.commission.report', ['from' => '2026-10-01', 'to' => '2026-10-31']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('sales/commission/report')
            ->where('rows.0.name', 'Belal Agent')
            ->where('rows.0.earned', '100.00')
            ->where('rows.0.settled', '100.00')
            ->where('rows.0.paid', '100.00')
            ->where('rows.0.owed', '0.00')
            ->where('rows.0.to_settle', '0.00'));
});

it('refuses a double settle and a settle on a day already settled', function () {
    ($this->as)($this->w->owner)->post(route('sales.commission.store'), $this->deal);
    $agreement = CommissionAgreement::query()->sole();
    $order = $this->s->order('5', date: '2026-10-05');
    $order->forceFill(['agent_type' => 'supplier', 'agent_id' => $this->agent->id])->save();
    $this->s->invoice($this->s->challan($order, '5', '2026-10-05'), '850', '2026-10-05');

    $settle = fn () => ($this->as)($this->w->owner)->post(route('sales.commission.settle', $agreement), [
        'settled_on' => '2026-10-15', 'through_date' => '2026-10-15', 'method' => 'payable',
    ]);

    $settle()->assertSessionHasNoErrors();
    $settle()->assertSessionHasErrors('through_date');
    expect(CommissionSettlement::query()->count())->toBe(1);
});

it('keeps the screens and the actions off limits without the commission permission', function () {
    ($this->as)($this->w->sales)->get(route('sales.commission.index'))->assertForbidden();
    ($this->as)($this->w->sales)->get(route('sales.commission.report'))->assertForbidden();
    ($this->as)($this->w->sales)->post(route('sales.commission.store'), $this->deal)->assertForbidden();
    expect(CommissionAgreement::query()->count())->toBe(0);
});

it('shows the old target rows read only on the agreements list', function () {
    DB::table('sales_targets')->insert([
        'business_id' => $this->w->business->id, 'user_id' => $this->w->sales->id, 'month' => '2026-09-01',
        'target_amount' => 100000, 'commission_percent' => 2, 'created_by' => $this->w->owner->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    ($this->as)($this->w->owner)->get(route('sales.commission.index'))
        ->assertInertia(fn (Assert $page) => $page->has('legacyTargets', 1)->where('legacyTargets.0.month', '2026-09')->where('legacyTargets.0.user', 'Sales'));
});

it('shares the commission flag the menu tab reads', function () {
    $props = ($this->as)($this->w->owner)->get(route('sales.commission.index'))->original->getData()['page']['props'];
    expect($props['canManageCommission'])->toBeTrue();
});

it('credits an order to the agent from the Sold by picker, and the invoice made from it counts', function () {
    ($this->as)($this->w->owner)->post(route('sales.commission.store'), $this->deal)->assertSessionHasNoErrors();

    ($this->as)($this->w->owner)->get(route('sales.orders.index', ['new' => 1]))
        ->assertInertia(fn (Assert $page) => $page->where('commission_agents.0.value', "supplier:{$this->agent->id}"));

    ($this->as)($this->w->owner)->post(route('sales.orders.store'), [
        'customer_id' => $this->s->customer->id, 'warehouse_id' => $this->s->warehouse->id, 'ordered_at' => '2026-10-05',
        'lines' => [['product_id' => $this->s->honey->id, 'quantity' => '4', 'unit_price' => '850']],
        'agent' => "supplier:{$this->agent->id}",
    ])->assertSessionHasNoErrors();

    $order = SalesOrder::query()->latest('id')->firstOrFail();
    expect($order->agent_type)->toBe('supplier')->and($order->agent_id)->toBe($this->agent->id);

    $this->s->invoice($this->s->challan($order, '4', '2026-10-06'), '850', '2026-10-06');
    $progress = app(CommissionTracker::class)->progress(CommissionAgreement::query()->sole()->load(['items', 'tiers']));
    expect($progress['earned'])->toBe('80.00');
});

it('refuses a Sold by person who has no agreement', function () {
    ($this->as)($this->w->owner)->post(route('sales.orders.store'), [
        'customer_id' => $this->s->customer->id, 'warehouse_id' => $this->s->warehouse->id, 'ordered_at' => '2026-10-05',
        'lines' => [['product_id' => $this->s->honey->id, 'quantity' => '4', 'unit_price' => '850']],
        'agent' => "supplier:{$this->agent->id}",
    ])->assertSessionHasErrors('agent');

    expect(SalesOrder::query()->count())->toBe(0);
});

it('credits a direct invoice of a challan to the agent from the invoice form', function () {
    ($this->as)($this->w->owner)->post(route('sales.commission.store'), $this->deal)->assertSessionHasNoErrors();
    ProductPrice::create(['business_id' => $this->w->business->id, 'product_id' => $this->s->honey->id, 'unit_price' => '850', 'effective_from' => '2026-01-01']);
    $challan = $this->s->challan(null, '3', '2026-10-06');

    ($this->as)($this->w->owner)->get(route('sales.invoices.create', ['customer_id' => $this->s->customer->id]))
        ->assertInertia(fn (Assert $page) => $page->has('commission_agents', 1));

    ($this->as)($this->w->owner)->post(route('sales.invoices.store'), [
        'customer_id' => $this->s->customer->id, 'invoice_date' => '2026-10-06',
        'lines' => [['delivery_line_id' => $challan->lines()->first()->id, 'quantity' => '3', 'unit_price' => '850']],
        'agent' => "supplier:{$this->agent->id}",
    ])->assertSessionHasNoErrors();

    $invoice = SalesInvoice::query()->latest('id')->firstOrFail();
    expect($invoice->agent_type)->toBe('supplier')->and($invoice->agent_id)->toBe($this->agent->id);
});

it('credits a counter bill to the agent from the counter screen, in the same transaction as the sale', function () {
    ($this->as)($this->w->owner)->post(route('sales.commission.store'), $this->deal)->assertSessionHasNoErrors();
    ProductPrice::create(['business_id' => $this->w->business->id, 'product_id' => $this->s->honey->id, 'unit_price' => '850', 'effective_from' => '2026-01-01']);

    ($this->as)($this->w->owner)->get(route('sales.counter.create'))
        ->assertInertia(fn (Assert $page) => $page->has('commission_agents', 1));

    ($this->as)($this->w->owner)->post(route('sales.counter.store'), [
        'customer_id' => $this->s->customer->id,
        'warehouse_id' => $this->s->warehouse->id,
        'lines' => [['product_id' => $this->s->honey->id, 'quantity' => '2']],
        'agent' => "supplier:{$this->agent->id}",
    ])->assertSessionHasNoErrors();

    $invoice = SalesInvoice::query()->latest('id')->firstOrFail();
    expect($invoice->agent_type)->toBe('supplier')->and($invoice->agent_id)->toBe($this->agent->id);
});
