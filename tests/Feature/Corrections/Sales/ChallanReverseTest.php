<?php

use App\Modules\Foundation\Corrections\CorrectionOutcome;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\DocumentPolicyRegistry;
use App\Modules\Foundation\Corrections\Exceptions\CorrectionBlockedException;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Sales\Corrections\DeliveryActionPolicy;
use App\Modules\Sales\Corrections\SalesInvoiceActionPolicy;
use App\Modules\Sales\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\Consignment;
use App\Modules\Sales\Models\DeliveryLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\SalesWorld;

/**
 * ⭐ Corrections wave 1: a challan, Reverse (FINAL-PLAN 3.2 "Challan"): not
 * invoiced, no live parcel; the quantity goes back on the order, the stock
 * back into the same batches at the same cost, the GDNI journal mirrored on
 * the original date.
 */
beforeEach(function () {
    $this->s = SalesWorld::make();
    $this->order = $this->s->order('10');
    $this->challan = $this->s->challan($this->order, '10');
    $this->policy = app(DocumentPolicyRegistry::class)->for(DeliveryActionPolicy::TYPE);
});

afterEach(fn () => Carbon::setTestNow());

it('reverses a challan: goods back on the same batch at the same cost, GDNI mirrored on its date, the order open again', function () {
    expect($this->s->onHand())->toBe(90.0)
        ->and($this->order->fresh()->status)->toBe(SalesOrderStatus::Delivered);

    $result = $this->s->submit($this->s->input(DeliveryActionPolicy::TYPE, $this->challan, DocumentAction::Reverse), $this->s->w->manager);

    expect($result->outcome)->toBe(CorrectionOutcome::Executed)
        ->and($result->reversalJournal->journal_date->toDateString())->toBe('2026-10-12')
        ->and($this->s->onHand())->toBe(100.0)
        ->and(StockBatch::query()->count())->toBe(1)
        ->and(StockMovement::query()->whereNotNull('reverses_movement_id')->value('unit_cost'))->toEqual('500.0000')
        ->and($this->s->balance('goods_delivered_not_invoiced'))->toBe(0)
        ->and($this->order->fresh()->status)->toBe(SalesOrderStatus::Open)
        ->and((float) $this->order->lines()->first()->quantity_delivered)->toBe(0.0)
        ->and($this->challan->fresh()->isReversed())->toBeTrue()
        ->and($this->challan->fresh()->reversed_quantities[0]['quantity'])->toBe('10.0000')
        ->and($this->s->reconciliationProblems())->toBe([]);

    // Nothing on it can be billed or returned any more; the order can be delivered again.
    expect(DeliveryLine::query()->where('delivery_id', $this->challan->id)->first()->quantityUninvoiced())->toBe('0.0000');
    $again = $this->s->challan($this->order->fresh(), '10', '2026-10-15');
    expect($again->exists)->toBeTrue();
});

it('refuses while the challan is invoiced, and allows it once the invoice is credited', function () {
    $invoice = $this->s->invoice($this->challan);

    $codes = collect($this->policy->availability($this->challan->fresh(), $this->s->w->manager, DocumentAction::Reverse)->blockers)->pluck('code');
    expect($codes)->toContain('invoiced');

    $this->s->submit($this->s->input(SalesInvoiceActionPolicy::TYPE, $invoice, DocumentAction::Reverse), $this->s->w->financeB);

    expect($this->policy->availability($this->challan->fresh(), $this->s->w->manager, DocumentAction::Reverse)->allowed)->toBeTrue();
    $this->s->submit($this->s->input(DeliveryActionPolicy::TYPE, $this->challan->fresh(), DocumentAction::Reverse), $this->s->w->manager);

    expect($this->s->onHand())->toBe(100.0)
        ->and($this->s->balance('goods_delivered_not_invoiced'))->toBe(0)
        ->and($this->s->balance('cost_of_goods_sold'))->toBe(0)
        ->and($this->s->balance('accounts_receivable'))->toBe(0)
        ->and($this->s->reconciliationProblems())->toBe([]);
});

it('refuses while a courier holds the parcel', function () {
    Consignment::create([
        'business_id' => $this->s->w->business->id, 'delivery_id' => $this->challan->id, 'money_account_id' => $this->s->w->cash->id,
        'consignment_number' => 'PTH-1', 'cod_amount' => '8500', 'status' => 'booked', 'created_by' => $this->s->w->owner->id,
    ]);

    $codes = collect($this->policy->availability($this->challan->fresh(), $this->s->w->manager, DocumentAction::Reverse)->blockers)->pluck('code');
    expect($codes)->toContain('parcel_live');
});

it('refuses when a batch it took from changed its cost since', function () {
    DB::table('stock_batches')->update(['unit_cost' => '520']);

    $codes = collect($this->policy->availability($this->challan->fresh(), $this->s->w->manager, DocumentAction::Reverse)->blockers)->pluck('code');
    expect($codes)->toContain('batch_cost_changed');
});

it('is Owner and Manager only, and Finance and a cashier get 403', function () {
    expect($this->policy->actionsFor($this->challan, $this->s->w->financeB)->actions)->toBe([]);

    foreach ([$this->s->w->financeB, $this->s->cashier] as $user) {
        $this->actingAs($user)
            ->postJson('/records/'.DeliveryActionPolicy::TYPE."/{$this->challan->id}/reverse/preview", ['reason_text' => 'Wrong goods sent', 'idempotency_key' => (string) Str::uuid()])
            ->assertForbidden();
    }
});

it('a closed month: only the Owner reverses it, on its own date, the month stays closed', function () {
    $this->s->w->closeThrough('2026-10-12');

    expect($this->policy->availability($this->challan, $this->s->w->manager, DocumentAction::Reverse)->isRequestable())->toBeTrue();

    $result = $this->s->submit($this->s->input(DeliveryActionPolicy::TYPE, $this->challan, DocumentAction::Reverse), $this->s->w->owner);

    expect($result->outcome)->toBe(CorrectionOutcome::Executed)
        ->and($result->reversalJournal->period_exception_id)->not->toBeNull()
        ->and($this->s->onHand())->toBe(100.0)
        ->and((bool) AccountingPeriod::current()->is_unlocked)->toBeFalse();
});

it('cannot be reversed twice', function () {
    $this->s->submit($this->s->input(DeliveryActionPolicy::TYPE, $this->challan, DocumentAction::Reverse), $this->s->w->manager);

    expect(fn () => $this->s->submit($this->s->input(DeliveryActionPolicy::TYPE, $this->challan->fresh(), DocumentAction::Reverse), $this->s->w->owner))
        ->toThrow(CorrectionBlockedException::class);

    expect($this->s->onHand())->toBe(100.0);
});
