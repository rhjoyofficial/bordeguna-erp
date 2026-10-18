<?php

use App\Models\User;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\SupplierAdvance;
use App\Modules\Purchasing\Models\SupplierAdvanceApplication;
use App\Modules\Purchasing\Models\SupplierPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\Support\DiscountWorld;

/**
 * PR5 connected-360 (18 Oct 2026): every document page shows what it came
 * from, what came of it and the journals, both directions agreeing (PO <->
 * receipt <-> bill <-> payment), each link a real page.
 */
beforeEach(function () {
    $this->d = DiscountWorld::make();
    $this->order = $this->d->order([[$this->d->salt, '10', '50']]);
    $this->bill = $this->d->bill($this->order);
    $this->payment = $this->d->pay($this->bill, '500');
    $this->receipt = GoodsReceipt::query()->where('purchase_order_id', $this->order->id)->sole();
});

function relatedOf($test, string $url): array
{
    return $test->actingAs($test->d->w->owner)->get($url)->assertOk()->viewData('page')['props']['related'];
}

function typesIn(array $refs): array
{
    return collect($refs)->pluck('type')->unique()->sort()->values()->all();
}

it('shows the receipt, bill and payment under the purchase order, with their journals', function () {
    $related = relatedOf($this, "/purchasing/purchase-orders/{$this->order->id}");

    expect(typesIn($related['to']))->toBe(['goods_receipt', 'supplier_bill', 'supplier_payment'])
        ->and($related['journals'])->not->toBeEmpty()
        ->and(collect($related['journals'])->pluck('type')->unique()->all())->toBe(['journal']);

    // Each link opens a page of this app.
    collect([...$related['to'], ...$related['journals']])->pluck('url')->filter()->each(function (string $url) {
        expect(Route::getRoutes()->match(Request::create(strtok($url, '#'), 'GET'))->isFallback)->toBeFalse();
    });
});

it('walks back from the bill to the order and receipt, and forward to the payment', function () {
    $related = relatedOf($this, "/purchasing/bills/{$this->bill->id}");

    expect(typesIn($related['from']))->toBe(['goods_receipt', 'purchase_order'])
        ->and(typesIn($related['to']))->toBe(['supplier_payment'])
        ->and(collect($related['from'])->firstWhere('type', 'purchase_order')['url'])->toBe("/purchasing/purchase-orders/{$this->order->id}")
        ->and(collect($related['to'])->first()['url'])->toBe("/purchasing/payments/{$this->payment->id}")
        ->and($related['journals'])->not->toBeEmpty();
});

it('walks back from the payment to the bill it paid', function () {
    $related = relatedOf($this, "/purchasing/payments/{$this->payment->id}");

    expect(collect($related['from'])->pluck('number')->all())->toContain('INV-100')
        ->and($related['journals'])->not->toBeEmpty();
});

it('sends the panel to every document page', function (string $page) {
    expect(file_get_contents(resource_path("js/pages/{$page}.tsx")))->toContain('<RelatedDocumentsPanel');
})->with([
    'purchasing/purchase-orders/show', 'purchasing/bills/show', 'purchasing/payments/show', 'purchasing/advances/show',
    'purchasing/buy-and-receive/show', 'sales/orders/show', 'sales/invoices/show',
]);

it('leaves the journals out for a reader without accounting', function () {
    $reader = User::factory()->create();
    $reader->businesses()->attach($this->d->w->business->id);
    setPermissionsTeamId($this->d->w->business->id);
    $reader->givePermissionTo('purchasing.reports.view');

    $related = $this->actingAs($reader)->get("/purchasing/bills/{$this->bill->id}")->assertOk()->viewData('page')['props']['related'];

    expect($related['journals'])->toBe([])
        ->and(typesIn($related['to']))->toBe(['supplier_payment']);
    expect(SupplierPayment::count())->toBe(1);
});

it('links an advance to its order, the bill it was applied to and the application', function () {
    $by = ['business_id' => $this->d->w->business->id, 'supplier_id' => $this->d->supplier->id, 'created_by' => $this->d->w->owner->id];
    $advance = SupplierAdvance::create($by + [
        'number' => 'SADV-0009', 'kind' => 'advance', 'purchase_order_id' => $this->order->id,
        'money_account_id' => $this->d->w->bank->id, 'paid_on' => '2026-10-09', 'amount' => '100',
    ]);
    SupplierAdvanceApplication::create($by + [
        'number' => 'SAAP-0009', 'supplier_advance_id' => $advance->id, 'supplier_bill_id' => $this->bill->id,
        'applied_on' => '2026-10-13', 'amount' => '100',
    ]);

    $related = relatedOf($this, "/purchasing/advances/{$advance->id}");

    expect(typesIn($related['from']))->toBe(['purchase_order'])
        ->and(typesIn($related['to']))->toBe(['advance_application', 'supplier_bill']);

    // And the bill sees it from its side.
    expect(typesIn(relatedOf($this, "/purchasing/bills/{$this->bill->id}")['to']))->toContain('advance_application');
});
