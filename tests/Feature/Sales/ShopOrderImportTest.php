<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Brand;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderImport;
use App\Modules\Sales\Models\SalesOrderImportOrder;
use App\Modules\Sales\Support\ShopOrderSheet;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use OpenSpout\Common\Entity\Cell\DateTimeCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Shop orders by import sheet (Joy, 11 Oct 2026): one row per order line,
 * grouped by the shop's order number, previewed with every problem named by
 * row, applied through CreateSalesOrder, idempotent by order number and file.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-11 10:00:00');
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

    $this->lld = Brand::create(['business_id' => $this->business->id, 'code' => 'LLD', 'name' => 'LaLaDia', 'slug' => 'laladia', 'is_active' => true]);
    $this->bnc = Brand::create(['business_id' => $this->business->id, 'code' => 'BNC', 'name' => 'Bionic-Garden', 'slug' => 'bionic-garden', 'is_active' => true]);

    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $this->ghee = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id, 'weight_grams' => 450, 'brand_id' => $this->lld->id,
        'sku' => 'LLD-GHEE-400', 'name' => 'Royal Essence Ghee 400g', 'type' => ProductType::FinishedGood,
    ]);
    $this->honey = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id, 'weight_grams' => 600,
        'sku' => 'LLD-HONEY-500', 'name' => 'Mangrove Gold Honey 500g', 'type' => ProductType::FinishedGood,
    ]);
    ProductPrice::create(['business_id' => $this->business->id, 'product_id' => $this->ghee->id, 'unit_price' => '850', 'effective_from' => '2026-01-01']);
    ProductPrice::create(['business_id' => $this->business->id, 'product_id' => $this->honey->id, 'unit_price' => '700', 'effective_from' => '2026-01-01']);

    $this->bkash = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'bKash merchant', 'type' => MoneyAccountType::Mfs,
        'account_id' => Account::bySlug('cash_in_hand')->id, 'is_active' => true,
    ]);
});

afterEach(fn () => Carbon::setTestNow());

/** @param list<array<string, string>> $rows */
function soiCsv(array $rows): string
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
function soiRow(array $over = []): array
{
    return $over + [
        'order_ref' => '#LLD-20261010-0001', 'order_date' => '10/10/2026', 'brand' => 'LaLaDia', 'channel' => 'facebook',
        'customer_name' => 'Karim Uddin', 'phone' => '+880 1711-000000', 'district' => 'Dhaka', 'thana' => 'Dhanmondi',
        'address_line' => 'House 4, Road 2, Jigatola', 'sku' => 'LLD-GHEE-400', 'quantity' => '2', 'delivery_charge' => '60',
    ];
}

function soiPreview(object $t, string $csv, ?User $as = null, string $name = 'orders.csv')
{
    return $t->actingAs($as ?? $t->manager)->post('/sales/orders/import/preview', [
        'file' => UploadedFile::fake()->createWithContent($name, $csv),
    ]);
}

/** Preview, then apply exactly what the preview showed. */
function soiImport(object $t, string $csv, array $extra = [], ?User $as = null)
{
    $as ??= $t->manager;
    $preview = null;

    soiPreview($t, $csv, $as)->assertOk()->assertInertia(function (Assert $page) use (&$preview) {
        $preview = $page->toArray()['props']['preview'];
    });

    return $t->actingAs($as)->post('/sales/orders/import', $extra + [
        'token' => $preview['token'], 'hash' => $preview['hash'], 'file_name' => $preview['file_name'],
    ]);
}

it('downloads the template as xlsx and csv with every column in order', function () {
    $csv = $this->actingAs($this->staff)->get('/sales/orders/import/template?format=csv')->assertOk();
    expect($csv->headers->get('content-type'))->toContain('text/csv')
        ->and(trim(substr($csv->streamedContent(), 3)))->toBe(implode(',', array_keys(ShopOrderSheet::COLUMNS)));

    $xlsx = $this->actingAs($this->staff)->get('/sales/orders/import/template?format=xlsx')->assertOk();
    expect($xlsx->headers->get('content-type'))->toContain('spreadsheetml');

    $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
    file_put_contents($path, $xlsx->streamedContent());
    $read = ShopOrderSheet::read($path, 'xlsx');
    expect($read['rows'])->toBe([])->and($read['ignored'])->toBe([]);
    @unlink($path);

    expect(array_keys(ShopOrderSheet::COLUMNS))->toBe([
        'order_ref', 'order_date', 'brand', 'channel', 'customer_name', 'phone', 'alt_phone', 'district', 'thana', 'area',
        'address_line', 'landmark', 'sku', 'quantity', 'unit_price', 'line_discount', 'order_discount', 'delivery_charge',
        'advance_paid', 'advance_method', 'advance_reference', 'payment_method', 'note', 'weight_grams',
    ]);
});

it('names every problem by row and column, and imports nothing while one remains', function () {
    SalesOrder::query()->insert([
        'business_id' => $this->business->id, 'customer_id' => Customer::query()->first()->id, 'order_number' => 'SO-OLD-1',
        'shop_reference' => '#LLD-OLD', 'ordered_at' => '2026-10-01', 'status' => 'open', 'order_discount' => 0,
        'created_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $csv = soiCsv([
        soiRow(['order_ref' => 'A1', 'sku' => 'NOPE-1']),
        soiRow(['order_ref' => 'A2', 'district' => 'Atlantis']),
        soiRow(['order_ref' => 'A3', 'thana' => 'Nowhere']),
        soiRow(['order_ref' => 'A4', 'phone' => '02-9110000']),
        soiRow(['order_ref' => 'A5', 'quantity' => '0']),
        soiRow(['order_ref' => 'A6']),
        soiRow(['order_ref' => 'A6', 'phone' => '01811000000', 'sku' => 'LLD-HONEY-500']),
        soiRow(['order_ref' => 'A7', 'brand' => 'Unknown brand']),
        soiRow(['order_ref' => 'A8', 'order_date' => '2026-12-01']),
        soiRow(['order_ref' => 'A9', 'unit_price' => '800']),
        soiRow(['order_ref' => 'A10', 'advance_paid' => '5000', 'advance_method' => 'bkash']),
        soiRow(['order_ref' => 'A11', 'advance_paid' => '100']),
        soiRow(['order_ref' => 'A12', 'channel' => 'telegram']),
        soiRow(['order_ref' => 'A13', 'brand' => '']),
        soiRow(['order_ref' => 'A14', 'area' => 'Not an area']),
        soiRow(['order_ref' => '#LLD-OLD']),
    ]);

    soiPreview($this, $csv, $this->staff)->assertOk()->assertInertia(function (Assert $page) {
        $preview = $page->toArray()['props']['preview'];
        $errors = collect($preview['errors'])->map(fn ($e) => $e['order_ref'].':'.$e['field'])->all();

        expect($errors)->toContain('A1:sku', 'A2:district', 'A3:thana', 'A4:phone', 'A5:quantity', 'A6:phone', 'A7:brand', 'A8:order_date',
            'A9:unit_price', 'A10:advance_paid', 'A11:advance_method', 'A12:channel', 'A13:brand')
            ->and(collect($preview['errors'])->firstWhere('order_ref', 'A1')['line'])->toBe(2)
            ->and(collect($preview['errors'])->firstWhere('order_ref', 'A6')['line'])->toBe(8)
            ->and(collect($preview['errors'])->firstWhere('order_ref', 'A9')['message'])->toContain('price-override permission')
            ->and(collect($preview['orders'])->pluck('order_ref')->all())->toBe(['A14'])
            ->and(collect($preview['warnings'])->pluck('message')->implode(' '))->toContain('Area "Not an area"')
            ->and($preview['skipped'])->toBe([['order_ref' => '#LLD-OLD', 'sales_order_id' => SalesOrder::query()->where('shop_reference', '#LLD-OLD')->value('id'), 'order_number' => 'SO-OLD-1']]);
    });

    $preview = null;
    soiPreview($this, $csv, $this->staff)->assertInertia(function (Assert $page) use (&$preview) {
        $preview = $page->toArray()['props']['preview'];
    });

    $this->actingAs($this->staff)->post('/sales/orders/import', ['token' => $preview['token'], 'hash' => $preview['hash'], 'file_name' => 'orders.csv'])
        ->assertSessionHasErrors('file');

    expect(SalesOrder::query()->count())->toBe(1)->and(SalesOrderImport::query()->count())->toBe(0);
});

it('creates the orders with customers, addresses, prices, discounts, delivery, weight and the advance', function () {
    $existing = Customer::create(['business_id' => $this->business->id, 'name' => 'Rahima Begum', 'type' => CustomerType::Retail, 'phone' => '01811000000']);

    $csv = soiCsv([
        soiRow(['line_discount' => '50', 'order_discount' => '100', 'advance_paid' => '500', 'advance_method' => 'bKash', 'advance_reference' => 'TX9', 'alt_phone' => '1911000000', 'area' => '', 'landmark' => 'Near the mosque']),
        ['order_ref' => '#LLD-20261010-0001', 'sku' => 'LLD-HONEY-500', 'quantity' => '1', 'unit_price' => '650'],
        soiRow(['order_ref' => '#BNC-0002', 'brand' => 'bnc', 'customer_name' => 'Rahima', 'phone' => '01811000000', 'district' => 'Chittagong', 'thana' => 'Chittagong Sadar',
            'sku' => 'lld-honey-500', 'quantity' => '3', 'delivery_charge' => '120', 'payment_method' => 'cod', 'weight_grams' => '2500', 'advance_paid' => '200', 'advance_method' => 'nagad', 'channel' => 'website']),
    ]);

    // The Owner: 200 off 2,400 is over a Manager's 3% (rule #3, 14 Oct 2026).
    soiImport($this, $csv, ['mappings' => ['bkash' => $this->bkash->id]], $this->owner)->assertRedirect()->assertSessionHasNoErrors();

    $first = SalesOrder::query()->where('shop_reference', '#LLD-20261010-0001')->with(['lines', 'address', 'customer'])->firstOrFail();
    $second = SalesOrder::query()->where('shop_reference', '#BNC-0002')->with(['lines', 'address', 'customer'])->firstOrFail();

    // A new Retail customer by phone, the existing one matched and never rewritten.
    expect($first->customer->phone)->toBe('01711000000')
        ->and($first->customer->type)->toBe(CustomerType::Retail)
        ->and($second->customer_id)->toBe($existing->id)
        ->and($existing->fresh()->name)->toBe('Rahima Begum');

    // Address through BdGeo, phone normalised.
    expect($first->address->recipient_phone)->toBe('01711000000')
        ->and($first->address->district)->toBe('Dhaka')->and($first->address->thana)->toBe('Dhanmondi')
        ->and($first->address->landmark)->toBe('Near the mosque')
        ->and($second->address->district)->toBe('Chattogram');

    // Lines: the list price, and the shop's own price marked as imported.
    $ghee = $first->lines->firstWhere('product_id', $this->ghee->id);
    $honey = $first->lines->firstWhere('product_id', $this->honey->id);
    expect((float) $ghee->unit_price)->toBe(850.0)->and((float) $ghee->line_discount)->toBe(50.0)
        ->and((float) $honey->unit_price)->toBe(650.0)
        ->and($honey->price_note)->toContain('Imported from shop (list price 700.00)');

    // Totals: 1700 - 50 + 650 - 100 + 60 = 2260; advance 500 recorded on bKash.
    expect($first->agreedTotal())->toBe('2260.00')
        ->and($first->delivery_charge)->toBe('60.00')
        ->and($first->weight_grams_auto)->toBe(1500)->and($first->weight_grams)->toBeNull()
        ->and($first->advanceHeld()->toTaka())->toBe('500.00')
        ->and(CustomerReceipt::query()->where('sales_order_id', $first->id)->value('reference'))->toBe('TX9')
        ->and($first->note)->toContain('Alternative phone 01911000000')->toContain('facebook');

    // Nagad had no account chosen: the advance stays in the note only.
    expect($second->advanceHeld()->isZero())->toBeTrue()
        ->and($second->note)->toContain('NOT recorded as a receipt')
        ->and($second->weight_grams)->toBe(2500)
        ->and($second->channel->value)->toBe('website')
        ->and($first->channel->value)->toBe('internal');

    // Waiting for approval: the importer did not ask to approve.
    expect($first->approvalRequest->status)->toBe(ApprovalStatus::Pending);

    $import = SalesOrderImport::query()->sole();
    expect($import->orders_created)->toBe(2)
        ->and(Storage::disk('local')->exists($import->file_path))->toBeTrue()
        ->and($import->file_sha256)->toBe(hash('sha256', $csv));
});

it('skips an order number imported before and refuses the same file twice', function () {
    $csv = soiCsv([soiRow()]);
    soiImport($this, $csv)->assertSessionHasNoErrors();

    soiPreview($this, $csv)->assertSessionHasErrors(['file' => 'This exact file was already imported on 2026-10-11 10:00 (import #'.SalesOrderImport::query()->value('id').'). Nothing new to do.']);

    // The sheet grew: the old order is skipped with a link, the new one made.
    $grown = soiCsv([soiRow(), soiRow(['order_ref' => '#LLD-20261010-0002', 'phone' => '01722000000'])]);
    soiImport($this, $grown)->assertSessionHasNoErrors();

    expect(SalesOrder::query()->count())->toBe(2)
        ->and(SalesOrderImportOrder::query()->where('outcome', 'skipped')->value('order_ref'))->toBe('#LLD-20261010-0001')
        ->and(SalesOrderImportOrder::query()->where('outcome', 'skipped')->value('message'))->toStartWith('Already imported as SO-');
});

it('writes the brand on the order through CreateSalesOrder, and keeps it on the import row', function () {
    soiImport($this, soiCsv([soiRow(), soiRow(['order_ref' => 'BNC-9', 'brand' => 'Bionic Garden', 'phone' => '01733000000'])]))->assertSessionHasNoErrors();

    expect(SalesOrder::query()->where('shop_reference', '#LLD-20261010-0001')->value('brand_id'))->toBe($this->lld->id)
        ->and(SalesOrder::query()->where('shop_reference', 'BNC-9')->value('brand_id'))->toBe($this->bnc->id)
        ->and(SalesOrderImportOrder::query()->where('order_ref', 'BNC-9')->value('brand_id'))->toBe($this->bnc->id)
        ->and(SalesOrderImportOrder::query()->where('order_ref', 'BNC-9')->value('brand_name'))->toBe('Bionic-Garden');

    // A brand switched off since is refused at the preview, not half way.
    $this->bnc->update(['is_active' => false]);
    soiPreview($this, soiCsv([soiRow(['order_ref' => 'BNC-10', 'brand' => 'BNC'])]))->assertInertia(fn (Assert $page) => $page
        ->where('preview.errors.0.field', 'brand'));
});

it('approves on import only for someone who may approve, through the approval rules', function () {
    soiImport($this, soiCsv([soiRow()]), ['auto_approve' => true], $this->staff)->assertSessionHasErrors('auto_approve');
    expect(SalesOrder::query()->count())->toBe(0);

    soiImport($this, soiCsv([soiRow(['order_ref' => 'X2'])]), ['auto_approve' => true], $this->manager)->assertSessionHasNoErrors();

    expect(SalesOrder::query()->where('shop_reference', 'X2')->first()->approvalRequest->status)->toBe(ApprovalStatus::Approved)
        ->and(SalesOrderImport::query()->sole()->auto_approve)->toBeTrue();
});

it('lets a person with the override permission import a shop price, and staff record advances they may receive', function () {
    soiImport($this, soiCsv([soiRow(['unit_price' => '800'])]), [], $this->owner)->assertSessionHasNoErrors();
    expect((float) SalesOrder::query()->sole()->lines->first()->unit_price)->toBe(800.0);

    soiImport($this, soiCsv([soiRow(['order_ref' => 'S1', 'advance_paid' => '100', 'advance_method' => 'bkash', 'phone' => '01744000000'])]), ['mappings' => ['bkash' => $this->bkash->id]], $this->staff)
        ->assertSessionHasNoErrors();
    expect(SalesOrder::query()->where('shop_reference', 'S1')->first()->advanceHeld()->toTaka())->toBe('100.00');
});

it('reads an xlsx whose phones are numbers and whose dates are dates', function () {
    $path = tempnam(sys_get_temp_dir(), 'ord').'.xlsx';
    $writer = new XlsxWriter;
    $writer->openToFile($path);
    $writer->addRow(new Row(array_map(fn (string $c) => new StringCell($c), array_keys(ShopOrderSheet::COLUMNS))));
    $values = soiRow();
    $writer->addRow(new Row(array_map(fn (string $c) => match ($c) {
        'phone' => new NumericCell(1711000000),
        'order_date' => new DateTimeCell(new DateTimeImmutable('2026-10-10')),
        'quantity' => new NumericCell(2),
        default => new StringCell($values[$c] ?? ''),
    }, array_keys(ShopOrderSheet::COLUMNS))));
    $writer->close();

    $this->actingAs($this->manager)->post('/sales/orders/import/preview', [
        'file' => new UploadedFile($path, 'orders.xlsx', null, null, true),
    ])->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('sales/orders/import')
        ->where('preview.errors', [])
        ->where('preview.orders.0.phone', '01711000000')
        ->where('preview.orders.0.order_date', '2026-10-10')
        ->where('preview.orders.0.total', '1760.00'));

    @unlink($path);
});

it('keeps the importer behind the order-entry permission', function () {
    $outsider = User::factory()->create();
    $outsider->businesses()->attach($this->business->id);

    $this->actingAs($outsider)->get('/sales/orders/import')->assertForbidden();
    $this->actingAs($outsider)->get('/sales/orders/import/template')->assertForbidden();
    soiPreview($this, soiCsv([soiRow()]), $outsider)->assertForbidden();
    $this->actingAs($outsider)->post('/sales/orders/import', ['token' => 'x', 'hash' => str_repeat('a', 64), 'file_name' => 'x'])->assertForbidden();

    $this->actingAs($this->staff)->get('/sales/orders/import')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('sales/orders/import')->where('can.approve', false)->where('can.override_price', false));
});

/** Preview a sheet and hand back the preview's errors as "ref:field" => message. */
function soiErrors(object $t, string $csv, User $as): array
{
    $errors = [];

    soiPreview($t, $csv, $as)->assertOk()->assertInertia(function (Assert $page) use (&$errors) {
        foreach ($page->toArray()['props']['preview']['errors'] as $e) {
            $errors[$e['order_ref'].':'.$e['field']] = $e['message'];
        }
    });

    return $errors;
}

/*
 * Rule #3 (Joy, 14 Oct 2026): an imported order's discount follows the counter's
 * policy. Any discount needs the price-override permission, and the WHOLE order
 * (price cuts below the list + line discounts + the order discount) is measured
 * by DiscountCap against the goods at list price, so a discount split across
 * lines cannot slip under the cap.
 */
it('refuses an imported discount from a person who may not give one, as the counter does', function () {
    $errors = soiErrors($this, soiCsv([
        soiRow(['order_ref' => 'D1', 'line_discount' => '5']),
        soiRow(['order_ref' => 'D2', 'order_discount' => '5', 'phone' => '01722000000']),
    ]), $this->staff);

    expect($errors)->toHaveKeys(['D1:order_discount', 'D2:order_discount'])
        ->and($errors['D1:order_discount'])->toContain('price-override permission');
});

it('measures the whole imported order against the cap, so a discount split across lines is caught', function () {
    // Two ghee at 850 = 1,700 of goods; the Manager's 3% is 51. A price cut,
    // a line discount and the order discount are each under it; together 60.
    $csv = soiCsv([
        soiRow(['order_ref' => 'C1', 'unit_price' => '840', 'line_discount' => '20', 'order_discount' => '20']),
    ]);

    $errors = soiErrors($this, $csv, $this->manager);

    expect($errors)->toHaveKey('C1:order_discount')
        ->and($errors['C1:order_discount'])->toContain('60.00')->toContain('Your limit is');

    soiImport($this, $csv, [], $this->manager)->assertSessionHasErrors('file');
    expect(SalesOrder::query()->count())->toBe(0);

    // Staff given the override permission: 1% of 1,700 or Tk 300, so 17.
    $this->staff->givePermissionTo('sales.price.override');
    expect(soiErrors($this, soiCsv([soiRow(['order_ref' => 'C2', 'order_discount' => '18'])]), $this->staff->fresh()))
        ->toHaveKey('C2:order_discount');
});

it('imports a discount inside the cap and keeps what was given on the order for audit; the Owner has no cap', function () {
    soiImport($this, soiCsv([soiRow(['order_ref' => 'OK1', 'line_discount' => '25', 'order_discount' => '25'])]), [], $this->manager)
        ->assertSessionHasNoErrors();

    $order = SalesOrder::query()->where('shop_reference', 'OK1')->sole();
    expect($order->note)->toContain('Imported discount 50.00 off 1,700.00 of goods at list price')
        ->and((float) $order->order_discount)->toBe(25.0);

    soiImport($this, soiCsv([soiRow(['order_ref' => 'OWN1', 'order_discount' => '500', 'phone' => '01722000000'])]), [], $this->owner)
        ->assertSessionHasNoErrors();
    expect((float) SalesOrder::query()->where('shop_reference', 'OWN1')->value('order_discount'))->toBe(500.0);
});
