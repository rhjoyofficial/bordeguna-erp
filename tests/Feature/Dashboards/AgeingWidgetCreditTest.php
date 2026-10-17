<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReturn;
use App\Support\Dashboards\DashboardKey;
use App\Support\Dashboards\DateRange;
use App\Support\Dashboards\WidgetContext;
use App\Support\Dashboards\Widgets\PayablesWidget;
use App\Support\Dashboards\Widgets\ReceivablesAgeingWidget;
use Illuminate\Support\Carbon;
use Spatie\Permission\PermissionRegistrar;

/**
 * F33 on the role dashboards (practice run 17 Oct 2026). The only customer
 * position was a dealer's 807.50 return credit, which the Receivables card
 * would have shown as a total of -807.50. Owed to us is the customers who owe
 * us; a customer in credit is "We owe customers", positive and neutral.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-17 10:00:00');

    $this->business = Business::create(['name' => 'Widget Co', 'slug' => 'widget-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);
    (new ChartOfAccountsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);

    $this->context = new WidgetContext($this->owner, $this->business->id, DateRange::fromQuery([]), DashboardKey::Owner);

    $this->dealer = Customer::create(['business_id' => $this->business->id, 'name' => 'Practice Dealer Store']);
    $warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);
    $delivery = Delivery::create([
        'business_id' => $this->business->id, 'customer_id' => $this->dealer->id, 'warehouse_id' => $warehouse->id,
        'delivery_number' => 'DN-1', 'delivered_at' => '2026-10-16 09:00:00', 'created_by' => $this->owner->id,
    ]);
    SalesReturn::create([
        'business_id' => $this->business->id, 'customer_id' => $this->dealer->id, 'delivery_id' => $delivery->id,
        'return_number' => 'SR-1', 'returned_at' => '2026-10-16', 'credit_total' => '807.50', 'created_by' => $this->owner->id,
    ]);
});

afterEach(fn () => Carbon::setTestNow());

it('shows nothing owed to us and the return credit as we owe customers, not -807.50', function () {
    $data = (new ReceivablesAgeingWidget)->data($this->context);
    $parts = collect($data['parts'])->keyBy('label');

    expect($data['total'])->toEqual(0)
        ->and($parts['We owe customers']['value'])->toEqual(807.5)
        ->and($parts['We owe customers']['tone'])->toBeNull()
        // Held on account, shown the right way up rather than as a minus.
        ->and($parts['On account']['value'])->toEqual(807.5)
        ->and(collect($data['parts'])->pluck('value')->filter(fn ($v) => $v < 0)->all())->toBe([]);
});

it('keeps owed to us as the customers who owe, beside the credit of another', function () {
    $owing = Customer::create(['business_id' => $this->business->id, 'name' => 'Rahman Traders']);
    SalesInvoice::create([
        'business_id' => $this->business->id, 'customer_id' => $owing->id, 'invoice_number' => 'INV-1',
        'invoice_date' => '2026-10-10', 'due_date' => '2026-10-20', 'subtotal' => '12500', 'total' => '12500', 'created_by' => $this->owner->id,
    ]);

    $data = (new ReceivablesAgeingWidget)->data($this->context);

    expect($data['total'])->toEqual(12500)
        ->and(collect($data['parts'])->keyBy('label')['We owe customers']['value'])->toEqual(807.5);
});

it('adds no credit part when nobody is in credit, on the payables card too', function () {
    $data = (new PayablesWidget)->data($this->context);

    expect($data['total'])->toEqual(0)
        ->and(collect($data['parts'])->pluck('label')->all())->not->toContain('Suppliers owe us');
});
