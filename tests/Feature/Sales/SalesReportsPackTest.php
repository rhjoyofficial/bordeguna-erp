<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Support\CostOfGoodsSold;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Reporting\ReportParameters;
use App\Modules\Hrm\Reporting\ReportRegistry;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Brand;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Actions\CreateSalesOrder;
use App\Modules\Sales\Actions\RecordCounterSale;
use App\Modules\Sales\Actions\RecordDelivery;
use App\Modules\Sales\Actions\RecordSalesInvoice;
use App\Modules\Sales\Actions\RecordSalesReturn;
use App\Modules\Sales\Actions\ResolveSellingPrice;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Enums\SalesChannel;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Reporting\Definitions\SalesByChannelReport;
use App\Modules\Sales\Reporting\Definitions\SalesMarginReport;
use App\Modules\Sales\Support\DeliveryBilling;
use App\Modules\Sales\Support\OrderBrand;
use App\Modules\Sales\Support\SalesOrderOrigin;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reports pack (11 Oct 2026): sales by brand, channel and customer type, and
 * product and brand margin. The fixture goes through the real actions, so
 * every figure the reports print can be checked against the journal lines the
 * same documents posted:
 *
 *   A  LaLaDia, website, retail:  2 ghee at 850, order discount 50, delivery 100;
 *                                 1 ghee returned after billing
 *   B  Bionic, internal, dealer:  1 ghee at 850, delivery 60
 *   C  counter, walk-in:          3 honey at 200, received at NO cost
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-11 10:00:00');
    $this->withoutVite();

    $this->business = rpBusiness('Bor de Guna', 'bdg-rp');
    $this->owner = rpUser($this->business, 'Owner');

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $this->ghee = Product::create(['business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id, 'sku' => 'GHEE', 'name' => 'Ghee 400g', 'type' => ProductType::FinishedGood]);
    $this->honey = Product::create(['business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id, 'sku' => 'HONEY', 'name' => 'Honey 500g', 'type' => ProductType::FinishedGood]);
    ProductPrice::create(['business_id' => $this->business->id, 'product_id' => $this->ghee->id, 'unit_price' => '850', 'effective_from' => '2026-01-01']);
    ProductPrice::create(['business_id' => $this->business->id, 'product_id' => $this->honey->id, 'unit_price' => '200', 'effective_from' => '2026-01-01']);
    app(RecordStockIn::class)->handle($this->ghee, $this->warehouse, '50', '500', $this->owner, occurredAt: '2026-10-01 09:00:00');
    app(RecordStockIn::class)->handle($this->honey, $this->warehouse, '50', '0', $this->owner, occurredAt: '2026-10-01 09:00:00');

    $this->lld = Brand::create(['business_id' => $this->business->id, 'code' => 'LLD', 'name' => 'LaLaDia', 'slug' => 'laladia']);
    $this->bnc = Brand::create(['business_id' => $this->business->id, 'code' => 'BNC', 'name' => 'Bionic', 'slug' => 'bionic']);

    $this->retail = Customer::create(['business_id' => $this->business->id, 'name' => 'Web buyer', 'type' => CustomerType::Retail]);
    $this->dealer = Customer::create(['business_id' => $this->business->id, 'name' => 'Dealer', 'type' => CustomerType::Dealer]);

    // A: LaLaDia on the website.
    $orderA = rpOrder($this, $this->retail, $this->lld, '2', delivery: '100', discount: '50');
    DB::table('sales_orders')->where('id', $orderA->id)->update(['channel' => SalesChannel::Website->value]);
    $this->deliveryA = rpDeliverAndBill($this, $this->retail, $orderA->fresh('lines'), '2026-10-05');

    app(RecordSalesReturn::class)->handle(
        delivery: $this->deliveryA,
        lines: [['deliveryLine' => $this->deliveryA->lines->first(), 'quantityToStock' => '1', 'quantityWrittenOff' => '0']],
        createdBy: $this->owner,
        returnedAt: '2026-10-07',
    );

    // B: Bionic, typed in, to a dealer.
    $orderB = rpOrder($this, $this->dealer, $this->bnc, '1', delivery: '60');
    rpDeliverAndBill($this, $this->dealer, $orderB, '2026-10-06');

    // C: the counter, no order, goods at nil cost.
    $cash = MoneyAccount::create(['business_id' => $this->business->id, 'name' => 'Till', 'type' => 'cash', 'account_id' => Account::bySlug('cash_in_hand')->id]);
    app(RecordCounterSale::class)->handle(
        customer: Customer::walkIn(),
        warehouse: $this->warehouse,
        lines: [['product' => $this->honey, 'quantity' => '3']],
        createdBy: $this->owner,
        moneyAccount: $cash,
        soldAt: '2026-10-08 12:00:00',
    );

    $this->period = new ReportParameters(['invoiced_from' => '2026-10-01', 'invoiced_to' => '2026-10-31']);
});

afterEach(fn () => Carbon::setTestNow());

function rpBusiness(string $name, string $slug): Business
{
    $business = Business::create(['name' => $name, 'slug' => $slug]);
    app(BusinessContext::class)->set($business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($business->id);

    foreach (['Owner', 'Manager', 'Staff', 'Clerk'] as $role) {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web', 'business_id' => $business->id]);
    }

    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    (new SalesAccountMappingSeeder)->run();
    (new WalkInCustomerSeeder)->run();
    (new SalesPermissionsSeeder)->run();
    (new \App\Modules\Accounting\database\seeders\AccountingPermissionsSeeder)->run();
    app(BusinessContext::class)->set($business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($business->id);

    return $business;
}

function rpUser(Business $business, string $role): User
{
    $user = User::factory()->create();
    $user->businesses()->attach($business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($business->id);
    $user->assignRole($role);

    return $user;
}

function rpOrder(object $t, Customer $customer, ?Brand $brand, string $quantity, ?string $delivery = null, string $discount = '0'): SalesOrder
{
    return app(CreateSalesOrder::class)->handle(
        customer: $customer,
        lines: [['product' => $t->ghee, 'quantity' => $quantity]],
        createdBy: $t->owner,
        orderedAt: '2026-10-04',
        origin: new SalesOrderOrigin(channel: SalesChannel::Internal, deliveryCharge: $delivery, orderDiscount: $discount),
        brandId: $brand?->id,
    );
}

function rpDeliverAndBill(object $t, Customer $customer, SalesOrder $order, string $on): Delivery
{
    $line = $order->lines->first();
    $delivery = app(RecordDelivery::class)->handle(
        customer: $customer,
        warehouse: $t->warehouse,
        lines: [['product' => $t->ghee, 'quantity' => (string) $line->quantity_ordered, 'orderLine' => $line]],
        createdBy: $t->owner,
        deliveredAt: $on.' 09:00:00',
    );

    $delivery = $delivery->fresh(['lines.product', 'lines.orderLine', 'customer']);

    app(RecordSalesInvoice::class)->handle(
        customer: $customer,
        lines: DeliveryBilling::lines($delivery, DeliveryBilling::everythingUninvoiced($delivery), app(ResolveSellingPrice::class), Carbon::parse($on)),
        createdBy: $t->owner,
        invoiceDate: $on,
    );

    return $delivery->fresh(['lines']);
}

/** Signed paisa posted to a mapped account between two dates (credits negative). */
function rpMovement(int $businessId, string $key, string $from = '2026-10-01', string $to = '2026-10-31'): int
{
    return (int) DB::table('journal_lines')
        ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
        ->where('journal_lines.business_id', $businessId)
        ->where('journal_lines.account_id', AccountMapping::resolve($key, Carbon::parse($to))->id)
        ->whereDate('journals.journal_date', '>=', $from)
        ->whereDate('journals.journal_date', '<=', $to)
        ->sum('journal_lines.amount_minor');
}

function rpRows(string $class, array $parameters): Illuminate\Support\Collection
{
    return app($class)->rows(new ReportParameters($parameters))->collect();
}

// ── Sales by brand, channel and customer type ─────────────────────────────

it('ties net revenue to Sales, Returns and Discounts, and delivery charges to 4300', function () {
    $rows = rpRows(SalesByChannelReport::class, $this->period->all());
    $id = $this->business->id;

    $ledgerRevenue = -1 * (rpMovement($id, 'sales.revenue') + rpMovement($id, 'sales.sales_returns') + rpMovement($id, 'sales.discounts_allowed'));
    $ledgerDelivery = -1 * rpMovement($id, 'sales.delivery_income');

    expect((int) round($rows->sum('net_revenue') * 100))->toBe($ledgerRevenue)
        ->and($ledgerRevenue)->toBe(227500)
        ->and((int) round($rows->sum('delivery_charges') * 100))->toBe($ledgerDelivery)
        ->and($ledgerDelivery)->toBe(16000)
        ->and((int) round($rows->sum('gross') * 100))->toBe(-1 * rpMovement($id, 'sales.revenue'))
        ->and((int) round($rows->sum('returns') * 100))->toBe(rpMovement($id, 'sales.sales_returns') - 2500);
});

it('splits by the order brand, the channel and the customer type, with the counter as no brand', function () {
    $rows = rpRows(SalesByChannelReport::class, $this->period->all())->keyBy(fn ($r) => $r['brand'].'|'.$r['channel'].'|'.$r['customer_type']);

    $a = $rows['LaLaDia|Website|Retail customer'];
    $b = $rows['Bionic|Internal (typed orders)|Dealer'];
    $c = $rows['No brand|Counter (no order)|Walk-in'];

    expect($rows)->toHaveCount(3)
        // 1700 sold, 50 discount, one jar back: 850 less the 25 of discount it carried.
        ->and($a['gross'])->toBe(1700.0)
        ->and($a['discount'])->toBe(50.0)
        ->and($a['returns'])->toBe(825.0)
        ->and($a['net_revenue'])->toBe(825.0)
        ->and($a['quantity'])->toBe(1.0)
        ->and($a['delivery_charges'])->toBe(100.0)
        ->and($b['net_revenue'])->toBe(850.0)
        ->and($b['delivery_charges'])->toBe(60.0)
        ->and($c['net_revenue'])->toBe(600.0)
        ->and($c['delivery_charges'])->toBe(0.0);
});

it('never counts the invoice total, VAT, the delivery charge or goods not yet invoiced as revenue', function () {
    // A challan sent and NOT billed: GDNI, not revenue.
    $orderD = rpOrder($this, $this->retail, $this->lld, '4');
    $line = $orderD->lines->first();
    app(RecordDelivery::class)->handle(customer: $this->retail, warehouse: $this->warehouse, lines: [['product' => $this->ghee, 'quantity' => '4', 'orderLine' => $line]], createdBy: $this->owner, deliveredAt: '2026-10-09 09:00:00');

    $rows = rpRows(SalesByChannelReport::class, $this->period->all());
    $invoiceTotals = (float) SalesInvoice::query()->whereBetween('invoice_date', ['2026-10-01', '2026-10-31'])->sum('total');

    expect($rows->sum('net_revenue'))->toBe(2275.0)
        // The invoices carry the delivery charges and are before discount allocation
        // and returns: 1700 - 50 + 100 + 850 + 60 + 600.
        ->and($invoiceTotals)->toBe(3260.0)
        ->and($rows->sum('net_revenue'))->not->toBe($invoiceTotals);
});

it('filters by brand, by No brand and by channel, and a brand of another business narrows nothing', function () {
    $lld = rpRows(SalesByChannelReport::class, $this->period->all() + ['brand' => (string) $this->lld->id]);
    $none = rpRows(SalesByChannelReport::class, $this->period->all() + ['brand' => OrderBrand::NONE]);
    $counter = rpRows(SalesByChannelReport::class, $this->period->all() + ['channel' => 'counter']);

    $other = rpBusiness('Elsewhere', 'elsewhere-rp');
    $foreign = Brand::create(['business_id' => $other->id, 'code' => 'XX', 'name' => 'Foreign', 'slug' => 'foreign']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);
    $foreignFilter = rpRows(SalesByChannelReport::class, $this->period->all() + ['brand' => (string) $foreign->id]);

    expect($lld->pluck('brand')->unique()->all())->toBe(['LaLaDia'])
        ->and($lld->sum('net_revenue'))->toBe(825.0)
        ->and($none->sum('net_revenue'))->toBe(600.0)
        ->and($counter->sum('net_revenue'))->toBe(600.0)
        ->and($foreignFilter->sum('net_revenue'))->toBe(2275.0);
});

it('groups by one dimension when asked, the totals unchanged', function () {
    $byChannel = rpRows(SalesByChannelReport::class, $this->period->all() + ['group_by' => 'channel']);

    expect($byChannel)->toHaveCount(3)
        ->and($byChannel->pluck('brand')->unique()->all())->toBe(['All brands'])
        ->and($byChannel->sum('net_revenue'))->toBe(2275.0);
});

it('keeps another business out of every figure', function () {
    $other = rpBusiness('Other Co', 'other-rp');
    $otherOwner = rpUser($other, 'Owner');
    $warehouse = Warehouse::create(['business_id' => $other->id, 'name' => 'W', 'code' => 'W']);
    $unit = UnitOfMeasure::create(['business_id' => $other->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $product = Product::create(['business_id' => $other->id, 'unit_of_measure_id' => $unit->id, 'sku' => 'X', 'name' => 'X', 'type' => ProductType::FinishedGood]);
    ProductPrice::create(['business_id' => $other->id, 'product_id' => $product->id, 'unit_price' => '9999', 'effective_from' => '2026-01-01']);
    app(RecordStockIn::class)->handle($product, $warehouse, '5', '100', $otherOwner, occurredAt: '2026-10-01 09:00:00');
    app(RecordCounterSale::class)->handle(customer: Customer::walkIn(), warehouse: $warehouse, lines: [['product' => $product, 'quantity' => '1']], createdBy: $otherOwner, soldAt: '2026-10-08 12:00:00');

    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    expect(rpRows(SalesByChannelReport::class, $this->period->all())->sum('net_revenue'))->toBe(2275.0)
        ->and(rpRows(SalesMarginReport::class, $this->period->all())->sum('revenue'))->toBe(2275.0);
});

// ── Product and brand margin ──────────────────────────────────────────────

it('ties cost of goods sold to the ledger and to CostOfGoodsSold, and flags goods sold at no cost', function () {
    $rows = rpRows(SalesMarginReport::class, $this->period->all())->keyBy('sku');
    $cogs = rpMovement($this->business->id, 'sales.cost_of_goods_sold');
    $statement = CostOfGoodsSold::forPeriod(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));

    expect((int) round($rows->sum('cogs') * 100))->toBe($cogs)
        ->and($statement->totalCost()->minor)->toBe($cogs)
        // Two jars at 500 billed (A one back, B one): 1000 less 500 relieved + 500.
        ->and($rows['GHEE']['cogs'])->toBe(1000.0)
        ->and($rows['GHEE']['revenue'])->toBe(1675.0)
        ->and($rows['GHEE']['margin'])->toBe(675.0)
        ->and($rows['GHEE']['margin_pct'])->toBe(40.3)
        ->and($rows['GHEE']['cost_known'])->toBe('Yes')
        ->and($rows['GHEE']['quantity'])->toBe(2.0)
        ->and($rows['HONEY']['cogs'])->toBe(0.0)
        ->and($rows['HONEY']['cost_known'])->toBe('No cost recorded');
});

it('shows margin per order brand with the same totals', function () {
    $byBrand = rpRows(SalesMarginReport::class, $this->period->all() + ['group_by' => 'brand'])->keyBy('brand');

    expect($byBrand->keys()->sort()->values()->all())->toBe(['Bionic', 'LaLaDia', 'No brand'])
        ->and($byBrand['LaLaDia']['revenue'])->toBe(825.0)
        ->and($byBrand['LaLaDia']['cogs'])->toBe(500.0)
        ->and($byBrand['Bionic']['margin'])->toBe(350.0)
        ->and($byBrand->sum('cogs'))->toBe(1000.0);
});

// ── Registry, permissions, screen ─────────────────────────────────────────

it('registers both under Sales behind sales.reports.view, hidden and 404 for a role without it', function () {
    $clerk = rpUser($this->business, 'Clerk');
    $registry = app(ReportRegistry::class);

    foreach (['sales_by_channel', 'sales_margin'] as $key) {
        expect($registry->find($key)->group())->toBe('Sales')
            ->and($registry->find($key)->permission())->toBe('sales.reports.view')
            ->and($registry->availableTo($this->owner)->has($key))->toBeTrue()
            ->and($registry->availableTo($clerk)->has($key))->toBeFalse();

        $this->actingAs($clerk)->get(route('hrm.reports.preview', $key))->assertNotFound();
        $this->actingAs($clerk)->get(route('hrm.reports.run', $key).'?format=csv')->assertNotFound();
    }
});

it('shows the report on screen with totals over every row, the basis note and a chart', function () {
    $response = $this->actingAs($this->owner)
        ->getJson(route('hrm.reports.preview', 'sales_by_channel').'?invoiced_from=2026-10-01&invoiced_to=2026-10-31&brand='.$this->lld->id)
        ->assertOk();

    expect($response->json('row_count'))->toBe(1)
        ->and($response->json('rows.0.net_revenue.value'))->toEqual(825)
        ->and($response->json('totals.net_revenue.display'))->toBe('825.00')
        ->and($response->json('meta.note'))->toContain('Basis: invoiced')
        ->and($response->json('meta.filters.Brand'))->toBe('LaLaDia')
        ->and($response->json('chart.series.0.key'))->toBe('net_revenue');

    $this->actingAs($this->owner)->get(route('hrm.reports.show', 'sales_margin'))->assertOk();
});

it('exports through the registry writers with the basis note in the PDF', function () {
    foreach (['csv', 'xlsx'] as $format) {
        $this->actingAs($this->owner)
            ->get(route('hrm.reports.run', 'sales_by_channel').'?invoiced_from=2026-10-01&invoiced_to=2026-10-31&format='.$format)
            ->assertOk()
            ->assertDownload();
    }

    $csv = $this->actingAs($this->owner)
        ->get(route('hrm.reports.run', 'sales_margin').'?invoiced_from=2026-10-01&invoiced_to=2026-10-31&format=csv');

    expect(file_get_contents($csv->baseResponse->getFile()->getPathname()))->toContain('Ghee 400g');

    $print = $this->actingAs($this->owner)
        ->get(route('hrm.reports.run', 'sales_by_channel').'?invoiced_from=2026-10-01&invoiced_to=2026-10-31&format=print')
        ->assertOk();

    expect($print->getContent())->toContain('Basis: invoiced')->toContain('LaLaDia');
});

it('takes the VAT out of inclusive prices and their discount, still tying to the ledger', function () {
    $vat = rpBusiness('Vat Co', 'vat-rp');
    (new \App\Modules\Accounting\database\seeders\TaxAccountMappingSeeder)->run();
    app(BusinessContext::class)->set($vat->id);
    $owner = rpUser($vat, 'Owner');

    $standard = \App\Modules\Accounting\Models\TaxClass::create(['business_id' => $vat->id, 'code' => 'STANDARD', 'name' => 'Standard']);
    \App\Modules\Accounting\Models\TaxRate::create(['business_id' => $vat->id, 'tax_class_id' => $standard->id, 'rate' => '15', 'effective_from' => '2026-01-01']);
    $vat->forceFill(['vat_enabled' => true, 'vat_effective_from' => '2026-10-01', 'vat_regime' => 'vat_standard'])->save();

    $warehouse = Warehouse::create(['business_id' => $vat->id, 'name' => 'W', 'code' => 'W']);
    $unit = UnitOfMeasure::create(['business_id' => $vat->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $taxed = Product::create(['business_id' => $vat->id, 'unit_of_measure_id' => $unit->id, 'sku' => 'T', 'name' => 'Taxed', 'type' => ProductType::FinishedGood, 'tax_class_id' => $standard->id]);
    $plain = Product::create(['business_id' => $vat->id, 'unit_of_measure_id' => $unit->id, 'sku' => 'P', 'name' => 'Plain', 'type' => ProductType::FinishedGood]);
    $customer = Customer::create(['business_id' => $vat->id, 'name' => 'Shop', 'type' => CustomerType::Shop]);

    foreach ([$taxed, $plain] as $product) {
        app(RecordStockIn::class)->handle($product, $warehouse, '10', '100', $owner, occurredAt: '2026-10-01 09:00:00');
    }

    $lines = app(RecordDelivery::class)->handle(
        customer: $customer, warehouse: $warehouse, createdBy: $owner, deliveredAt: '2026-10-05 09:00:00',
        lines: [['product' => $taxed, 'quantity' => '2'], ['product' => $plain, 'quantity' => '1']],
    )->lines;

    app(RecordSalesInvoice::class)->handle(
        customer: $customer,
        lines: [
            ['deliveryLine' => $lines[0], 'product' => $taxed, 'quantity' => '2', 'unitPrice' => '575', 'discount' => '0'],
            ['deliveryLine' => $lines[1], 'product' => $plain, 'quantity' => '1', 'unitPrice' => '333', 'discount' => '0'],
        ],
        createdBy: $owner,
        invoiceDate: '2026-10-05',
        invoiceDiscount: '100',
        pricesIncludeTax: true,
    );

    $rows = rpRows(SalesByChannelReport::class, $this->period->all());
    $ledger = -1 * (rpMovement($vat->id, 'sales.revenue') + rpMovement($vat->id, 'sales.sales_returns') + rpMovement($vat->id, 'sales.discounts_allowed'));
    $vatPosted = -1 * rpMovement($vat->id, 'sales.output_vat');

    expect($vatPosted)->toBeGreaterThan(0)
        ->and((int) round($rows->sum('net_revenue') * 100))->toBe($ledger)
        // What the customer pays, less the VAT in it, is the revenue: nothing more.
        ->and($ledger + $vatPosted)->toBe((1150 + 333 - 100) * 100)
        ->and((int) round(rpRows(SalesMarginReport::class, $this->period->all())->sum('revenue') * 100))->toBe($ledger);
});
