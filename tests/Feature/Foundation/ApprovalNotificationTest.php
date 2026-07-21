<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Foundation\Notifications\PendingApprovalNotification;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\SubmitStockRequest;
use App\Modules\Inventory\Enums\AdjustmentDirection;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Enums\StockRequestType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->business = Business::create(['name' => 'Notify Co', 'slug' => 'notify-co']);
    $this->otherBusiness = Business::create(['name' => 'Other Notify', 'slug' => 'other-notify']);
    app(BusinessContext::class)->set($this->business->id);

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $this->product = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-1',
        'name' => 'Widget',
        'type' => ProductType::RawMaterial,
    ]);
    $this->reason = Reason::create(['business_id' => $this->business->id, 'context' => 'inventory.stock_adjustment', 'name' => 'Damaged']);
    $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'Acme Supplies']);

    foreach (['inventory.stock.approve', 'inventory.stock.request', 'purchasing.po.approve', 'purchasing.po.create'] as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $registrar = app(PermissionRegistrar::class);
    foreach ([$this->business, $this->otherBusiness] as $biz) {
        foreach (['Owner', 'Staff'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'business_id' => $biz->id, 'guard_name' => 'web']);
        }
    }

    $registrar->setPermissionsTeamId($this->business->id);
    Role::where('business_id', $this->business->id)->where('name', 'Owner')->first()
        ->givePermissionTo(['inventory.stock.approve', 'purchasing.po.approve', 'purchasing.po.create']);
    Role::where('business_id', $this->business->id)->where('name', 'Staff')->first()
        ->givePermissionTo(['inventory.stock.request', 'purchasing.po.create']);

    $registrar->setPermissionsTeamId($this->otherBusiness->id);
    Role::where('business_id', $this->otherBusiness->id)->where('name', 'Owner')->first()
        ->givePermissionTo(['inventory.stock.approve', 'purchasing.po.approve']);

    $registrar->setPermissionsTeamId($this->business->id);
    $this->approver = User::factory()->create();
    $this->approver->businesses()->attach($this->business->id);
    $this->approver->assignRole('Owner');

    $this->submitter = User::factory()->create();
    $this->submitter->businesses()->attach($this->business->id);
    $this->submitter->assignRole('Staff');

    $registrar->setPermissionsTeamId($this->otherBusiness->id);
    $this->outsider = User::factory()->create();
    $this->outsider->businesses()->attach($this->otherBusiness->id);
    $this->outsider->assignRole('Owner');

    $registrar->setPermissionsTeamId($this->business->id);
});

function submitRequest(): void
{
    app(SubmitStockRequest::class)->handle(
        type: StockRequestType::Adjustment,
        product: test()->product,
        warehouse: test()->warehouse,
        quantity: '5',
        reason: test()->reason,
        requestedBy: test()->submitter,
        direction: AdjustmentDirection::Decrease,
    );
}

it('emails stock approvers when a request is submitted, but not the submitter or another business', function () {
    Notification::fake();

    submitRequest();

    Notification::assertSentTo($this->approver, PendingApprovalNotification::class);
    Notification::assertNotSentTo($this->submitter, PendingApprovalNotification::class);
    Notification::assertNotSentTo($this->outsider, PendingApprovalNotification::class);
});

it('sends the pending-approval notification on the mail channel', function () {
    Notification::fake();

    submitRequest();

    Notification::assertSentTo(
        $this->approver,
        PendingApprovalNotification::class,
        fn ($notification, array $channels) => in_array('mail', $channels, true),
    );
});

it('emails PO approvers when a Staff member creates a purchase order', function () {
    Notification::fake();

    app(CreatePurchaseOrder::class)->handle(
        supplier: $this->supplier,
        warehouse: $this->warehouse,
        lines: [['product' => $this->product, 'quantity' => '10', 'unit_cost' => '5']],
        createdBy: $this->submitter,
    );

    Notification::assertSentTo($this->approver, PendingApprovalNotification::class);
    Notification::assertNotSentTo($this->outsider, PendingApprovalNotification::class);
});

it('does not notify when a purchase order is self-approved on creation', function () {
    Notification::fake();

    app(CreatePurchaseOrder::class)->handle(
        supplier: $this->supplier,
        warehouse: $this->warehouse,
        lines: [['product' => $this->product, 'quantity' => '10', 'unit_cost' => '5']],
        createdBy: $this->approver,
    );

    Notification::assertNothingSent();
});
