<?php

use App\Models\User;
use App\Modules\Accounting\Actions\PostVoucher;
use App\Modules\Accounting\Actions\ReverseVoucher;
use App\Modules\Accounting\Actions\SaveVoucherDraft;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Enums\VoucherType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FinancePolicy;
use App\Modules\Accounting\Support\SubledgerReconciliation;
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
use App\Modules\Sales\Actions\CreateSalesOrder;
use App\Modules\Sales\Actions\RecordDelivery;
use App\Modules\Sales\Actions\RecordSalesReturn;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Enums\SalesChannel;
use App\Modules\Sales\Models\Consignment;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerRefund;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Support\SalesOrderOrigin;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ A customer on a Payment voucher (party ledgers, integration 18 Oct 2026):
 * money paid back against a sales return, or out of what an order still
 * holds, is the same CustomerRefund the return and order pages record, owned
 * by the voucher and carried on the voucher's one journal.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Refund Co', 'slug' => 'refund-co']);
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
    FinancePolicy::create(['business_id' => $this->business->id, 'refuse_negative_money' => false]);

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $this->product = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-HONEY', 'name' => 'Mangrove Honey 500g', 'type' => ProductType::FinishedGood,
    ]);
    $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'Online buyer', 'type' => CustomerType::Retail])->refresh();

    ProductPrice::create(['business_id' => $this->business->id, 'product_id' => $this->product->id, 'unit_price' => '850', 'effective_from' => '2026-01-01']);

    Carbon::setTestNow('2026-09-14 10:00:00');

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->owner->assignRole('Owner');

    $this->staff = User::factory()->create();
    $this->staff->businesses()->attach($this->business->id);
    $this->staff->assignRole('Staff');

    app(RecordStockIn::class)->handle($this->product, $this->warehouse, '100', '500', $this->owner, occurredAt: '2026-09-01 09:00:00');

    $this->courier = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'Pathao', 'type' => MoneyAccountType::Courier,
        'provider' => 'Pathao', 'account_id' => Account::bySlug('courier_receivable')->id, 'is_active' => true,
    ]);
    $this->bkash = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'bKash merchant', 'type' => MoneyAccountType::Cash,
        'account_id' => Account::bySlug('cash_in_hand')->id, 'is_active' => true,
    ]);

    // 2 x 850 of goods plus 120 delivery = 1,820, paid before delivery.
    $this->order = function (): SalesOrder {
        $order = app(CreateSalesOrder::class)->handle(
            customer: $this->customer,
            lines: [['product' => $this->product, 'quantity' => '2']],
            createdBy: $this->owner,
            origin: new SalesOrderOrigin(channel: SalesChannel::Internal, deliveryCharge: '120'),
        );

        $this->actingAs($this->staff)->post("/sales/orders/{$order->id}/payments", [
            'money_account_id' => $this->bkash->id, 'amount' => '1820', 'received_on' => '2026-09-14', 'reference' => 'TRX9A7B',
        ])->assertSessionHasNoErrors();

        return $order->fresh('lines');
    };

    $this->voucher = fn (array $billWise, string $amount) => app(PostVoucher::class)->handle(app(SaveVoucherDraft::class)->handle(VoucherType::Payment, [
        'voucher_date' => '2026-09-14', 'money_account_id' => $this->bkash->id,
        'lines' => [['account_id' => $this->customer->account_id, 'amount' => $amount, 'bill_wise' => $billWise]],
    ], $this->owner), $this->owner);

    $this->reconciles = fn () => array_values(array_filter(
        SubledgerReconciliation::reconcileCurrentBusiness()['problems'],
        fn (string $problem) => str_contains($problem, 'Receivable'),
    ));
});

afterEach(fn () => Carbon::setTestNow());

it('pays a customer back against their sales return on a payment voucher', function () {
    $order = ($this->order)();
    $delivery = app(RecordDelivery::class)->handle(
        customer: $this->customer, warehouse: $this->warehouse,
        lines: [['product' => $this->product, 'quantity' => '2', 'orderLine' => $order->lines->first()]],
        createdBy: $this->owner, deliveredAt: '2026-09-13 11:00:00',
    );
    $this->actingAs($this->staff)->post("/sales/deliveries/{$delivery->id}/consignment", [
        'money_account_id' => $this->courier->id, 'consignment_number' => 'DB140926PAID', 'cod_amount' => '0',
    ]);
    $this->actingAs($this->staff)->post('/sales/consignments/'.Consignment::query()->sole()->id.'/outcome', [
        'outcome' => 'delivered', 'outcome_on' => '2026-09-14', 'collected_amount' => '0',
    ])->assertSessionHasNoErrors();

    /** @var Delivery $delivery */
    $delivery = $delivery->fresh('lines');
    $return = app(RecordSalesReturn::class)->handle(
        delivery: $delivery,
        lines: [['deliveryLine' => $delivery->lines->first(), 'quantityToStock' => '1', 'quantityWrittenOff' => '0']],
        createdBy: $this->owner,
    );
    expect($return->refundable()->minor)->toBe(85000);

    $journals = DB::table('journals')->count();
    $voucher = ($this->voucher)([['ref' => "return:{$return->id}", 'amount' => '850']], '850');

    $refund = CustomerRefund::query()->where('voucher_id', $voucher->id)->sole();

    expect($refund->sales_return_id)->toBe($return->id)
        ->and($refund->sales_order_id)->toBeNull()
        ->and($return->fresh()->refundable()->minor)->toBe(0)
        // One journal: the voucher's own, the refund posts none of its own.
        ->and(DB::table('journals')->count())->toBe($journals + 1)
        ->and(($this->reconciles)())->toBe([]);

    // Nothing more is open on the return.
    expect(fn () => ($this->voucher)([['ref' => "return:{$return->id}", 'amount' => '1']], '1'))
        ->toThrow(ValidationException::class);

    app(ReverseVoucher::class)->handle($voucher, $this->owner, 'Paid the wrong customer');

    expect($return->fresh()->refundable()->minor)->toBe(85000)
        ->and(($this->reconciles)())->toBe([]);
});

it('pays a customer back out of what their order still holds on a payment voucher', function () {
    $order = ($this->order)();
    expect($order->advanceHeld()->minor)->toBe(182000);

    $voucher = ($this->voucher)([['ref' => "order:{$order->id}", 'amount' => '500']], '500');

    $refund = CustomerRefund::query()->where('voucher_id', $voucher->id)->sole();

    expect($refund->sales_order_id)->toBe($order->id)
        ->and($refund->sales_return_id)->toBeNull()
        ->and($order->fresh()->advanceHeld()->minor)->toBe(132000)
        ->and(($this->reconciles)())->toBe([]);

    // Never more than the order still holds.
    expect(fn () => ($this->voucher)([['ref' => "order:{$order->id}", 'amount' => '1400']], '1400'))
        ->toThrow(ValidationException::class, '1,320.00');

    expect($order->fresh()->advanceHeld()->minor)->toBe(132000);
});
