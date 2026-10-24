<?php

use App\Modules\Foundation\Corrections\CorrectionOutcome;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\DocumentPolicyRegistry;
use App\Modules\Inventory\Actions\RecordStockOut;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Actions\RecordSalesReturn;
use App\Modules\Sales\Corrections\SalesReturnActionPolicy;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\SalesWorld;

/**
 * ⭐ Integration of the initial plan branches (25 Oct 2026), where IP3 (credit
 * note numbers, Reverse a return), IP14 (return from an invoice, good goods to
 * another selling warehouse) and IP6 (damaged goods to quarantine) meet:
 *
 *  - one CN series: a return opened from an invoice keeps its SRN number and
 *    carries a CN credit note number, shown on the return and the invoice;
 *  - Reverse follows the goods the return moved on: taken back from the
 *    quarantine and the other warehouse, refused only once they were sold.
 */
beforeEach(function () {
    $this->withoutVite();
    $this->s = SalesWorld::make();
    $business = $this->s->w->business;
    $this->shop = Warehouse::create(['business_id' => $business->id, 'name' => 'Dhaka shop', 'code' => 'SHOP']);
    $this->quarantine = Warehouse::create(['business_id' => $business->id, 'name' => 'Damaged', 'code' => 'QRN', 'not_for_sale' => true]);

    $this->delivery = $this->s->challan(null, '10');
    $this->invoice = $this->s->invoice($this->delivery);

    $this->returnOf = fn (string $good, string $damaged, ?Warehouse $goodTo = null) => app(RecordSalesReturn::class)->handle(
        $this->delivery->fresh(),
        [['deliveryLine' => $this->delivery->lines()->first(), 'quantityToStock' => $good, 'quantityWrittenOff' => '0', 'quantityToQuarantine' => $damaged]],
        $this->s->w->owner,
        '2026-10-15',
        quarantine: $damaged === '0' ? null : $this->quarantine,
        goodWarehouse: $goodTo,
        fromInvoice: $this->invoice,
    );
    $this->in = fn (Warehouse $warehouse) => (float) StockBatch::query()->where('warehouse_id', $warehouse->id)->sum('current_quantity');
});

afterEach(fn () => Carbon::setTestNow());

it('numbers a return opened from an invoice in the CN series and shows it on the invoice', function () {
    $return = ($this->returnOf)('2', '0');

    expect($return->return_number)->toStartWith('SRN-')
        ->and($return->credit_note_number)->toStartWith('CN-');

    $this->actingAs($this->s->w->owner)->get(route('sales.invoices.show', $this->invoice))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('corrections.return_credit_notes.0.number', $return->credit_note_number)
            ->where('corrections.return_credit_notes.0.return_number', $return->return_number));

    $this->actingAs($this->s->w->owner)->get(route('sales.returns.show', $return))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('salesReturn.credit_note_number', $return->credit_note_number));
});

it('reverses a return whose goods went to another warehouse and to quarantine, taking them back from there', function () {
    $savar = ($this->in)($this->s->warehouse);
    $return = ($this->returnOf)('2', '1', $this->shop);

    expect(($this->in)($this->shop))->toBe(2.0)
        ->and(($this->in)($this->quarantine))->toBe(1.0)
        ->and(($this->in)($this->s->warehouse))->toBe($savar);

    $policy = app(DocumentPolicyRegistry::class)->for(SalesReturnActionPolicy::TYPE);
    expect($policy->availability($return->fresh(), $this->s->w->manager, DocumentAction::Reverse)->blockers)->toBe([]);

    $result = $this->s->submit($this->s->input(SalesReturnActionPolicy::TYPE, $return, DocumentAction::Reverse), $this->s->w->manager);

    expect($result->outcome)->toBe(CorrectionOutcome::Executed)
        ->and(($this->in)($this->shop))->toBe(0.0)
        ->and(($this->in)($this->quarantine))->toBe(0.0)
        ->and(($this->in)($this->s->warehouse))->toBe($savar)
        ->and($this->invoice->fresh()->outstanding()->toTaka())->toBe('8500.00')
        ->and($this->s->reconciliationProblems())->toBe([]);
});

it('refuses to reverse once the goods moved to the other warehouse were sold', function () {
    $return = ($this->returnOf)('2', '0', $this->shop);

    app(RecordStockOut::class)->handle($this->s->honey, $this->shop, '2', $this->s->w->owner, occurredAt: '2026-10-16 10:00:00');

    $policy = app(DocumentPolicyRegistry::class)->for(SalesReturnActionPolicy::TYPE);
    expect(collect($policy->availability($return->fresh(), $this->s->w->manager, DocumentAction::Reverse)->blockers)->pluck('code'))->toContain('stock_used');
});
