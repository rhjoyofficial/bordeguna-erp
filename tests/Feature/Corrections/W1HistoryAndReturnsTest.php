<?php

use App\Models\User;
use App\Modules\Foundation\Corrections\DocumentPolicyRegistry;
use App\Modules\Foundation\Support\HistoryOnlyTypes;
use App\Modules\Purchasing\Actions\RecordSupplierAdvance;
use App\Modules\Purchasing\Models\GoodsReceipt;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\SalesWorld;
use Tests\Support\SupplierMoneyWorld;

/**
 * ⭐ Corrections wave 1 gaps (24 Oct 2026): the History tab on the supplier
 * money pages (bill, payment, credit note, purchase return, advance) and on a
 * sales order; a reversed purchase return marked on its goods receipt.
 *
 * A supplier advance and a sales order offer no correction actions, so they
 * are served as history-only types (HistoryOnlyTypes), kept out of the
 * corrections registry the policy kit walks.
 */
beforeEach(function () {
    $this->m = SupplierMoneyWorld::make();
    $this->w = $this->m->w;
    $this->d = $this->m->d;
    $this->order = $this->d->order([[$this->d->salt, '50', '20.00']]);
    $this->bill = $this->d->bill($this->order, null, 'INV-H1');
    $this->payment = $this->d->pay($this->bill, '100');
    $this->return = $this->m->returnGoods($this->order, '5');
    $this->note = $this->m->creditNote($this->return, $this->bill, '50');
    $this->advance = app(RecordSupplierAdvance::class)->pay(
        supplier: $this->d->supplier, moneyAccount: $this->w->bank, paidOn: '2026-10-15', amount: '500', createdBy: $this->w->owner,
    );

    // Nobody's role: belongs to the business, may see nothing.
    $this->outsider = User::factory()->create(['name' => 'Outsider']);
    $this->outsider->businesses()->attach($this->w->business->id);
});

afterEach(fn () => Carbon::setTestNow());

it('serves the History tab of every supplier money document to whoever may read purchases, and to nobody else', function () {
    $records = [
        'purchasing.supplier_bill' => $this->bill->id,
        'purchasing.supplier_payment' => $this->payment->id,
        'purchasing.supplier_credit_note' => $this->note->id,
        'purchasing.purchase_return' => $this->return->id,
        'purchasing.supplier_advance' => $this->advance->id,
    ];

    foreach ($records as $type => $id) {
        $history = $this->actingAs($this->w->financeB)->getJson("/records/{$type}/{$id}/history")->assertOk()->json();

        expect($history)->toHaveKeys(['entries', 'recorded_since', 'gap_notice'], $type);

        $this->actingAs($this->outsider)->getJson("/records/{$type}/{$id}/history")->assertNotFound();
    }

    $this->actingAs($this->w->financeB)->getJson("/records/purchasing.nothing/{$this->bill->id}/history")->assertNotFound();
    $this->actingAs($this->w->financeB)->getJson('/records/purchasing.supplier_advance/999999/history')->assertNotFound();
});

it('logs a purchase return, so its History starts when it was recorded and shows its reversal', function () {
    expect(Activity::query()->where('subject_type', $this->return->getMorphClass())->where('subject_id', $this->return->id)->exists())->toBeTrue();

    $free = $this->m->returnGoods($this->order, '2');
    $this->m->reverse($free, $this->w->financeB);

    $history = $this->actingAs($this->w->financeB)->getJson("/records/purchasing.purchase_return/{$free->id}/history")->assertOk()->json();

    expect($history['gap_notice'])->toBeNull()
        ->and(collect($history['entries'])->pluck('kind')->all())->toContain('created', 'reversed');
});

it('keeps the history-only types out of the corrections registry the policy kit walks', function () {
    $registry = app(DocumentPolicyRegistry::class);

    expect($registry->has('purchasing.supplier_advance'))->toBeFalse()
        ->and($registry->has('sales.order'))->toBeFalse()
        ->and($registry->types())->not->toContain('purchasing.supplier_advance', 'sales.order')
        ->and(app(HistoryOnlyTypes::class)->has('purchasing.supplier_advance'))->toBeTrue()
        ->and(app(HistoryOnlyTypes::class)->has('sales.order'))->toBeTrue();
});

it('serves a sales order\'s History to whoever may open the order: a sales reader or the person who took it', function () {
    $s = SalesWorld::make($this->w);
    // Takes orders, does not read sales: sees only the orders they took.
    $taker = User::factory()->create(['name' => 'Taker']);
    $taker->businesses()->attach($this->w->business->id);
    setPermissionsTeamId($this->w->business->id);
    $taker->givePermissionTo('sales.orders.record');

    $mine = $s->order('2', '850', $taker);
    $theirs = $s->order('3', '850', $this->w->owner);

    expect($taker->can('sales.reports.view'))->toBeFalse();

    $this->actingAs($this->w->owner)->getJson("/records/sales.order/{$theirs->id}/history")->assertOk();
    $this->actingAs($taker)->getJson("/records/sales.order/{$mine->id}/history")->assertOk();
    $this->actingAs($taker)->getJson("/records/sales.order/{$theirs->id}/history")->assertNotFound();
    $this->actingAs($this->outsider)->getJson("/records/sales.order/{$theirs->id}/history")->assertNotFound();
});

it('marks a reversed purchase return on the goods receipt it came from', function () {
    $free = $this->m->returnGoods($this->order, '2');
    $this->m->reverse($free, $this->w->financeB);
    $receipt = GoodsReceipt::query()->where('purchase_order_id', $this->order->id)->firstOrFail();

    $returns = collect($this->actingAs($this->w->owner)->get("/purchasing/receipts/{$receipt->id}")->assertOk()
        ->original->getData()['page']['props']['returns'])->keyBy('id');

    expect($returns[$free->id]['reversed'])->toBeTrue()
        ->and($returns[$free->id]['reversed_on'])->toBe($free->fresh()->reversed_on->toDateString())
        ->and($returns[$this->return->id]['reversed'])->toBeFalse()
        ->and($returns[$this->return->id]['reversed_on'])->toBeNull();
});

it('renders the History panel on the six pages', function () {
    $pages = [
        'resources/js/pages/purchasing/bills/show.tsx' => 'type="purchasing.supplier_bill"',
        'resources/js/pages/purchasing/payments/show.tsx' => 'type="purchasing.supplier_payment"',
        'resources/js/pages/purchasing/credit-notes/show.tsx' => 'type="purchasing.supplier_credit_note"',
        'resources/js/pages/purchasing/returns/show.tsx' => 'type="purchasing.purchase_return"',
        'resources/js/pages/purchasing/advances/show.tsx' => 'type="purchasing.supplier_advance"',
        'resources/js/pages/sales/orders/show.tsx' => 'type="sales.order"',
    ];

    foreach ($pages as $file => $needle) {
        expect(file_get_contents(base_path($file)))->toContain('<RecordHistory')->toContain($needle);
    }
});
