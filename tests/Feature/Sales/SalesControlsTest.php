<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\FinancePolicy;
use App\Modules\Foundation\Models\ActivityLogEntry;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Actions\ConfirmMarketPrice;
use App\Modules\Sales\database\seeders\PriceGroupSeeder;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Models\CreditOverride;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\PriceFloorOverride;
use App\Modules\Sales\Models\PriceGroup;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Support\CreditOverrideAuthority;
use App\Modules\Sales\Support\PriceFloor;
use App\Modules\Sales\Support\ProductPricing;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Joy's initial plan, sales controls (25 Oct 2026): B14 credit limit override
 * (Q11 = A), B15 discount % per line capped by role (Q12 = A), B16 price
 * groups and the minimum selling price (Q13, Q16 = A).
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Controls Co', 'slug' => 'controls-co']);
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
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-HONEY',
        'name' => 'Sundarban Honey 500g',
        'type' => ProductType::FinishedGood,
    ]);
    $this->customer = Customer::create([
        'business_id' => $this->business->id,
        'name' => 'Karim Traders',
        'type' => CustomerType::Shop,
        'credit_limit' => '100000',
    ]);

    ProductPrice::create([
        'business_id' => $this->business->id,
        'product_id' => $this->product->id,
        'unit_price' => '1000',
        'effective_from' => '2026-01-01',
    ]);

    Carbon::setTestNow('2026-10-25 10:00:00');

    app(RecordStockIn::class)->handle(
        $this->product, $this->warehouse, '100', '500',
        User::factory()->create(), occurredAt: '2026-10-01 09:00:00'
    );

    $this->userAs = function (string $role, string $password = 'password') {
        $user = User::factory()->create(['password' => bcrypt($password)]);
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $user;
    };

    $this->order = fn (User $user, array $lines, array $extra = []) => $this->actingAs($user)
        ->post('/sales/orders', ['customer_id' => $this->customer->id, 'lines' => $lines, ...$extra]);
});

afterEach(fn () => Carbon::setTestNow());

// ─── B15: Discount % per line, capped by role ───────────────────────────────

describe('B15 the discount per line on an order', function () {
    it('keeps a line discount inside the staff 2% on the order, without the price-override permission', function () {
        $staff = ($this->userAs)('Staff');

        // Five honey at 1,000 = Tk 5,000; 2% is Tk 100.
        ($this->order)($staff, [['product_id' => $this->product->id, 'quantity' => '5', 'discount' => '100']])
            ->assertSessionHasNoErrors();

        $line = SalesOrder::sole()->lines->sole();
        expect((string) $line->line_discount)->toBe('100.0000')
            ->and((string) $line->unit_price)->toBe('1000.0000');
    });

    it('refuses a line discount over the staff 2%, under that line, and saves nothing', function () {
        ($this->order)(($this->userAs)('Staff'), [['product_id' => $this->product->id, 'quantity' => '5', 'discount' => '101']])
            ->assertSessionHasErrors('lines.0.discount');

        expect(session('errors')->first('lines.0.discount'))->toContain('Your limit is 2% a line')
            ->and(SalesOrder::count())->toBe(0);
    });

    it('lets a Manager give 5% a line and the Owner any discount', function () {
        ($this->order)(($this->userAs)('Manager'), [['product_id' => $this->product->id, 'quantity' => '5', 'discount' => '251']])
            ->assertSessionHasErrors('lines.0.discount');
        ($this->order)(($this->userAs)('Manager'), [['product_id' => $this->product->id, 'quantity' => '5', 'discount' => '250']])
            ->assertSessionHasNoErrors();
        ($this->order)(($this->userAs)('Owner'), [['product_id' => $this->product->id, 'quantity' => '5', 'discount' => '1000']])
            ->assertSessionHasNoErrors();

        expect(SalesOrder::count())->toBe(2);
    });

    it('reads the caps from the Finance policy, which the Owner can change', function () {
        FinancePolicy::create(['business_id' => $this->business->id, 'staff_discount_cap_percent' => 4]);

        ($this->order)(($this->userAs)('Staff'), [['product_id' => $this->product->id, 'quantity' => '5', 'discount' => '200']])
            ->assertSessionHasNoErrors();
    });

    it('starts every business at staff 2% and Manager 5%', function () {
        $policy = FinancePolicy::for($this->business->id);

        expect($policy->staff_discount_cap_percent)->toBe(2)
            ->and($policy->manager_discount_cap_percent)->toBe(5);
    });

    it('changes a line discount when the order is edited, held to the same cap', function () {
        $staff = ($this->userAs)('Staff');
        ($this->order)($staff, [['product_id' => $this->product->id, 'quantity' => '5']])->assertSessionHasNoErrors();
        $order = SalesOrder::sole();
        $line = $order->lines->sole();

        $edit = fn (string $discount) => $this->actingAs($staff)->put("/sales/orders/{$order->id}", [
            'ordered_at' => '2026-10-25',
            'customer_id' => $this->customer->id,
            'lines' => [['id' => $line->id, 'product_id' => $this->product->id, 'quantity' => '5', 'discount' => $discount]],
        ]);

        $edit('150')->assertSessionHasErrors('lines');
        expect((string) $line->fresh()->line_discount)->toBe('0.0000');

        $edit('80')->assertSessionHasNoErrors();
        expect((string) $line->fresh()->line_discount)->toBe('80.0000');
    });
});

// ─── B14: Credit limit, blocked on the screen, overridden there ─────────────

describe('B14 the credit limit on the screen', function () {
    beforeEach(function () {
        $this->cashOnly = Customer::create(['business_id' => $this->business->id, 'name' => 'Rahim Store', 'type' => CustomerType::Shop]);
        $this->staff = ($this->userAs)('Staff');
        $this->manager = ($this->userAs)('Manager', 'manager-secret');
        $this->orderFor = fn (Customer $customer, array $extra = []) => $this->actingAs($this->staff)->post('/sales/orders', [
            'customer_id' => $customer->id,
            'lines' => [['product_id' => $this->product->id, 'quantity' => '2']],
            ...$extra,
        ]);
    });

    it('blocks an unpaid order for a trade customer with a blank limit: cash only, nothing saved', function () {
        ($this->orderFor)($this->cashOnly)->assertSessionHasErrors('credit');

        expect(session('errors')->first('credit'))->toContain('has no credit limit, so buys for cash only')
            ->and(SalesOrder::count())->toBe(0);
    });

    it('keeps a blank limit unchecked for a retail customer (cash on delivery)', function () {
        $retail = Customer::create(['business_id' => $this->business->id, 'name' => 'Nasima', 'type' => CustomerType::Retail]);

        ($this->orderFor)($retail)->assertSessionHasNoErrors();
        expect(SalesOrder::count())->toBe(1);
    });

    it('blocks an order over the limit and lets a Manager through with their own password and a reason, logged on the order and in History', function () {
        $this->customer->update(['credit_limit' => '1000']);

        ($this->orderFor)($this->customer)->assertSessionHasErrors('credit');
        expect(session('errors')->first('credit'))->toContain('over their credit limit of 1,000.00');

        ($this->orderFor)($this->customer, [
            'override_by' => $this->manager->id,
            'override_password' => 'manager-secret',
            'override_reason' => 'Pays every Friday',
        ])->assertSessionHasNoErrors();

        $order = SalesOrder::sole();
        $override = CreditOverride::sole();

        expect($override->sales_order_id)->toBe($order->id)
            ->and($override->method)->toBe(CreditOverride::METHOD_PASSWORD)
            ->and($override->overridden_by)->toBe($this->manager->id)
            ->and($override->entered_by)->toBe($this->staff->id)
            ->and($override->reason)->toBe('Pays every Friday')
            ->and((string) $override->owed_after)->toBe('2000.00')
            // Staff may not approve, so the order waits as any other does.
            ->and($order->approvalRequest->isPending())->toBeTrue();

        $logged = ActivityLogEntry::query()->where('subject_type', $order->getMorphClass())->where('subject_id', $order->id)
            ->where('description', 'like', 'Credit limit overridden%')->sole();
        expect($logged->causer_id)->toBe($this->manager->id)
            ->and(json_encode($logged->properties))->not->toContain('manager-secret');

        // The approval needs no second reason: the screen override covers it.
        $this->actingAs($this->manager)->post("/sales/orders/{$order->id}/approve")->assertSessionHasNoErrors();
        expect($order->fresh()->approvalRequest->isPending())->toBeFalse()
            ->and(CreditOverride::count())->toBe(1);
    });

    it('lets an Owner who overrides their own order approve it there and then', function () {
        $owner = ($this->userAs)('Owner', 'owner-secret');

        $this->actingAs($owner)->post('/sales/orders', [
            'customer_id' => $this->cashOnly->id,
            'lines' => [['product_id' => $this->product->id, 'quantity' => '2']],
            'override_by' => $owner->id,
            'override_password' => 'owner-secret',
            'override_reason' => 'Known shop, first order',
        ])->assertSessionHasNoErrors();

        expect(SalesOrder::sole()->isDeliverable())->toBeTrue()
            ->and(CreditOverride::sole()->rule)->toBe('cash_only');
    });

    it('refuses a wrong password, saves nothing, and locks the pair after five tries', function () {
        foreach (range(1, 5) as $try) {
            ($this->orderFor)($this->cashOnly, ['override_by' => $this->manager->id, 'override_password' => "guess-{$try}", 'override_reason' => 'Please let it go'])
                ->assertSessionHasErrors('override_password');
        }

        expect(session('errors')->first('override_password'))->toContain('not right');

        ($this->orderFor)($this->cashOnly, ['override_by' => $this->manager->id, 'override_password' => 'manager-secret', 'override_reason' => 'Please let it go'])
            ->assertSessionHasErrors('override_password');

        expect(session('errors')->first('override_password'))->toContain('Too many wrong passwords')
            ->and(SalesOrder::count())->toBe(0)
            ->and(CreditOverride::count())->toBe(0);
    });

    it('refuses an override by staff, or without a reason', function () {
        $other = ($this->userAs)('Staff', 'staff-secret');

        ($this->orderFor)($this->cashOnly, ['override_by' => $other->id, 'override_password' => 'staff-secret', 'override_reason' => 'I say so'])
            ->assertSessionHasErrors('override_by');
        ($this->orderFor)($this->cashOnly, ['override_by' => $this->manager->id, 'override_password' => 'manager-secret', 'override_reason' => 'ok'])
            ->assertSessionHasErrors('override_reason');

        expect(SalesOrder::count())->toBe(0);
    });

    it('offers only active Managers and Owners of this business as authorisers', function () {
        ($this->userAs)('Owner', 'x')->update(['is_active' => false]);

        $ids = collect(CreditOverrideAuthority::options($this->business->id))->pluck('id');

        expect($ids->all())->toBe([$this->manager->id]);
    });

    it('blocks a counter sale on account for a cash-only customer and lets it through with an override kept on the invoice', function () {
        $sell = fn (array $extra = []) => $this->actingAs($this->staff)->post('/sales/counter', [
            'customer_id' => $this->cashOnly->id,
            'warehouse_id' => $this->warehouse->id,
            'money_account_id' => null,
            'lines' => [['product_id' => $this->product->id, 'quantity' => '1']],
            ...$extra,
        ]);

        $sell()->assertSessionHasErrors(['credit', 'money_account_id']);
        expect(SalesInvoice::count())->toBe(0);

        $sell(['override_by' => $this->manager->id, 'override_password' => 'manager-secret', 'override_reason' => 'Pays on Friday'])
            ->assertSessionHasNoErrors();

        $invoice = SalesInvoice::sole();
        expect(CreditOverride::sole()->sales_invoice_id)->toBe($invoice->id)
            ->and(CreditOverride::sole()->sales_order_id)->toBeNull();
    });

    it('blocks a direct invoice line for a cash-only customer, and lets it through with an override', function () {
        $invoice = fn (array $extra = []) => $this->actingAs($this->staff)->post('/sales/invoices', [
            'customer_id' => $this->cashOnly->id,
            'lines' => [['description' => 'Gift wrapping', 'quantity' => '1', 'unit_price' => '300']],
            ...$extra,
        ]);

        $invoice()->assertSessionHasErrors('credit');
        expect(SalesInvoice::count())->toBe(0);

        $invoice(['override_by' => $this->manager->id, 'override_password' => 'manager-secret', 'override_reason' => 'Settles monthly'])
            ->assertSessionHasNoErrors();

        expect(CreditOverride::sole()->sales_invoice_id)->toBe(SalesInvoice::sole()->id);
    });

    it('gives the Manager role the override permission', function () {
        setPermissionsTeamId($this->business->id);

        expect($this->manager->fresh()->can('sales.credit.override'))->toBeTrue()
            ->and($this->staff->fresh()->can('sales.credit.override'))->toBeFalse();
    });
});

// ─── B16: Price groups and the minimum selling price ────────────────────────

describe('B16 the price groups', function () {
    it('renames the groups, moves Dealer customers and lone Dealer prices to Distributor, keeps the rest, and switches Dealer off', function () {
        $group = fn (string $code, string $name) => PriceGroup::create(['business_id' => $this->business->id, 'code' => $code, 'name' => $name, 'is_active' => true]);
        $retail = $group('RETAIL', 'Retail');
        $dealer = $group('DEALER', 'Dealer');
        $distributor = $group('DISTRIBUTOR', 'Distributor');
        $shop = $group('SHOP', 'Retail shop');

        $other = Product::create(['business_id' => $this->business->id, 'unit_of_measure_id' => $this->product->unit_of_measure_id, 'sku' => 'SKU-GHEE', 'name' => 'Ghee', 'type' => ProductType::FinishedGood]);
        $price = fn (Product $product, PriceGroup $g, string $amount) => ProductPrice::create(['business_id' => $this->business->id, 'product_id' => $product->id, 'price_group_id' => $g->id, 'unit_price' => $amount, 'effective_from' => '2026-01-01']);

        // Honey: Dealer only, so it moves. Ghee: both, so the Dealer row stays.
        $lone = $price($this->product, $dealer, '900');
        $kept = $price($other, $dealer, '800');
        $price($other, $distributor, '780');
        $this->customer->update(['price_group_id' => $dealer->id]);

        (require base_path('app/Modules/Sales/database/migrations/2026_10_25_280200_price_groups_become_mrp_dp_tp_corporate_and_owner.php'))->up();

        expect($retail->fresh()->name)->toBe('Retail (MRP)')
            ->and($distributor->fresh()->name)->toBe('Distributor Price (DP)')
            ->and($shop->fresh()->name)->toBe('Trade Price (TP)')
            ->and($dealer->fresh()->is_active)->toBeFalse()
            ->and($this->customer->fresh()->price_group_id)->toBe($distributor->id)
            ->and($lone->fresh()->price_group_id)->toBe($distributor->id)
            // Never deleted, never moved beside a Distributor price.
            ->and($kept->fresh()->price_group_id)->toBe($dealer->id)
            ->and(ProductPrice::count())->toBe(4);
    });

    it('prices a Dealer-type customer with no group at the Distributor price', function () {
        expect(CustomerType::Dealer->defaultPriceGroupCode())->toBe('DISTRIBUTOR');
    });
});

describe('B16 the minimum selling price', function () {
    beforeEach(function () {
        (new PriceGroupSeeder)->run();
        app(BusinessContext::class)->set($this->business->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

        $this->hilsa = Product::create([
            'business_id' => $this->business->id,
            'unit_of_measure_id' => $this->product->unit_of_measure_id,
            'sku' => 'FISH-HILSA',
            'name' => 'Hilsa',
            'type' => ProductType::FinishedGood,
            'pricing_mode' => 'market',
            'min_selling_price' => '148',
        ]);
        $this->owner = ($this->userAs)('Owner');
        app(ConfirmMarketPrice::class)->handle($this->hilsa, '150', '100', $this->owner);

        $this->buyer = Customer::create(['business_id' => $this->business->id, 'name' => 'Nasima', 'type' => CustomerType::Retail]);
        $this->fishOrder = fn (User $user, array $line, array $extra = []) => $this->actingAs($user)->post('/sales/orders', [
            'customer_id' => $this->buyer->id,
            'lines' => [['product_id' => $this->hilsa->id, 'quantity' => '1', ...$line]],
            ...$extra,
        ]);
    });

    it('refuses staff a discount that takes a market product below its minimum', function () {
        // Tk 3 off Tk 150 is inside the staff 2%, but leaves Tk 147 < Tk 148.
        ($this->fishOrder)(($this->userAs)('Staff'), ['discount' => '3'])->assertSessionHasErrors('lines.0.unit_price');

        expect(session('errors')->first('lines.0.unit_price'))->toContain('below its minimum selling price of Tk 148')
            ->and(SalesOrder::count())->toBe(0);
    });

    it('lets a Manager go below it only with a reason, and logs who, the price, the floor and why', function () {
        $manager = ($this->userAs)('Manager');

        ($this->fishOrder)($manager, ['unit_price' => '147'])->assertSessionHasErrors('floor_reason');
        expect(SalesOrder::count())->toBe(0);

        ($this->fishOrder)($manager, ['unit_price' => '147'], ['floor_reason' => 'Soft by evening'])->assertSessionHasNoErrors();

        $order = SalesOrder::sole();
        $log = PriceFloorOverride::sole();

        expect($log->document_type)->toBe($order->getMorphClass())
            ->and($log->document_id)->toBe($order->id)
            ->and((string) $log->unit_price)->toBe('147.0000')
            ->and((string) $log->floor)->toBe('148.0000')
            ->and($log->reason)->toBe('Soft by evening')
            ->and($log->allowed_by)->toBe($manager->id)
            ->and(ActivityLogEntry::query()->where('subject_id', $order->id)->where('subject_type', $order->getMorphClass())->where('description', 'like', 'Sold Hilsa below its minimum price%')->exists())->toBeTrue();
    });

    it('sells at or above the minimum without asking anything', function () {
        ($this->fishOrder)(($this->userAs)('Staff'), [])->assertSessionHasNoErrors();

        expect(PriceFloorOverride::count())->toBe(0);
    });

    it('holds today\'s market price to the floor too', function () {
        $staff = ($this->userAs)('Staff');
        $manager = ($this->userAs)('Manager');

        expect(ProductPricing::refusalFor($this->hilsa, '140', '100', 'Evening', $staff))->toContain('Only a Manager or the Owner')
            ->and(ProductPricing::refusalFor($this->hilsa, '140', '100', '', $manager))->toContain('Say why')
            ->and(ProductPricing::refusalFor($this->hilsa, '140', '100', 'Evening', $manager))->toBeNull();

        $history = app(ConfirmMarketPrice::class)->handle($this->hilsa, '140', '100', $manager, reason: 'Evening clearance');

        expect(PriceFloorOverride::sole()->document_id)->toBe($history->id);
    });

    it('has no floor for a fixed-price product, whatever is typed', function () {
        $this->product->update(['min_selling_price' => '5000']);

        expect(PriceFloor::of($this->product->fresh()))->toBeNull();
    });

    it('gives the below-the-floor permission to Owner and Manager only', function () {
        setPermissionsTeamId($this->business->id);

        expect(($this->userAs)('Manager')->can(PriceFloor::PERMISSION))->toBeTrue()
            ->and(($this->userAs)('Staff')->can(PriceFloor::PERMISSION))->toBeFalse();
    });
});
