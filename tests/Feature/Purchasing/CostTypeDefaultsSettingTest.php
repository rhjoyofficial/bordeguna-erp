<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Foundation\database\seeders\ApprovalWorkflowSeeder;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Actions\PostLandedCost;
use App\Modules\Purchasing\Actions\RecordGoodsReceipt;
use App\Modules\Purchasing\Actions\SaveLandedCost;
use App\Modules\Purchasing\Actions\SubmitLandedCost;
use App\Modules\Purchasing\Enums\LandedCostTreatment;
use App\Modules\Purchasing\Enums\LandedCostType;
use App\Modules\Purchasing\Models\PurchaseCostTypeDefault;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Support\CostTypeDefaults;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Joy's answer 3 (25 Oct 2026): every purchase cost type's default (stock
 * cost or expense, with the head) is the Owner's to set on Finance policy;
 * every purchase screen reads it, and the per-line switch stays.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-25 10:00:00');

    $this->business = Business::create(['name' => 'Defaults Co', 'slug' => 'defaults-co', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $grants = ['purchasing.landed_cost.view', 'purchasing.landed_cost.manage', 'purchasing.landed_cost.post', 'purchasing.po.approve', 'accounting.policy.manage'];

    foreach ($grants as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $owner = Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    $owner->givePermissionTo($grants);
    Role::firstOrCreate(['name' => 'Staff', 'business_id' => $this->business->id, 'guard_name' => 'web']);

    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->user->assignRole($owner);

    $this->staff = User::factory()->create();
    $this->staff->businesses()->attach($this->business->id);
    $this->staff->assignRole('Staff');

    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(InventoryAccountMappingSeeder::class);
    (new ApprovalWorkflowSeeder)->run();
    TenantScope::enter($this->business->id);
    CostTypeDefaults::forget();

    $kg = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Kilogram', 'abbreviation' => 'kg']);
    $warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Savar', 'code' => 'SAV']);
    $salt = Product::create(['business_id' => $this->business->id, 'unit_of_measure_id' => $kg->id, 'sku' => 'SALT', 'name' => 'Salt', 'type' => ProductType::RawMaterial]);
    $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'Chattogram Salt', 'code' => 'SUP-001']);

    $order = app(CreatePurchaseOrder::class)->handle(supplier: $this->supplier, warehouse: $warehouse, lines: [['product' => $salt, 'quantity' => '25', 'unit_cost' => '20.00']], createdBy: $this->user);
    approveIfPending($order->approvalRequest, $this->user);
    $order = $order->fresh(['lines', 'approvalRequest']);
    $this->receipt = app(RecordGoodsReceipt::class)->handle($order, [['line' => $order->lines->first(), 'quantity' => '25']], $this->user, receivedOn: Carbon::parse('2026-10-20'), backdateReason: 'test')->fresh('lines');

    $this->line = fn (string $type, string $amount, array $extra = []) => $extra + [
        'cost_type' => $type, 'treatment' => null, 'override_reason' => null, 'allocation_method' => 'value',
        'goods_receipt_line_id' => null, 'amount' => $amount, 'supplier_id' => $this->supplier->id,
        'money_account_id' => null, 'expense_account_id' => null, 'description' => null,
    ];
    $this->balanceOnCode = fn (string $code) => (int) JournalLine::where('account_id', Account::where('code', $code)->firstOrFail()->id)->sum('amount_minor');
});

afterEach(fn () => Carbon::setTestNow());

it('starts every type at stock cost and lists all twelve on Finance policy', function () {
    foreach (LandedCostType::cases() as $type) {
        expect($type->defaultTreatment())->toBe(LandedCostTreatment::Capitalize);
    }

    $this->actingAs($this->user)->get('/accounting/finance-policy')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('cost_types', 12)->where('cost_types.0.treatment', 'capitalize')->has('expense_accounts')->etc());
});

it('lets the Owner make feeding an expense under a named head, and a new landed cost follows it', function () {
    $head = Account::where('code', '6096')->firstOrFail();

    $this->actingAs($this->user)
        ->put(route('accounting.finance-policy.cost-types.update', 'feeding'), ['treatment' => 'expense', 'expense_account_id' => $head->id])
        ->assertSessionHasNoErrors();

    expect(LandedCostType::Feeding->defaultTreatment())->toBe(LandedCostTreatment::Expense)
        ->and(LandedCostType::Feeding->defaultExpenseAccountId())->toBe($head->id)
        ->and(LandedCostType::Freight->defaultTreatment())->toBe(LandedCostTreatment::Capitalize);

    // Saved with no treatment typed: the Owner's default applies, no reason needed, the head fills in.
    $lc = app(SaveLandedCost::class)->handle($this->receipt, '2026-10-21', [($this->line)('feeding', '300')], $this->user);
    $line = $lc->lines->first();

    expect($line->treatment)->toBe(LandedCostTreatment::Expense)
        ->and($line->default_treatment)->toBe(LandedCostTreatment::Expense)
        ->and($line->overridesDefault())->toBeFalse()
        ->and((int) $line->expense_account_id)->toBe($head->id);

    $lc = app(SubmitLandedCost::class)->handle($lc, $this->user);
    app(PostLandedCost::class)->handle($lc->fresh(['approvalRequest', 'lines']), $this->user);

    expect(($this->balanceOnCode)('6096'))->toBe(30000);

    // Putting feeding into stock now needs a reason: the per-line switch stays.
    expect(fn () => app(SaveLandedCost::class)->handle($this->receipt, '2026-10-21', [($this->line)('feeding', '300', ['treatment' => 'capitalize'])], $this->user))
        ->toThrow(ValidationException::class);
});

it('refuses an expense default with no head for a type that has no mapped head, and a non-expense head', function () {
    $this->actingAs($this->user)
        ->put(route('accounting.finance-policy.cost-types.update', 'feeding'), ['treatment' => 'expense'])
        ->assertSessionHasErrors('expense_account_id');

    $this->actingAs($this->user)
        ->put(route('accounting.finance-policy.cost-types.update', 'feeding'), ['treatment' => 'expense', 'expense_account_id' => Account::bySlug('cash_at_bank')->id])
        ->assertSessionHasErrors('expense_account_id');

    // Freight has a mapped head (5300), so no head is needed.
    $this->actingAs($this->user)
        ->put(route('accounting.finance-policy.cost-types.update', 'freight'), ['treatment' => 'expense'])
        ->assertSessionHasNoErrors();

    expect(PurchaseCostTypeDefault::query()->where('cost_type', 'feeding')->exists())->toBeFalse()
        ->and(LandedCostType::Freight->defaultTreatment())->toBe(LandedCostTreatment::Expense);
});

it('keeps a saved line on the default it was saved under after the Owner changes it', function () {
    $lc = app(SaveLandedCost::class)->handle($this->receipt, '2026-10-21', [($this->line)('travel', '200')], $this->user);

    $this->actingAs($this->user)->put(route('accounting.finance-policy.cost-types.update', 'travel'), ['treatment' => 'expense'])->assertSessionHasNoErrors();

    $line = $lc->fresh('lines')->lines->first();

    expect($line->treatment)->toBe(LandedCostTreatment::Capitalize)
        ->and($line->defaultTreatment())->toBe(LandedCostTreatment::Capitalize)
        ->and($line->overridesDefault())->toBeFalse();
});

it('is the Owner key only, and an unknown type is not found', function () {
    $this->actingAs($this->staff)->put(route('accounting.finance-policy.cost-types.update', 'feeding'), ['treatment' => 'expense'])->assertForbidden();
    $this->actingAs($this->user)->put('/accounting/finance-policy/cost-types/nonsense', ['treatment' => 'expense'])->assertNotFound();
});

it('seeds every existing business at stock cost through the migration, idempotently', function () {
    $migration = require base_path('app/Modules/Purchasing/database/migrations/2026_10_26_380000_purchase_cost_types_have_owner_set_defaults.php');
    $migration->up();
    $migration->up();

    expect(PurchaseCostTypeDefault::withoutGlobalScopes()->where('business_id', $this->business->id)->count())->toBe(12)
        ->and(PurchaseCostTypeDefault::withoutGlobalScopes()->where('treatment', '!=', 'capitalize')->count())->toBe(0);
});
