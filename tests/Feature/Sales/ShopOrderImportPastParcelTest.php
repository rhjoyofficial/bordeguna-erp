<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Brand;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Enums\ConsignmentStatus;
use App\Modules\Sales\Enums\OrderConfirmation;
use App\Modules\Sales\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\Consignment;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderImport;
use App\Modules\Sales\Models\SalesOrderImportOrder;
use App\Modules\Sales\Support\ShopOrderSheet;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Past parcels by import sheet (Joy, 26 Oct 2026): a shop order that already
 * went out by CarryBee and was delivered is imported with its whole history,
 * approval, challan on the day it left (FIFO), the real consignment id, and
 * Delivered on the day with what was collected, in one transaction per order.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-26 10:00:00');
    Storage::fake('local');
    $this->withoutVite();

    $this->business = Business::create(['name' => 'Bor de Guna', 'slug' => 'bdg']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $role) {
        Role::create(['name' => $role, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    (new SalesAccountMappingSeeder)->run();
    (new WalkInCustomerSeeder)->run();
    (new SalesPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['owner' => 'Owner', 'manager' => 'Manager', 'staff' => 'Staff'] as $prop => $role) {
        $this->{$prop} = User::factory()->create();
        $this->{$prop}->businesses()->attach($this->business->id);
        $this->{$prop}->assignRole($role);
    }

    Brand::create(['business_id' => $this->business->id, 'code' => 'BNC', 'name' => 'Bionic-Garden', 'slug' => 'bionic-garden', 'is_active' => true]);

    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $this->ghee = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id, 'weight_grams' => 450,
        'sku' => 'BNC-GHEE-400', 'name' => 'Royal Essence Ghee 400g', 'type' => ProductType::FinishedGood,
    ]);
    $this->honey = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id, 'weight_grams' => 1100,
        'sku' => 'BNC-HONEY-1KG', 'name' => 'Mangrove Gold Honey 1kg', 'type' => ProductType::FinishedGood,
    ]);
    ProductPrice::create(['business_id' => $this->business->id, 'product_id' => $this->ghee->id, 'unit_price' => '850', 'effective_from' => '2026-01-01']);
    ProductPrice::create(['business_id' => $this->business->id, 'product_id' => $this->honey->id, 'unit_price' => '1690', 'effective_from' => '2026-01-01']);

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN', 'is_default' => true]);
    $this->batch1 = app(RecordStockIn::class)->handle($this->ghee, $this->warehouse, '2', '400', $this->owner, occurredAt: '2026-09-10 09:00:00');
    app(RecordStockIn::class)->handle($this->honey, $this->warehouse, '10', '900', $this->owner, occurredAt: '2026-09-01 09:00:00');

    $this->courier = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'CarryBee - Bionic', 'type' => MoneyAccountType::Courier,
        'provider' => 'CarryBee', 'account_id' => Account::bySlug('courier_receivable')->id, 'is_active' => true,
    ]);
    $this->bkash = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'bKash merchant', 'type' => MoneyAccountType::Mfs,
        'account_id' => Account::bySlug('cash_in_hand')->id, 'is_active' => true,
    ]);

    $this->posted = fn (string $slug) => (int) JournalLine::where('account_id', Account::bySlug($slug)->id)->sum('amount_minor');
});

afterEach(fn () => Carbon::setTestNow());

/** @param list<array<string, string>> $rows */
function ppCsv(array $rows): string
{
    $handle = fopen('php://temp', 'r+');
    fputcsv($handle, array_keys(ShopOrderSheet::COLUMNS));

    foreach ($rows as $row) {
        fputcsv($handle, array_map(fn (string $c) => $row[$c] ?? '', array_keys(ShopOrderSheet::COLUMNS)));
    }

    rewind($handle);

    return (string) stream_get_contents($handle);
}

/** @param array<string, string> $over */
function ppRow(array $over = []): array
{
    return $over + [
        'order_ref' => '#BNC-0919-01', 'order_date' => '18/09/2026', 'brand' => 'BNC', 'channel' => 'facebook',
        'customer_name' => 'Karim Uddin', 'phone' => '01711000000', 'district' => 'Dhaka', 'thana' => 'Dhanmondi',
        'address_line' => 'House 4, Road 2, Jigatola', 'sku' => 'BNC-GHEE-400', 'quantity' => '2', 'delivery_charge' => '60',
        'warehouse' => 'Main', 'courier' => 'CarryBee - Bionic', 'consignment_id' => 'F0919ABC', 'sent_on' => '19/09/2026',
        'delivered_on' => '20/09/2026',
    ];
}

function ppPreview(object $t, string $csv, ?User $as = null): array
{
    $preview = null;

    $t->actingAs($as ?? $t->manager)->post('/sales/orders/import/preview', [
        'file' => UploadedFile::fake()->createWithContent('past.csv', $csv),
    ])->assertOk()->assertInertia(function (Assert $page) use (&$preview) {
        $preview = $page->toArray()['props']['preview'];
    });

    return $preview;
}

function ppImport(object $t, string $csv, array $extra = [], ?User $as = null)
{
    $as ??= $t->manager;
    $preview = ppPreview($t, $csv, $as);
    expect($preview['errors'])->toBe([]);

    return $t->actingAs($as)->post('/sales/orders/import', $extra + [
        'token' => $preview['token'], 'hash' => $preview['hash'], 'file_name' => $preview['file_name'],
    ]);
}

it('imports a delivered parcel with its whole history on the real days', function () {
    $preview = ppPreview($this, ppCsv([ppRow()]));

    expect($preview['errors'])->toBe([])
        ->and($preview['orders'][0]['parcel']['summary'])->toBe('past parcel: CarryBee - Bionic F0919ABC, sent 19/09, delivered 20/09')
        ->and($preview['orders'][0]['parcel']['collected'])->toBe('1760.00')
        ->and($preview['totals']['past_parcels'])->toBe(1);

    // ⚠ The auto-approve box is left off: a past parcel is approved anyway.
    ppImport($this, ppCsv([ppRow()]))->assertRedirect();

    $import = SalesOrderImport::query()->sole();
    expect($import->orders_created)->toBe(1)->and($import->orders_failed)->toBe(0);
    expect(SalesOrderImportOrder::query()->sole()->message)->toContain('CarryBee - Bionic F0919ABC')->toContain('19/09/2026');

    $order = SalesOrder::query()->where('shop_reference', '#BNC-0919-01')->sole();
    expect($order->status)->toBe(SalesOrderStatus::Delivered)
        ->and($order->approvalRequest->status)->toBe(ApprovalStatus::Approved)
        ->and($order->confirmation_status)->toBe(OrderConfirmation::Confirmed)
        ->and($order->ordered_at->toDateString())->toBe('2026-09-18')
        ->and((int) $order->warehouse_id)->toBe($this->warehouse->id);

    // Stock left on the day the parcel left.
    $delivery = Delivery::query()->sole();
    expect($delivery->delivered_at->toDateString())->toBe('2026-09-19')
        ->and((int) $delivery->warehouse_id)->toBe($this->warehouse->id);
    $line = $delivery->lines()->sole();
    expect((int) $line->sales_order_line_id)->toBe($order->lines()->sole()->id);
    expect($line->movements->map(fn ($m) => $m->occurred_at->toDateString())->unique()->values()->all())->toBe(['2026-09-19']);
    expect((float) $this->ghee->fresh()->currentStockQuantity())->toBe(0.0);

    // The courier's answer: delivered on the 20th, invoice and receipt that day, into 1145.
    $consignment = Consignment::query()->sole();
    expect($consignment->status)->toBe(ConsignmentStatus::Delivered)
        ->and($consignment->consignment_number)->toBe('F0919ABC')
        ->and((int) $consignment->money_account_id)->toBe($this->courier->id)
        ->and($consignment->outcome_on->toDateString())->toBe('2026-09-20')
        ->and($consignment->codAmount()->toTaka())->toBe('1760.00');

    $invoice = SalesInvoice::query()->sole();
    expect($invoice->invoice_date->toDateString())->toBe('2026-09-20')
        ->and((string) $invoice->total)->toBe('1760.0000')
        ->and($invoice->outstanding()->isZero())->toBeTrue();

    $receipt = CustomerReceipt::query()->sole();
    expect($receipt->received_on->toDateString())->toBe('2026-09-20')
        ->and((int) $receipt->money_account_id)->toBe($this->courier->id)
        ->and((string) $receipt->amount)->toBe('1760.0000');

    expect(($this->posted)('courier_receivable'))->toBe(176000);
});

it('sends the oldest parcel first so a back-dated challan takes the batch it really took', function () {
    // A second batch of ghee arrived on the 22nd, after the first parcel left.
    $batch2 = app(RecordStockIn::class)->handle($this->ghee, $this->warehouse, '5', '500', $this->owner, occurredAt: '2026-09-22 09:00:00');

    // The later parcel is FIRST in the file; in file order it would take the
    // 10 Sep batch and leave the 15 Sep parcel needing goods that came on the 22nd.
    $csv = ppCsv([
        ppRow(['order_ref' => 'LATE', 'order_date' => '23/09/2026', 'consignment_id' => 'F0924', 'sent_on' => '24/09/2026', 'delivered_on' => '25/09/2026']),
        ppRow(['order_ref' => 'EARLY', 'order_date' => '14/09/2026', 'phone' => '01811000000', 'consignment_id' => 'F0915', 'sent_on' => '15/09/2026', 'delivered_on' => '16/09/2026']),
    ]);

    ppImport($this, $csv)->assertRedirect();
    expect(SalesOrderImport::query()->sole()->orders_created)->toBe(2);

    $batchesOf = fn (string $ref) => Delivery::query()
        ->whereHas('lines.orderLine.order', fn ($q) => $q->where('shop_reference', $ref))
        ->sole()->lines()->sole()->movements->pluck('batch_id')->map(fn ($id) => (int) $id)->unique()->values()->all();

    expect($batchesOf('EARLY'))->toBe([(int) $this->batch1->batch_id])
        ->and($batchesOf('LATE'))->toBe([(int) $batch2->batch_id]);
});

it('undoes the whole order when a step refuses, and keeps the others', function () {
    // The honey parcel leaves on the 5th from honey received on the 1st; the ghee
    // parcel leaves on the 9th, a day before any ghee arrived, so its challan is refused.
    $csv = ppCsv([
        ppRow(['order_ref' => 'GOOD', 'sku' => 'BNC-HONEY-1KG', 'quantity' => '1', 'delivery_charge' => '0', 'consignment_id' => 'F0905', 'order_date' => '04/09/2026', 'sent_on' => '05/09/2026', 'delivered_on' => '06/09/2026']),
        ppRow(['order_ref' => 'BAD', 'phone' => '01811000000', 'order_date' => '08/09/2026', 'consignment_id' => 'F0909', 'sent_on' => '09/09/2026', 'delivered_on' => '10/09/2026']),
    ]);

    ppImport($this, $csv)->assertRedirect();

    $import = SalesOrderImport::query()->sole();
    expect($import->orders_created)->toBe(1)->and($import->orders_failed)->toBe(1);

    $failure = SalesOrderImportOrder::query()->where('order_ref', 'BAD')->sole();
    expect($failure->outcome)->toBe(SalesOrderImportOrder::FAILED)
        ->and($failure->sales_order_id)->toBeNull()
        ->and($failure->message)->toContain('Past parcel F0909')->toContain('10/09/2026')->toContain('Nothing was written');

    expect(SalesOrder::query()->where('shop_reference', 'BAD')->exists())->toBeFalse()
        ->and(Consignment::query()->where('consignment_number', 'F0909')->exists())->toBeFalse()
        ->and(Delivery::query()->count())->toBe(1)
        ->and(SalesInvoice::query()->count())->toBe(1)
        ->and((float) $this->ghee->fresh()->currentStockQuantity())->toBe(2.0);

    expect(SalesOrder::query()->where('shop_reference', 'GOOD')->sole()->status)->toBe(SalesOrderStatus::Delivered);
});

it('names every past-parcel problem by row before anything is written', function () {
    Warehouse::create(['business_id' => $this->business->id, 'name' => 'Old shop', 'code' => 'OLD', 'is_active' => false]);
    MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'Pathao old', 'type' => MoneyAccountType::Courier,
        'provider' => 'Pathao', 'account_id' => Account::bySlug('courier_receivable')->id, 'is_active' => false,
    ]);
    MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'Steadfast', 'type' => MoneyAccountType::Courier,
        'provider' => 'Steadfast', 'account_id' => null, 'is_active' => true,
    ]);

    $csv = ppCsv([
        ppRow(['order_ref' => 'P1', 'consignment_id' => '']),
        ppRow(['order_ref' => 'P2', 'warehouse' => '', 'courier' => '', 'sent_on' => '', 'delivered_on' => '']),
        ppRow(['order_ref' => 'P3', 'courier' => 'RedX']),
        ppRow(['order_ref' => 'P4', 'courier' => 'Pathao old']),
        ppRow(['order_ref' => 'P5', 'courier' => 'bKash merchant']),
        ppRow(['order_ref' => 'P6', 'courier' => 'Steadfast']),
        ppRow(['order_ref' => 'P7', 'warehouse' => 'Nowhere']),
        ppRow(['order_ref' => 'P8', 'warehouse' => 'OLD']),
        ppRow(['order_ref' => 'P9', 'consignment_id' => 'DUP1']),
        ppRow(['order_ref' => 'P10', 'consignment_id' => 'DUP1']),
        ppRow(['order_ref' => 'P11', 'sent_on' => '17/09/2026']),
        ppRow(['order_ref' => 'P12', 'delivered_on' => '18/09/2026']),
        ppRow(['order_ref' => 'P13', 'order_date' => '25/10/2026', 'sent_on' => '25/10/2026', 'delivered_on' => '2026-10-30']),
        ppRow(['order_ref' => 'P14', 'collected' => '1700']),
    ]);

    $preview = ppPreview($this, $csv);
    $errors = collect($preview['errors'])->map(fn ($e) => $e['order_ref'].':'.$e['field'])->all();

    expect($errors)->toContain(
        'P1:consignment_id', 'P2:warehouse', 'P2:courier', 'P2:sent_on', 'P2:delivered_on',
        'P3:courier', 'P4:courier', 'P5:courier', 'P6:courier', 'P7:warehouse', 'P8:warehouse',
        'P10:consignment_id', 'P11:sent_on', 'P12:delivered_on', 'P13:delivered_on', 'P14:collected',
    )->and($errors)->not->toContain('P9:consignment_id');

    $message = fn (string $key) => collect($preview['errors'])->first(fn ($e) => $key === $e['order_ref'].':'.$e['field'])['message'];
    expect($message('P3:courier'))->toContain('CarryBee - Bionic')
        ->and($message('P4:courier'))->toContain('switched off')
        ->and($message('P6:courier'))->toContain('1145')
        ->and($message('P10:consignment_id'))->toContain('P9')
        ->and($message('P11:sent_on'))->toContain('before it was ordered')
        ->and($message('P12:delivered_on'))->toContain('before it left')
        ->and($message('P13:delivered_on'))->toContain('future')
        ->and($message('P14:collected'))->toContain('1,760.00')->toContain('no door discount');

    // An error anywhere: nothing is written.
    $this->actingAs($this->manager)->post('/sales/orders/import', [
        'token' => $preview['token'], 'hash' => $preview['hash'], 'file_name' => $preview['file_name'],
    ])->assertSessionHasErrors('file');
    expect(SalesOrder::query()->whereNotNull('shop_reference')->count())->toBe(0);
});

it('warns when the warehouse holds less today than the file sends', function () {
    $preview = ppPreview($this, ppCsv([ppRow(['quantity' => '3'])]));

    expect($preview['errors'])->toBe([])
        ->and(collect($preview['warnings'])->pluck('message')->implode(' '))->toContain('take 3 of Royal Essence Ghee 400g from Main, which holds 2 today');
});

it('refuses past parcels to Staff, who may neither back-date nor approve', function () {
    $preview = ppPreview($this, ppCsv([ppRow(), ppRow(['order_ref' => 'SECOND', 'consignment_id' => 'F2'])]), $this->staff);

    $errors = collect($preview['errors']);
    expect($errors)->toHaveCount(1)
        ->and($errors->first()['field'])->toBe('consignment_id')
        ->and($errors->first()['line'])->toBe(2)
        ->and($errors->first()['message'])->toContain('Only a Manager or the Owner');
});

it('skips an order already imported and refuses its consignment id on another order', function () {
    ppImport($this, ppCsv([ppRow()]))->assertRedirect();

    app(RecordStockIn::class)->handle($this->ghee, $this->warehouse, '5', '500', $this->owner, occurredAt: '2026-10-01 09:00:00');

    // The same order again (a sheet that grew): skipped, not sent twice.
    $grown = ppCsv([ppRow(), ppRow(['order_ref' => 'NEW', 'phone' => '01811000000', 'consignment_id' => 'F1002', 'order_date' => '01/10/2026', 'sent_on' => '02/10/2026', 'delivered_on' => '03/10/2026'])]);
    ppImport($this, $grown)->assertRedirect();

    $second = SalesOrderImport::query()->latest('id')->first();
    expect($second->orders_created)->toBe(1)->and($second->orders_skipped)->toBe(1)
        ->and(Consignment::query()->count())->toBe(2)
        ->and(Delivery::query()->count())->toBe(2);

    // The first parcel's id on a different order is refused at the preview.
    $preview = ppPreview($this, ppCsv([ppRow(['order_ref' => 'OTHER', 'phone' => '01911000000'])]));
    expect(collect($preview['errors'])->first()['message'])->toContain('F0919ABC is already recorded in the ERP');
});

it('imports a prepaid parcel with an advance and a discount, collecting nothing at the door', function () {
    $csv = ppCsv([ppRow([
        'order_ref' => 'PREPAID', 'sku' => 'BNC-HONEY-1KG', 'quantity' => '1', 'line_discount' => '105', 'delivery_charge' => '0',
        'advance_paid' => '1585', 'advance_method' => 'bkash', 'advance_reference' => 'TRX1', 'payment_method' => 'prepaid',
        'consignment_id' => 'F0919PP', 'collected' => '0',
    ])]);

    $preview = ppPreview($this, $csv, $this->owner);
    expect($preview['errors'])->toBe([])->and($preview['orders'][0]['parcel']['collected'])->toBe('0.00');

    // Without the advance's account the courier figure cannot match: the order fails whole.
    ppImport($this, $csv, [], $this->owner)->assertRedirect();
    expect(SalesOrderImportOrder::query()->sole()->message)->toContain('no money account chosen')
        ->and(SalesOrder::query()->where('shop_reference', 'PREPAID')->exists())->toBeFalse();

    // The same orders in a fresh file (a new hash), with the account chosen.
    $again = $csv."\n";
    ppImport($this, $again, ['mappings' => ['bkash' => $this->bkash->id]], $this->owner)->assertRedirect();

    $order = SalesOrder::query()->where('shop_reference', 'PREPAID')->sole();
    expect($order->status)->toBe(SalesOrderStatus::Delivered);

    $consignment = Consignment::query()->sole();
    expect($consignment->codAmount()->isZero())->toBeTrue()
        ->and($consignment->status)->toBe(ConsignmentStatus::Delivered)
        ->and($consignment->customer_receipt_id)->toBeNull();

    $invoice = SalesInvoice::query()->sole();
    expect((string) $invoice->total)->toBe('1585.0000')
        ->and($invoice->outstanding()->isZero())->toBeTrue();

    // The only receipt is the advance into bKash on the order day.
    $receipt = CustomerReceipt::query()->sole();
    expect((int) $receipt->money_account_id)->toBe($this->bkash->id)
        ->and($receipt->received_on->toDateString())->toBe('2026-09-18')
        ->and(($this->posted)('courier_receivable'))->toBe(0);
});
