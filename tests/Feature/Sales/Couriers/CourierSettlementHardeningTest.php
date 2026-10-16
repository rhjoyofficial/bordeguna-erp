<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Models\PartyAdjustment;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\ApproveInventoryAdjustment;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\InventoryAdjustmentType;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Actions\CreateSalesOrder;
use App\Modules\Sales\Actions\ImportCourierStatement;
use App\Modules\Sales\Actions\PostCourierStatement;
use App\Modules\Sales\Actions\RecordDelivery;
use App\Modules\Sales\Couriers\Enums\CourierStatus;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Enums\ConsignmentStatus;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Enums\DoorResolution;
use App\Modules\Sales\Enums\SalesChannel;
use App\Modules\Sales\Models\Consignment;
use App\Modules\Sales\Models\CourierDoorDiscountRequest;
use App\Modules\Sales\Models\CourierLossClaim;
use App\Modules\Sales\Models\CourierStatement;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Support\CourierStatements\CourierStatementReview;
use App\Modules\Sales\Support\SalesOrderOrigin;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Courier hub slice 8: the outcomes the ERP used to refuse. A difference at the
 * door settled by a named resolution (ruling 3), a paid return as delivery
 * income (ruling 2), a lost or damaged parcel claimed and written off (ruling
 * 5), cut-off into the first open day (risk 9), the statement equation (risk
 * 4), and technical vs accounting authority (risk 11).
 *
 * The jar: list 850, cost 500. Two jars and Tk 120 delivery owe 1,820.
 */
beforeEach(function () {
    Storage::fake('local');

    $this->business = Business::create(['name' => 'Door Co', 'slug' => 'door-co']);
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

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $this->product = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-GHEE', 'name' => 'Royal Essence Ghee 400g', 'type' => ProductType::FinishedGood,
    ]);
    $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'Web buyer', 'type' => CustomerType::Retail]);
    ProductPrice::create(['business_id' => $this->business->id, 'product_id' => $this->product->id, 'unit_price' => '850', 'effective_from' => '2026-01-01']);

    Carbon::setTestNow('2026-09-14 10:00:00');

    $user = function (string $role) {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $user;
    };

    $this->owner = $user('Owner');
    $this->manager = $user('Manager');
    $this->staff = $user('Staff');

    app(RecordStockIn::class)->handle($this->product, $this->warehouse, '100', '500', $this->owner, occurredAt: '2026-09-01 09:00:00');

    $this->courier = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'CarryBee', 'type' => MoneyAccountType::Courier,
        'provider' => 'CarryBee', 'account_id' => Account::bySlug('courier_receivable')->id, 'is_active' => true,
    ]);
    $this->bank = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'BRAC Bank', 'type' => MoneyAccountType::Bank,
        'account_id' => Account::bySlug('cash_at_bank')->id, 'is_active' => true,
    ]);

    // A booked parcel of `$quantity` jars.
    $this->parcel = function (string $quantity = '2', ?string $deliveryCharge = '120', string $orderDiscount = '0', ?string $cod = null, string $number = 'CB1001'): Consignment {
        $order = app(CreateSalesOrder::class)->handle(
            customer: $this->customer,
            lines: [['product' => $this->product, 'quantity' => $quantity]],
            createdBy: $this->owner,
            origin: new SalesOrderOrigin(channel: SalesChannel::Internal, deliveryCharge: $deliveryCharge, orderDiscount: $orderDiscount),
        );
        $delivery = app(RecordDelivery::class)->handle(
            customer: $this->customer,
            warehouse: $this->warehouse,
            lines: [['product' => $this->product, 'quantity' => $quantity, 'orderLine' => $order->lines->first()]],
            createdBy: $this->owner,
            deliveredAt: '2026-09-13 11:00:00',
        );

        $this->actingAs($this->owner)->post("/sales/deliveries/{$delivery->id}/consignment", [
            'money_account_id' => $this->courier->id,
            'consignment_number' => $number,
            'cod_amount' => $cod ?? '1820',
        ])->assertSessionHasNoErrors();

        return Consignment::query()->where('consignment_number', $number)->sole();
    };

    $this->report = fn (User $by, Consignment $consignment, string $outcome, string $collected, array $extra = []) => $this->actingAs($by)
        ->post("/sales/consignments/{$consignment->id}/outcome", [
            'outcome' => $outcome,
            'outcome_on' => '2026-09-14',
            'collected_amount' => $collected,
            ...$extra,
        ]);

    $this->posted = fn (string $slug, ?MoneyAccount $moneyAccount = null) => (int) JournalLine::query()
        ->where('account_id', Account::bySlug($slug)->id)
        ->when($moneyAccount !== null, fn ($query) => $query->where('money_account_id', $moneyAccount->id))
        ->sum('amount_minor');

    $this->stock = fn () => (float) $this->product->fresh()->currentStockQuantity();
});

afterEach(fn () => Carbon::setTestNow());

// --- 1. A difference at the door -------------------------------------------

it('records a door discount within the cap as a discount allowed against the invoice', function () {
    $consignment = ($this->parcel)();

    // Tk 20 off 1,700 of goods: inside a Manager's 3%.
    ($this->report)($this->manager, $consignment, 'delivered', '1800', ['resolution' => 'discount', 'door_reason' => 'Customer haggled at the door'])
        ->assertSessionHasNoErrors();

    $consignment->refresh();
    $invoice = SalesInvoice::query()->sole();
    $adjustment = PartyAdjustment::query()->sole();

    expect($consignment->status)->toBe(ConsignmentStatus::Delivered)
        ->and($consignment->door_resolution)->toBe(DoorResolution::Discount)
        ->and((float) $consignment->door_difference)->toBe(20.0)
        ->and($consignment->door_reason)->toBe('Customer haggled at the door')
        ->and($consignment->party_adjustment_id)->toBe($adjustment->id)
        ->and((float) $invoice->total)->toBe(1820.0)
        ->and($invoice->outstanding()->minor)->toBe(0)
        ->and((float) CustomerReceipt::query()->sole()->amount)->toBe(1800.0)
        ->and($adjustment->kind)->toBe('customer_discount')
        ->and(($this->posted)('discounts_allowed'))->toBe(2000)
        ->and(($this->posted)('courier_receivable', $this->courier))->toBe(180000)
        ->and(($this->posted)('accounts_receivable'))->toBe(0);
});

it('sends a door discount above the cap to the Owner with nothing posted, and records it on approval', function () {
    $consignment = ($this->parcel)();

    // Tk 120 off 1,700: above a Manager's 3% (51).
    ($this->report)($this->manager, $consignment, 'delivered', '1700', ['resolution' => 'discount', 'door_reason' => 'Jar lid dented, customer asked'])
        ->assertSessionHasNoErrors();

    $request = CourierDoorDiscountRequest::query()->sole();

    expect($consignment->fresh()->status)->toBe(ConsignmentStatus::Booked)
        ->and(SalesInvoice::query()->count())->toBe(0)
        ->and(Journal::query()->where('source_type', (new Consignment)->getMorphClass())->count())->toBe(0)
        ->and((float) $request->discount_amount)->toBe(120.0)
        ->and($request->approvalRequest->status)->toBe(ApprovalStatus::Pending);

    // Nobody records it another way while it waits, and a Manager cannot approve it.
    ($this->report)($this->manager, $consignment, 'delivered', '1700', ['resolution' => 'short_collection', 'door_reason' => 'try'])
        ->assertSessionHasErrors('outcome');
    $this->actingAs($this->manager)->post("/sales/courier-door-discounts/{$request->id}/approve")->assertSessionHasErrors('approval');

    // It is in the Owner's approvals inbox.
    $cards = collect($this->actingAs($this->owner)->get('/approvals')->original->getData()['page']['props']['requests'] ?? []);
    expect($cards->pluck('type'))->toContain('Door discount');

    $this->actingAs($this->owner)->post("/sales/courier-door-discounts/{$request->id}/approve")->assertSessionHasNoErrors();

    $consignment->refresh();

    expect($consignment->status)->toBe(ConsignmentStatus::Delivered)
        ->and($consignment->door_resolution)->toBe(DoorResolution::Discount)
        ->and((float) $consignment->door_difference)->toBe(120.0)
        ->and($request->fresh()->applied_at)->not->toBeNull()
        ->and($request->fresh()->approvalRequest->status)->toBe(ApprovalStatus::Approved)
        ->and(($this->posted)('discounts_allowed'))->toBe(12000)
        ->and(SalesInvoice::query()->sole()->outstanding()->minor)->toBe(0);
});

it('tells the order page a parcel waits for the Owner on a door discount, not for the courier answer', function () {
    $consignment = ($this->parcel)();

    ($this->report)($this->manager, $consignment, 'delivered', '1700', ['resolution' => 'discount', 'door_reason' => 'Jar lid dented, customer asked'])
        ->assertSessionHasNoErrors();

    $order = SalesOrder::query()->sole();
    $steps = fn (User $as) => $this->actingAs($as)->get(route('sales.orders.show', $order))->original->getData()['page']['props']['next_steps'];

    $owner = $steps($this->owner);
    $manager = $steps($this->manager);

    expect(collect($owner)->pluck('kind')->all())->toBe(['door_discount'])
        ->and($owner[0]['can'])->toBeTrue()
        ->and($owner[0]['href'])->toBe(route('approvals.index'))
        ->and($manager[0]['kind'])->toBe('door_discount')
        ->and($manager[0]['can'])->toBeFalse();
});

it('lists a parcel waiting on the Owner for a door discount as waiting for the Owner', function () {
    $waiting = ($this->parcel)();
    $other = ($this->parcel)(number: 'CB1002');

    ($this->report)($this->manager, $waiting, 'delivered', '1700', ['resolution' => 'discount', 'door_reason' => 'Jar lid dented, customer asked'])
        ->assertSessionHasNoErrors();

    $rows = collect($this->actingAs($this->owner)->get(route('sales.consignments.index'))
        ->original->getData()['page']['props']['consignments']['data'])->keyBy('consignment_number');

    expect($rows['CB1001']['waiting_owner'])->toBeTrue()
        ->and($rows['CB1002']['waiting_owner'])->toBeFalse();
});

it('leaves the parcel open when the Owner rejects the door discount', function () {
    $consignment = ($this->parcel)();
    ($this->report)($this->manager, $consignment, 'delivered', '1700', ['resolution' => 'discount', 'door_reason' => 'Asked for less']);
    $request = CourierDoorDiscountRequest::query()->sole();

    $this->actingAs($this->owner)->post("/sales/courier-door-discounts/{$request->id}/reject", ['review_note' => 'No'])->assertSessionHasNoErrors();

    expect($consignment->fresh()->status)->toBe(ConsignmentStatus::Booked)
        ->and($request->fresh()->approvalRequest->status)->toBe(ApprovalStatus::Rejected);

    // The answer can now be recorded another way.
    ($this->report)($this->manager, $consignment, 'delivered', '1700', ['resolution' => 'short_collection', 'door_reason' => 'Customer pays the rest by bKash'])
        ->assertSessionHasNoErrors();
    expect($consignment->fresh()->status)->toBe(ConsignmentStatus::Delivered);
});

it('bills a corrected price with the price trail, for someone who may change prices', function () {
    $consignment = ($this->parcel)();
    $lineId = $consignment->delivery->lines()->first()->id;

    ($this->report)($this->manager, $consignment, 'delivered', '1800', [
        'resolution' => 'price_correction',
        'door_reason' => 'Order typed at the old price of 840',
        'prices' => [['delivery_line_id' => $lineId, 'unit_price' => '840']],
    ])->assertSessionHasNoErrors();

    $invoice = SalesInvoice::query()->with('lines')->sole();
    $goods = $invoice->lines->firstWhere('delivery_line_id', $lineId);

    expect((float) $invoice->total)->toBe(1800.0)
        ->and($invoice->outstanding()->minor)->toBe(0)
        ->and((float) $goods->unit_price)->toBe(840.0)
        ->and((float) $goods->list_price)->toBe(850.0)
        ->and($goods->override_reason)->toBe('Order typed at the old price of 840')
        ->and($goods->overridden_by)->toBe($this->manager->id)
        ->and($consignment->fresh()->door_resolution)->toBe(DoorResolution::PriceCorrection)
        ->and(PartyAdjustment::query()->count())->toBe(0);
});

it('holds a Manager price correction above the cap for the Owner, and bills the corrected price on approval', function () {
    $consignment = ($this->parcel)();
    $lineId = $consignment->delivery->lines()->first()->id;

    // 850 -> 790 on two units is Tk 120 off 1,700 of goods: above a Manager's 3% (51).
    ($this->report)($this->manager, $consignment, 'delivered', '1700', [
        'resolution' => 'price_correction',
        'door_reason' => 'Agreed 790 on the phone',
        'prices' => [['delivery_line_id' => $lineId, 'unit_price' => '790']],
    ])->assertSessionHasNoErrors();

    $request = CourierDoorDiscountRequest::query()->sole();

    expect($consignment->fresh()->status)->toBe(ConsignmentStatus::Booked)
        ->and(SalesInvoice::query()->count())->toBe(0)
        ->and((float) $request->discount_amount)->toBe(120.0)
        ->and($request->payload['resolution'])->toBe('price_correction');

    $this->actingAs($this->owner)->post("/sales/courier-door-discounts/{$request->id}/approve")->assertSessionHasNoErrors();

    $invoice = SalesInvoice::query()->with('lines')->sole();
    $goods = $invoice->lines->firstWhere('delivery_line_id', $lineId);

    expect($consignment->fresh()->status)->toBe(ConsignmentStatus::Delivered)
        ->and($consignment->fresh()->door_resolution)->toBe(DoorResolution::PriceCorrection)
        ->and((float) $goods->unit_price)->toBe(790.0)
        ->and((float) $goods->list_price)->toBe(850.0)
        ->and((float) $invoice->total)->toBe(1700.0)
        ->and($invoice->outstanding()->minor)->toBe(0)
        ->and($request->fresh()->applied_at)->not->toBeNull();
});

it('lets the Owner correct a price beyond any cap directly', function () {
    $consignment = ($this->parcel)();
    $lineId = $consignment->delivery->lines()->first()->id;

    ($this->report)($this->owner, $consignment, 'delivered', '1700', [
        'resolution' => 'price_correction',
        'door_reason' => 'Owner agreed 790',
        'prices' => [['delivery_line_id' => $lineId, 'unit_price' => '790']],
    ])->assertSessionHasNoErrors();

    expect(CourierDoorDiscountRequest::query()->count())->toBe(0)
        ->and($consignment->fresh()->status)->toBe(ConsignmentStatus::Delivered);
});

it('refuses a corrected price that still does not match what was collected', function () {
    $consignment = ($this->parcel)();
    $lineId = $consignment->delivery->lines()->first()->id;

    ($this->report)($this->manager, $consignment, 'delivered', '1800', [
        'resolution' => 'price_correction', 'door_reason' => 'Wrong price',
        'prices' => [['delivery_line_id' => $lineId, 'unit_price' => '845']],
    ])->assertSessionHasErrors('collected_amount');

    expect($consignment->fresh()->status)->toBe(ConsignmentStatus::Booked)->and(SalesInvoice::query()->count())->toBe(0);
});

it('leaves the rest of a short collection on the customer', function () {
    $consignment = ($this->parcel)();

    ($this->report)($this->staff, $consignment, 'delivered', '1500', ['resolution' => 'short_collection', 'door_reason' => 'Customer had only 1,500'])
        ->assertSessionHasNoErrors();

    $invoice = SalesInvoice::query()->sole();

    expect((float) $invoice->total)->toBe(1820.0)
        ->and($invoice->outstanding()->minor)->toBe(32000)
        ->and((float) CustomerReceipt::query()->sole()->amount)->toBe(1500.0)
        ->and(($this->posted)('accounts_receivable'))->toBe(32000)
        ->and(($this->posted)('courier_receivable', $this->courier))->toBe(150000)
        ->and($consignment->fresh()->door_resolution)->toBe(DoorResolution::ShortCollection)
        ->and(PartyAdjustment::query()->count())->toBe(0);
});

it('writes off paisa the courier did not collect as rounding', function () {
    // 1,700 of goods and Tk 120.40 delivery owe 1,820.40; the courier took 1,820.
    $consignment = ($this->parcel)(deliveryCharge: '120.40', cod: '1820');

    ($this->report)($this->staff, $consignment, 'delivered', '1820', ['resolution' => 'rounding', 'door_reason' => 'COD in whole taka'])
        ->assertSessionHasNoErrors();

    expect(SalesInvoice::query()->sole()->outstanding()->minor)->toBe(0)
        ->and(PartyAdjustment::query()->sole()->kind)->toBe('rounding')
        ->and(($this->posted)('rounding_difference'))->toBe(40)
        ->and(($this->posted)('courier_receivable', $this->courier))->toBe(182000);
});

it('books paisa collected over the invoice as a rounding gain the courier holds', function () {
    $consignment = ($this->parcel)(deliveryCharge: '120.40', cod: '1821');

    ($this->report)($this->staff, $consignment, 'delivered', '1821', ['resolution' => 'rounding', 'door_reason' => 'COD rounded up'])
        ->assertSessionHasNoErrors();

    expect((float) CustomerReceipt::query()->sole()->amount)->toBe(1820.40)
        ->and(SalesInvoice::query()->sole()->outstanding()->minor)->toBe(0)
        ->and(($this->posted)('courier_receivable', $this->courier))->toBe(182100)
        ->and(($this->posted)('rounding_difference'))->toBe(-60)
        ->and((float) $consignment->fresh()->collected_amount)->toBe(1821.0);
});

it('refuses rounding of a taka or more, a difference with no resolution, and a resolution with no reason', function () {
    $consignment = ($this->parcel)();

    ($this->report)($this->staff, $consignment, 'delivered', '1818', ['resolution' => 'rounding', 'door_reason' => 'COD'])->assertSessionHasErrors('collected_amount');
    ($this->report)($this->staff, $consignment, 'delivered', '1818')->assertSessionHasErrors('collected_amount');
    ($this->report)($this->staff, $consignment, 'delivered', '1818', ['resolution' => 'short_collection'])->assertSessionHasErrors('door_reason');

    expect($consignment->fresh()->status)->toBe(ConsignmentStatus::Booked)
        ->and(SalesInvoice::query()->count())->toBe(0)
        ->and(CustomerReceipt::query()->count())->toBe(0);
});

it('settles a partial delivery whose share of the order discount leaves paisa', function () {
    // Three jars, Tk 100 off the order, no delivery charge. Two taken: 1,700
    // less 66.67 of the discount owes 1,633.33; the courier took 1,633.
    $consignment = ($this->parcel)('3', null, '100', '2450');
    $lineId = $consignment->delivery->lines()->first()->id;

    ($this->report)($this->staff, $consignment, 'partially_delivered', '1633', [
        'lines' => [['delivery_line_id' => $lineId, 'taken' => '2', 'written_off' => '0']],
        'resolution' => 'rounding', 'door_reason' => 'COD in whole taka',
    ])->assertSessionHasNoErrors();

    $invoice = SalesInvoice::query()->sole();

    expect((float) $invoice->total)->toBe(1633.33)
        ->and($invoice->outstanding()->minor)->toBe(0)
        ->and(($this->posted)('rounding_difference'))->toBe(33)
        ->and(SalesReturn::query()->count())->toBe(1)
        ->and(($this->stock)())->toBe(98.0);
});

// --- 2. Paid return -----------------------------------------------------------

it('brings a paid return back to stock and books the money as delivery income the courier holds', function () {
    $consignment = ($this->parcel)();
    $consignment->forceFill(['courier_status' => CourierStatus::PaidReturn, 'courier_collected_amount' => '120'])->save();

    // The courier prompt offers the paid return itself.
    $challans = $this->actingAs($this->staff)->get('/sales/orders/'.$consignment->delivery->lines()->first()->orderLine->sales_order_id)
        ->original->getData()['page']['props']['challans'];
    expect($challans[0]['consignment']['courier_info']['prompt']['outcome'])->toBe('paid_return');

    ($this->report)($this->staff, $consignment, 'paid_return', '120')->assertSessionHasNoErrors();

    $journal = Journal::query()->where('source_type', $consignment->getMorphClass())->where('source_id', $consignment->id)->sole();

    expect($consignment->fresh()->status)->toBe(ConsignmentStatus::PaidReturn)
        ->and(SalesInvoice::query()->count())->toBe(0)
        ->and(SalesReturn::query()->count())->toBe(1)
        ->and(($this->stock)())->toBe(100.0)
        ->and(($this->posted)('courier_receivable', $this->courier))->toBe(12000)
        ->and(($this->posted)('delivery_income'))->toBe(-12000)
        ->and(($this->posted)('sales_revenue'))->toBe(0)
        ->and(($this->posted)('goods_delivered_not_invoiced'))->toBe(0)
        ->and($journal->lines()->where('money_account_id', $this->courier->id)->count())->toBe(1);
});

it('refuses a paid return that collected nothing, and a plain return that collected money', function () {
    $consignment = ($this->parcel)();

    ($this->report)($this->staff, $consignment, 'paid_return', '0')->assertSessionHasErrors('collected_amount');
    ($this->report)($this->staff, $consignment, 'returned', '120')->assertSessionHasErrors('collected_amount');

    expect($consignment->fresh()->status)->toBe(ConsignmentStatus::Booked);
});

// --- 3. Lost and damaged parcels ----------------------------------------------

it('writes off a lost parcel through the adjustment workflow, and closes the claim when a statement pays compensation', function () {
    $consignment = ($this->parcel)();

    $this->actingAs($this->owner)->post("/sales/consignments/{$consignment->id}/loss", [
        'kind' => 'lost', 'reported_on' => '2026-09-14', 'claimed_amount' => '1700', 'notes' => 'Hub says missing',
    ])->assertSessionHasNoErrors();

    $claim = CourierLossClaim::query()->sole();
    $adjustment = InventoryAdjustment::query()->sole();

    expect($consignment->fresh()->status)->toBe(ConsignmentStatus::Lost)
        ->and($claim->status)->toBe(CourierLossClaim::OPEN)
        ->and((float) $claim->value_at_cost)->toBe(1000.0)
        ->and((float) $claim->claimed_amount)->toBe(1700.0)
        ->and($claim->money_account_id)->toBe($this->courier->id)
        ->and(SalesInvoice::query()->count())->toBe(0)
        ->and($adjustment->type)->toBe(InventoryAdjustmentType::LostOrStolen)
        ->and((float) $adjustment->quantity)->toBe(2.0)
        ->and($adjustment->source_type)->toBe(CourierLossClaim::class)
        ->and($adjustment->source_id)->toBe($claim->id)
        // Back on paper, waiting for the write-off's approver.
        ->and(($this->posted)('goods_delivered_not_invoiced'))->toBe(0);

    app(ApproveInventoryAdjustment::class)->handle($adjustment, $this->owner, 'Checked it myself.');

    expect(($this->stock)())->toBe(98.0);

    // CarryBee pays Tk 1,000 for it and charged Tk 100 to carry it.
    $csv = "Consignment_id,merchant_order_id,Recipient Name,Recipient Phone,Collectable Amount,Collected Amount,COD_fee,Delivery_fee,Additional_charge,Discount,Total Fee,Compensation,Adjustment,Billing_amount,Weight,Delivery_status,Delivered_by_hub_name,Created_date,Delivered_date,Invoiced_date,Invoice_number,Payout_method,Payment_status,Store_name\n"
        ."CB1001,,Rahima Test,01799999999,0.00,0.00,0.00,100.00,0.00,0.00,100.00,1000.00,0.00,900.00,0.70,lost,Test Hub,2026-09-13,2026-09-14,2026-09-14,ILOST1,bank,paid,Test Store\n";

    $statement = app(ImportCourierStatement::class)->handle(courier: $this->courier, reference: null, statementDate: '2026-09-14', contents: $csv, filename: 'carrybee.csv', createdBy: $this->owner);
    app(PostCourierStatement::class)->handle($statement, $this->bank, '900', '2026-09-14', $this->owner);

    $claim->refresh();

    expect($claim->status)->toBe(CourierLossClaim::PAID)
        ->and((float) $claim->compensation_received)->toBe(1000.0)
        ->and($claim->courier_statement_line_id)->toBe($statement->lines()->sole()->id)
        ->and(($this->posted)('courier_compensation_income', $this->courier))->toBe(-100000);
});

it('records compensation paid straight into a bank against 4960 naming the courier, and closes the claim', function () {
    $consignment = ($this->parcel)();

    $this->actingAs($this->owner)->post("/sales/consignments/{$consignment->id}/loss", [
        'kind' => 'damaged', 'reported_on' => '2026-09-14', 'claimed_amount' => '1000',
    ])->assertSessionHasNoErrors();

    $claim = CourierLossClaim::query()->sole();

    expect($consignment->fresh()->status)->toBe(ConsignmentStatus::Damaged)
        ->and(InventoryAdjustment::query()->sole()->type)->toBe(InventoryAdjustmentType::Damaged);

    // Staff record goods, not money a courier pays.
    $this->actingAs($this->staff)->post("/sales/courier-claims/{$claim->id}/decision", ['decision' => 'accepted'])->assertSessionHasNoErrors();
    expect($claim->fresh()->status)->toBe(CourierLossClaim::ACCEPTED);

    $this->actingAs($this->owner)->post("/sales/courier-claims/{$claim->id}/compensation", [
        'money_account_id' => $this->bank->id, 'amount' => '800', 'received_on' => '2026-09-14', 'reference' => 'CB-COMP-9',
    ])->assertSessionHasNoErrors();

    $journal = Journal::query()->where('source_type', $claim->getMorphClass())->where('source_id', $claim->id)->sole();
    $income = $journal->lines()->where('account_id', Account::bySlug('courier_compensation_income')->id)->sole();

    expect($claim->fresh()->status)->toBe(CourierLossClaim::PAID)
        ->and((float) $claim->fresh()->compensation_received)->toBe(800.0)
        ->and($income->amount_minor)->toBe(-80000)
        ->and($income->money_account_id)->toBe($this->courier->id)
        ->and(($this->posted)('cash_at_bank', $this->bank))->toBe(80000);

    // A closed claim takes no second payment.
    $this->actingAs($this->owner)->post("/sales/courier-claims/{$claim->id}/compensation", [
        'money_account_id' => $this->bank->id, 'amount' => '100', 'received_on' => '2026-09-14',
    ])->assertSessionHasErrors('claim');
});

// --- 4. Cut-off -------------------------------------------------------------------

it('posts a courier answer dated into a closed period on the first open day, with a note', function () {
    $consignment = ($this->parcel)();

    AccountingPeriod::closeThrough(Carbon::parse('2026-09-13'), $this->owner);
    $lockedJournals = Journal::query()->whereDate('journal_date', '<=', '2026-09-13')->count();

    $this->actingAs($this->staff)->post("/sales/consignments/{$consignment->id}/outcome", [
        'outcome' => 'delivered', 'outcome_on' => '2026-09-13', 'collected_amount' => '1820',
    ])->assertSessionHasNoErrors();

    $consignment->refresh();
    $invoice = SalesInvoice::query()->sole();

    expect($consignment->outcome_on->toDateString())->toBe('2026-09-13')
        ->and($consignment->posted_on->toDateString())->toBe('2026-09-14')
        ->and($consignment->cutoff_note)->toContain('closed period')
        ->and($invoice->invoice_date->toDateString())->toBe('2026-09-14')
        // Nothing new in the locked month.
        ->and(Journal::query()->whereDate('journal_date', '<=', '2026-09-13')->count())->toBe($lockedJournals);

    $challans = $this->actingAs($this->staff)->get('/sales/orders/'.$consignment->delivery->lines()->first()->orderLine->sales_order_id)
        ->original->getData()['page']['props'];
    expect($challans['challans'][0]['consignment']['settlement']['cutoff_note'])->toContain('posted on 2026-09-14')
        ->and($challans['books_open_from'])->toBe('2026-09-14');
});

// --- 5. Statement equation -------------------------------------------------------

it('shows the reconciliation equation and refuses to post a share that does not add up', function () {
    $consignment = ($this->parcel)();
    ($this->report)($this->staff, $consignment, 'delivered', '1820')->assertSessionHasNoErrors();

    $csv = "Consignment_id,merchant_order_id,Recipient Name,Recipient Phone,Collectable Amount,Collected Amount,COD_fee,Delivery_fee,Additional_charge,Discount,Total Fee,Compensation,Adjustment,Billing_amount,Weight,Delivery_status,Delivered_by_hub_name,Created_date,Delivered_date,Invoiced_date,Invoice_number,Payout_method,Payment_status,Store_name\n"
        ."CB1001,,Rahima Test,01799999999,1820.00,1820.00,18.20,100.00,0.00,10.00,108.20,0.00,0.00,1711.80,0.70,delivered,Test Hub,2026-09-13,2026-09-14,2026-09-14,IEQ1,bank,paid,Test Store\n";
    $statement = app(ImportCourierStatement::class)->handle(courier: $this->courier, reference: null, statementDate: '2026-09-14', contents: $csv, filename: 'carrybee.csv', createdBy: $this->owner);

    $equations = CourierStatementReview::of($statement->fresh())->equations();

    expect($equations['file']['balanced'])->toBeTrue()
        ->and($equations['file']['collected'])->toBe('1820.00')
        ->and($equations['file']['net_fees'])->toBe('90.00')
        ->and($equations['file']['cod_fees'])->toBe('18.20')
        ->and($equations['file']['expected'])->toBe('1711.80')
        ->and($equations['share']['payout'])->toBe('1711.80');

    // A payout column that no longer adds up is flagged and blocks posting.
    $statement->lines()->sole()->forceFill(['payout' => '1700.00'])->save();
    $review = CourierStatementReview::of($statement->fresh());

    expect($review->equations()['share']['balanced'])->toBeFalse()
        ->and($review->equations()['share']['difference'])->toBe('-11.80')
        ->and($review->canPost())->toBeFalse()
        ->and(implode(' ', $review->problems))->toContain('do not add up');

    expect(fn () => app(PostCourierStatement::class)->handle($statement->fresh(), $this->bank, '1700', '2026-09-14', $this->owner))
        ->toThrow(ValidationException::class);

    expect($statement->fresh()->status)->toBe(CourierStatement::DRAFT);

    $page = $this->actingAs($this->owner)->get("/sales/courier-statements/{$statement->id}")->original->getData()['page']['props'];
    expect($page['equations']['share']['balanced'])->toBeFalse();
});

// --- 6. Technical vs accounting authority ----------------------------------------

it('gives booking permission no accounting authority: no discount, no price correction', function () {
    $consignment = ($this->parcel)();
    $lineId = $consignment->delivery->lines()->first()->id;

    // Booking alone cannot reach the outcome at all.
    $booker = User::factory()->create();
    $booker->businesses()->attach($this->business->id);
    $booker->givePermissionTo('couriers.parcels.book', 'couriers.parcels.view');

    ($this->report)($booker, $consignment, 'delivered', '1800', ['resolution' => 'discount', 'door_reason' => 'x x x'])->assertForbidden();

    // Staff record parcels, but may not give a discount or change a price:
    // the price change is refused, the discount waits for the Owner.
    ($this->report)($this->staff, $consignment, 'delivered', '1800', [
        'resolution' => 'price_correction', 'door_reason' => 'Wrong price',
        'prices' => [['delivery_line_id' => $lineId, 'unit_price' => '840']],
    ])->assertSessionHasErrors('resolution');

    ($this->report)($this->staff, $consignment, 'delivered', '1800', ['resolution' => 'discount', 'door_reason' => 'Small discount'])
        ->assertSessionHasNoErrors();

    expect($consignment->fresh()->status)->toBe(ConsignmentStatus::Booked)
        ->and(SalesInvoice::query()->count())->toBe(0)
        ->and(CourierDoorDiscountRequest::query()->count())->toBe(1)
        ->and(PartyAdjustment::query()->count())->toBe(0);

    // Loss claims are goods coming back: not for someone who may only book.
    $this->actingAs($booker)->post("/sales/consignments/{$consignment->id}/loss", ['kind' => 'lost', 'reported_on' => '2026-09-14', 'claimed_amount' => '0'])->assertForbidden();
});
