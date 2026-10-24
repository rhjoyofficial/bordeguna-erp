<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\FinancePolicy;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesOrder;
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
