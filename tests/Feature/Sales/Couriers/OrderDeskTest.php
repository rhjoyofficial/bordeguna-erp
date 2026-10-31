<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Couriers\Actions\SaveCourierAccount;
use App\Modules\Sales\Couriers\Jobs\BookParcelsInBulk;
use App\Modules\Sales\Couriers\Models\CourierAccountBusiness;
use App\Modules\Sales\Couriers\Models\CourierBulkRun;
use App\Modules\Sales\Couriers\Models\CourierBulkRunItem;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ Order desk (Joy, 31 Oct 2026): one press saves the order, takes what the
 * customer paid (any number of ways) and sends it to the courier through the
 * same run the Parcels to book screen uses.
 */
beforeEach(function () {
    $this->withoutVite();
    Carbon::setTestNow('2026-10-31 10:00:00');

    $this->business = Business::create(['name' => 'Desk Co', 'slug' => 'desk-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    (new SalesAccountMappingSeeder)->run();
    (new WalkInCustomerSeeder)->run();
    (new SalesPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['owner' => 'Owner', 'staff' => 'Staff'] as $prop => $role) {
        $this->{$prop} = User::factory()->create();
        $this->{$prop}->businesses()->attach($this->business->id);
        $this->{$prop}->assignRole($role);
    }

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN', 'is_default' => true]);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Bottles', 'abbreviation' => 'btl']);
    $this->product = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id, 'weight_grams' => 1000,
        'sku' => 'OIL-1L', 'name' => 'Mustard Oil - 1L', 'type' => ProductType::FinishedGood,
    ]);
    ProductPrice::create(['business_id' => $this->business->id, 'product_id' => $this->product->id, 'unit_price' => '320', 'effective_from' => '2026-01-01']);
    app(RecordStockIn::class)->handle($this->product, $this->warehouse, '20', '250', $this->owner, occurredAt: '2026-10-01 09:00:00');

    $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'Shah Nawaz', 'type' => CustomerType::Retail, 'credit_limit' => '100000000']);
    $this->bkash = MoneyAccount::create(['business_id' => $this->business->id, 'name' => 'bKash', 'type' => MoneyAccountType::Mfs, 'account_id' => Account::bySlug('cash_in_hand')->id, 'is_active' => true]);
    $this->cash = MoneyAccount::create(['business_id' => $this->business->id, 'name' => 'Cash box', 'type' => MoneyAccountType::Cash, 'account_id' => Account::bySlug('cash_in_hand')->id, 'is_active' => true]);
    $courierMoney = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'CarryBee money', 'type' => MoneyAccountType::Courier,
        'provider' => 'carrybee', 'account_id' => Account::bySlug('courier_receivable')->id, 'is_active' => true,
    ]);
    $this->courierMoney = $courierMoney;

    $account = app(SaveCourierAccount::class)->handle(null, [
        'provider' => 'carrybee', 'label' => 'CarryBee LaLaDia', 'environment' => 'live', 'base_url' => 'https://developers.carrybee.com',
        'credentials' => ['client_id' => 'cb-id', 'client_secret' => 'cb-SECRET-1234', 'client_context' => 'cb-CONTEXT-5678'],
    ]);
    $account->forceFill(['last_test_at' => now(), 'last_test_ok' => true, 'tested_fingerprint' => $account->currentFingerprint(), 'is_active' => true, 'activated_at' => now()])->save();
    CourierAccountBusiness::query()->withoutGlobalScopes()->create([
        'courier_account_id' => $account->id, 'business_id' => $this->business->id, 'provider_store_id' => '24869',
        'provider_store_name' => 'Adabor 2', 'money_account_id' => $courierMoney->id, 'is_default' => true,
    ]);
    $this->account = $account->fresh();

    $this->post = fn (array $extra = [], ?User $by = null) => $this->actingAs($by ?? $this->owner)->post('/sales/orders', [
        'customer_id' => $this->customer->id,
        'warehouse_id' => $this->warehouse->id,
        'ordered_at' => '2026-10-31',
        'lines' => [['product_id' => $this->product->id, 'quantity' => '3']],
        'delivery_method' => 'courier',
        'address' => ['name' => 'Shah Nawaz', 'phone' => '01727317905', 'district' => 'Dhaka', 'thana' => 'Dhanmondi', 'address_line' => 'House 7'],
        ...$extra,
    ]);
    $this->latest = fn () => SalesOrder::query()->latest('id')->first();
});

afterEach(fn () => Carbon::setTestNow());

it('takes an advance in two ways with the order, so only the rest is left to collect', function () {
    ($this->post)(['payments' => [
        ['money_account_id' => $this->bkash->id, 'amount' => '500', 'reference' => 'TRX1'],
        ['money_account_id' => $this->cash->id, 'amount' => '200'],
    ]])->assertSessionHasNoErrors();

    $order = ($this->latest)();

    expect(CustomerReceipt::query()->count())->toBe(2)
        ->and(CustomerReceipt::query()->pluck('money_account_id')->sort()->values()->all())->toBe(collect([$this->bkash->id, $this->cash->id])->sort()->values()->all())
        // 3 x 320 plus the courier zone charge, less the 700 paid.
        ->and($order->stillToPay()->toTaka())->toBe(number_format((float) $order->agreedTotal() - 700, 2, '.', ''));
});

it('refuses a payment into the courier money account, and writes nothing', function () {
    ($this->post)(['payments' => [['money_account_id' => $this->courierMoney->id, 'amount' => '100']]])
        ->assertSessionHasErrors('payments.0.money_account_id');

    expect(SalesOrder::query()->count())->toBe(0)->and(CustomerReceipt::query()->count())->toBe(0);
});

it('saves and sends an approved order to the courier chosen, in one press', function () {
    Queue::fake();

    ($this->post)(['then' => 'book', 'courier_account_id' => $this->account->id])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', fn (string $message) => str_contains($message, 'sent to CarryBee LaLaDia'));

    $order = ($this->latest)();
    $run = CourierBulkRun::query()->sole();

    expect($run->courier_account_id)->toBe($this->account->id)
        ->and(CourierBulkRunItem::query()->sole()->sales_order_id)->toBe($order->id);
    Queue::assertPushed(BookParcelsInBulk::class);
});

it('saves without booking when only Save is pressed', function () {
    Queue::fake();

    ($this->post)(['then' => 'save'])->assertSessionHasNoErrors();

    expect(CourierBulkRun::query()->count())->toBe(0);
    Queue::assertNotPushed(BookParcelsInBulk::class);
});

it('keeps an order that waits for approval out of the courier until it is approved', function () {
    Queue::fake();
    $this->staff->givePermissionTo('couriers.parcels.book');

    ($this->post)(['then' => 'book'], $this->staff)
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', fn (string $message) => str_contains($message, 'waits for approval'));

    expect(CourierBulkRun::query()->count())->toBe(0);
});

it('refuses Save and book to someone who may not book parcels, before writing anything', function () {
    ($this->post)(['then' => 'book'], $this->staff)->assertSessionHasErrors('then');

    expect(SalesOrder::query()->count())->toBe(0);
});

it('approves a waiting order and sends it to the courier in one press (Joy decision 3)', function () {
    Queue::fake();
    ($this->post)([], $this->staff)->assertSessionHasNoErrors();
    $order = ($this->latest)();
    expect($order->approvalRequest->isPending())->toBeTrue();

    $this->actingAs($this->owner)->post("/sales/orders/{$order->id}/approve", ['book' => true])
        ->assertSessionHas('success', fn (string $message) => str_contains($message, 'approved and sent to CarryBee LaLaDia'));

    expect(CourierBulkRunItem::query()->sole()->sales_order_id)->toBe($order->id);
    Queue::assertPushed(BookParcelsInBulk::class);
});
