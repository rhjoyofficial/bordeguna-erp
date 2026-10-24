<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FinancePolicy;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\database\seeders\ApprovalWorkflowSeeder;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
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
use App\Modules\Purchasing\database\seeders\PurchasingPermissionsSeeder;
use App\Modules\Purchasing\Enums\LandedCostStatus;
use App\Modules\Purchasing\Exceptions\PurchaseApprovalRequired;
use App\Modules\Purchasing\Models\LandedCost;
use App\Modules\Purchasing\Models\PurchaseApproval;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Support\PurchasingApprovalGate;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * ⭐ 24 Oct 2026: a landed cost owed to a supplier becomes that supplier's
 * bill, so it passes the purchasing approvals every supplier bill passes
 * (ruling P7: Tk 2 lakh, never the maker). Refused, nothing is written; the
 * Landed costs screen saves it as a PA- row, and approving that posts it. An
 * order charge raised at receipt is left approved, never refusing the receipt.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-20 10:00:00');
    config(['accounting.purchasing_approvals' => true]);

    $this->business = Business::create(['name' => 'Approve Co', 'slug' => 'approve-co', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);

    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(InventoryAccountMappingSeeder::class);
    TenantScope::enter($this->business->id);

    foreach (['Owner', 'Manager', 'Finance'] as $name) {
        Role::firstOrCreate(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }
    (new PurchasingPermissionsSeeder)->run();
    (new ApprovalWorkflowSeeder)->run();
    setPermissionsTeamId($this->business->id);

    $this->make = function (string $role, string $name): User {
        $user = User::factory()->create(['name' => $name]);
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $user;
    };
    $this->joy = ($this->make)('Owner', 'Joy');
    $this->moin = ($this->make)('Manager', 'Moin');
    $this->clerk = ($this->make)('Finance', 'Clerk');

    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Piece', 'abbreviation' => 'pc']);
    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Savar', 'code' => 'SAV']);
    $this->product = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'JAR-500', 'name' => 'Glass jar 500ml', 'type' => ProductType::RawMaterial,
    ]);
    $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'Padma Glass', 'code' => 'SUP-001', 'supplier_type' => 'goods']);
    $this->bank = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'BRAC Bank', 'type' => MoneyAccountType::Bank,
        'account_id' => Account::bySlug('cash_at_bank')->id,
    ]);

    // The policy as Joy set it, with any switch overridden for one test.
    $this->policy = fn (array $overrides = []) => FinancePolicy::withoutGlobalScopes()->updateOrCreate(
        ['business_id' => $this->business->id],
        $overrides + ['refuse_negative_money' => false],
    );

    // An approved order of `quantity` at `cost`, received in full.
    $this->received = function (string $quantity, string $cost = '20.00'): PurchaseOrder {
        $order = app(CreatePurchaseOrder::class)->handle(
            supplier: $this->supplier, warehouse: $this->warehouse,
            lines: [['product' => $this->product, 'quantity' => $quantity, 'unit_cost' => $cost]],
            createdBy: $this->joy,
        );
        approveIfPending($order->approvalRequest, $this->moin);
        $order = $order->fresh('lines');
        $line = $order->lines->first();
        app(RecordGoodsReceipt::class)->handle($order, [['line' => $line, 'quantity' => (string) $line->quantity_ordered]], $this->joy);

        return $order->fresh('lines');
    };

});

// A landed cost of `amount` owed to the supplier on the receipt of `$order`,
// saved, sent and approved by Joy (the landed cost's own approval).
function lcApproved(object $t, PurchaseOrder $order, string $amount): LandedCost
{
    $receipt = $order->goodsReceipts()->with('lines')->latest('id')->firstOrFail();
    $lc = app(SaveLandedCost::class)->handle($receipt, '2026-08-20', [[
        'cost_type' => 'freight', 'treatment' => null, 'override_reason' => null, 'allocation_method' => 'value',
        'goods_receipt_line_id' => null, 'amount' => $amount, 'supplier_id' => $t->supplier->id,
        'money_account_id' => null, 'expense_account_id' => null, 'description' => 'Truck from Chattogram',
    ]], $t->joy);
    $lc = app(SubmitLandedCost::class)->handle($lc, $t->joy);

    expect($lc->status)->toBe(LandedCostStatus::Approved);

    return $lc->fresh(['approvalRequest', 'lines']);
}

it('posts a landed cost owed to a supplier below Tk 2 lakh with no purchasing approval', function () {
    ($this->policy)();
    $lc = lcApproved($this, ($this->received)('10000'), '150000');

    $posted = app(PostLandedCost::class)->handle($lc, $this->joy);

    expect($posted->status)->toBe(LandedCostStatus::Posted)
        ->and($posted->bills)->toHaveCount(1)
        ->and(PurchaseApproval::count())->toBe(0);
});

it('holds a landed cost that owes a supplier Tk 2 lakh or more for a second person, and posts it on approval', function () {
    ($this->policy)();
    $lc = lcApproved($this, ($this->received)('10000'), '250000');
    $journals = Journal::count();

    // The action refuses before anything is written.
    expect(fn () => app(PostLandedCost::class)->handle($lc, $this->joy))
        ->toThrow(fn (PurchaseApprovalRequired $e) => expect(collect($e->reasons)->pluck('code')->all())->toBe(['threshold'])
            ->and($e->draft->kind)->toBe('bill')
            ->and($e->draft->amount->format())->toBe('250,000.00'));

    expect($lc->fresh()->status)->toBe(LandedCostStatus::Approved)
        ->and(SupplierBill::query()->where('landed_cost_id', $lc->id)->exists())->toBeFalse()
        ->and(Journal::count())->toBe($journals);

    // Post on the Landed costs screen saves it as a PA- row.
    $this->actingAs($this->joy)->post(route('purchasing.landed-costs.post', $lc))
        ->assertSessionHas('success', fn (string $message) => str_contains($message, 'needs a second person'));

    $approval = PurchaseApproval::query()->sole();
    expect($approval->kind)->toBe('bill')
        ->and($approval->reference)->toBe($lc->number)
        ->and($approval->amount_minor)->toBe(25_000_000)
        ->and($approval->isPending())->toBeTrue()
        ->and($lc->fresh()->status)->toBe(LandedCostStatus::Approved);

    // Posting it again while it waits is refused, not held twice.
    $this->actingAs($this->joy)->post(route('purchasing.landed-costs.post', $lc))
        ->assertSessionHas('error', fn (string $message) => str_contains($message, 'already waiting for approval'));
    expect(PurchaseApproval::count())->toBe(1);

    // The maker may not approve; the Manager may, and that posts it.
    expect(fn () => app(PurchasingApprovalGate::class)->approve($approval, $this->joy))->toThrow(ValidationException::class);

    $bill = app(PurchasingApprovalGate::class)->approve($approval->fresh(), $this->moin);

    expect($bill)->toBeInstanceOf(SupplierBill::class)
        ->and($bill->landed_cost_id)->toBe($lc->id)
        ->and(Money::ofTaka((string) $bill->total)->minor)->toBe(25_000_000)
        ->and($lc->fresh()->status)->toBe(LandedCostStatus::Posted)
        ->and($approval->fresh()->status)->toBe(PurchaseApproval::APPROVED)
        // Posted once: one journal, Cr AP 250,000 with the supplier as party.
        ->and(Journal::count())->toBe($journals + 1)
        ->and(-(int) $lc->fresh()->journal->lines()->where('party_id', $this->supplier->id)->sum('amount_minor'))->toBe(25_000_000);
});

it('leaves a landed cost raised from an order charge approved but not posted when it needs a second person, never refusing the receipt', function () {
    ($this->policy)();
    $order = app(CreatePurchaseOrder::class)->handle(
        supplier: $this->supplier, warehouse: $this->warehouse,
        lines: [['product' => $this->product, 'quantity' => '10000', 'unit_cost' => '20.00']],
        createdBy: $this->joy,
        details: ['charges' => [['cost_type' => 'freight', 'amount' => '250000', 'description' => 'Truck', 'allocation_method' => 'value', 'supplier_id' => $this->supplier->id, 'money_account_id' => null]]],
    );
    approveIfPending($order->approvalRequest, $this->moin);
    $order = $order->fresh('lines');
    $line = $order->lines->first();

    $receipt = app(RecordGoodsReceipt::class)->handle($order, [['line' => $line, 'quantity' => (string) $line->quantity_ordered]], $this->joy);

    $lc = LandedCost::query()->where('goods_receipt_id', $receipt->id)->sole();
    expect($receipt->exists)->toBeTrue()
        ->and($lc->status)->toBe(LandedCostStatus::Approved)
        ->and(SupplierBill::query()->where('landed_cost_id', $lc->id)->exists())->toBeFalse();
});
