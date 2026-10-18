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
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Actions\RecordGoodsReceipt;
use App\Modules\Purchasing\database\seeders\PurchasingPermissionsSeeder;
use App\Modules\Purchasing\Models\LandedCost;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Purchases > Purchase orders > Landed costs through the real HTTP layer: who
 * may open it, who may write a draft, who may post, and that the whole chain
 * (save, submit, post) reaches the ledger from the buttons the screen offers.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-17 10:00:00');

    $this->business = Business::create(['name' => 'Screen Co', 'slug' => 'screen-co', 'fiscal_year_start_month' => 7]);

    TenantScope::enter($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Finance', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new PurchasingPermissionsSeeder)->run();
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(InventoryAccountMappingSeeder::class);
    (new ApprovalWorkflowSeeder)->run();
    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->asRole = function (string $role) {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $user;
    };

    $this->owner = ($this->asRole)('Owner');

    $kg = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Kilogram', 'abbreviation' => 'kg']);
    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Savar', 'code' => 'SAV']);
    $this->salt = Product::create(['business_id' => $this->business->id, 'unit_of_measure_id' => $kg->id, 'sku' => 'SALT', 'name' => 'Salt', 'type' => ProductType::RawMaterial]);
    $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'Chattogram Salt', 'code' => 'SUP-001']);

    $order = app(CreatePurchaseOrder::class)->handle(supplier: $this->supplier, warehouse: $this->warehouse, lines: [['product' => $this->salt, 'quantity' => '25', 'unit_cost' => '20.00']], createdBy: $this->owner);
    approveIfPending($order->approvalRequest, $this->owner);
    $order = $order->fresh(['lines', 'approvalRequest']);
    $this->receipt = app(RecordGoodsReceipt::class)->handle($order, [['line' => $order->lines->first(), 'quantity' => '25']], $this->owner, receivedOn: Carbon::parse('2026-10-10'), backdateReason: 'test')->fresh('lines');

    $this->payload = fn (array $line = []) => [
        'goods_receipt_id' => $this->receipt->id,
        'incurred_on' => '2026-10-15',
        'note' => 'Sacks',
        // ⚠ `$line + [...]`: PHP's array union keeps the LEFT side, so the overrides come first.
        'lines' => [$line + [
            'cost_type' => 'packaging', 'treatment' => '', 'override_reason' => '', 'allocation_method' => 'value',
            'goods_receipt_line_id' => '', 'amount' => '1000', 'supplier_id' => $this->supplier->id,
            'money_account_id' => '', 'expense_account_id' => '', 'description' => 'Jute sacks',
        ]],
    ];
});

it('opens for the Owner, Manager and Finance and is refused to Staff', function (string $role, int $status) {
    $this->actingAs(($this->asRole)($role))->get('/purchasing/landed-costs')->assertStatus($status);
})->with([['Owner', 200], ['Manager', 200], ['Finance', 200], ['Staff', 403]]);

it('lets Finance read but not write, and Staff neither', function () {
    $this->actingAs(($this->asRole)('Finance'))->post('/purchasing/landed-costs', ($this->payload)())->assertForbidden();
    $this->actingAs(($this->asRole)('Staff'))->post('/purchasing/landed-costs', ($this->payload)())->assertForbidden();

    expect(LandedCost::count())->toBe(0);
});

it('renders the list with the receipts, the options and the allocation preview per batch', function () {
    $this->actingAs($this->owner)->post('/purchasing/landed-costs', ($this->payload)())->assertRedirect();

    $this->actingAs($this->owner)->get('/purchasing/landed-costs?receipt='.$this->receipt->id)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('purchasing/landed-costs/index')
            ->where('canManage', true)
            ->where('canPost', true)
            ->has('receipts', 1)
            ->has('costTypes', 9)
            ->has('landedCosts', 1, fn (AssertableInertia $row) => $row
                ->where('status', 'draft')
                ->where('editable', true)
                ->where('total', '1000.00')
                ->where('plan.capitalised', '1000.00')
                ->where('plan.on_hand', '1000.00')
                ->where('plan.consumed', '0.00')
                ->where('plan.refusals', [])
                ->where('plan.lines.0.allocations.0.new_unit_cost', '60.0000')
                ->etc()));
});

it('refuses an override without a reason at the HTTP layer with the field named', function () {
    $this->actingAs($this->owner)
        ->from('/purchasing/landed-costs')
        ->post('/purchasing/landed-costs', ($this->payload)(['cost_type' => 'bank_charge', 'treatment' => 'capitalize']))
        ->assertRedirect('/purchasing/landed-costs')
        ->assertSessionHasErrors(['lines.0.override_reason']);

    expect(LandedCost::count())->toBe(0);
});

it('posts from the buttons: save, submit (self-approved), post, and the ledger and batch follow', function () {
    $this->actingAs($this->owner)->post('/purchasing/landed-costs', ($this->payload)())->assertRedirect();
    $lc = LandedCost::sole();

    $this->actingAs($this->owner)->post("/purchasing/landed-costs/{$lc->id}/submit")->assertRedirect()->assertSessionHas('success');
    expect($lc->fresh()->status->value)->toBe('approved');

    // Finance may read but not post.
    $this->actingAs(($this->asRole)('Finance'))->post("/purchasing/landed-costs/{$lc->id}/post")->assertForbidden();

    $this->actingAs($this->owner)->post("/purchasing/landed-costs/{$lc->id}/post")->assertRedirect()->assertSessionHas('success');

    $lc->refresh();
    $batch = StockBatch::find($this->receipt->lines->first()->stockMovement->batch_id);

    expect($lc->status->value)->toBe('posted')
        ->and($lc->journal_id)->not->toBeNull()
        ->and((string) $batch->unit_cost)->toBe('60.0000')
        ->and((int) JournalLine::where('account_id', Account::bySlug('stock_on_hand')->id)->sum('amount_minor'))->toBe(150000)
        ->and((int) JournalLine::where('account_id', Account::bySlug('accounts_payable')->id)->sum('amount_minor'))->toBe(-100000);

    // Posted: frozen. Editing and deleting are refused.
    $this->actingAs($this->owner)->put("/purchasing/landed-costs/{$lc->id}", ($this->payload)())->assertSessionHasErrors('lines');
    $this->actingAs($this->owner)->delete("/purchasing/landed-costs/{$lc->id}")->assertRedirect()->assertSessionHas('error');
    expect(LandedCost::count())->toBe(1);
});

it('shows the delivery\'s landed costs on the purchase order page with the add link', function () {
    $this->actingAs($this->owner)->post('/purchasing/landed-costs', ($this->payload)())->assertRedirect();

    $this->actingAs($this->owner)->get('/purchasing/purchase-orders/'.$this->receipt->purchase_order_id)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('can_view_landed_costs', true)
            ->where('can_manage_landed_costs', true)
            ->has('receipts.0.landed_costs', 1, fn (AssertableInertia $row) => $row
                ->where('status', 'draft')
                ->where('total', '1000.00')
                ->etc())
            ->etc());

    expect(file_get_contents(resource_path('js/pages/purchasing/purchase-orders/show.tsx')))->toContain('landed-costs?receipt=');
});

it('deletes a draft and keeps the preview JSON behind the view permission', function () {
    $this->actingAs($this->owner)->post('/purchasing/landed-costs', ($this->payload)())->assertRedirect();
    $lc = LandedCost::sole();

    $this->actingAs(($this->asRole)('Staff'))->get("/purchasing/landed-costs/{$lc->id}/preview")->assertForbidden();
    $this->actingAs(($this->asRole)('Finance'))->get("/purchasing/landed-costs/{$lc->id}/preview")->assertOk()->assertJsonPath('on_hand', '1000.00');

    $this->actingAs($this->owner)->delete("/purchasing/landed-costs/{$lc->id}")->assertRedirect()->assertSessionHas('success');
    expect(LandedCost::count())->toBe(0);
});

it('speaks Bangla on the landed-costs screens through the dictionary', function () {
    $dictionary = file_get_contents(resource_path('js/lib/i18n/landed-costs.ts'));

    foreach (['landed.title', 'landed.add', 'landed.override_reason', 'landed.preview', 'landed.owner_rule'] as $key) {
        expect($dictionary)->toContain("'{$key}'");
    }

    foreach (['js/pages/purchasing/landed-costs/index.tsx', 'js/components/purchasing/landed-cost-dialog.tsx'] as $file) {
        expect(file_get_contents(resource_path($file)))->toContain("t('landed.");
    }
});
