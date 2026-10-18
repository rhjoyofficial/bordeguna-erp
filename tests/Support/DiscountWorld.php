<?php

namespace Tests\Support;

use App\Models\User;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\CorrectionInput;
use App\Modules\Foundation\Corrections\CorrectionResult;
use App\Modules\Foundation\Corrections\CorrectionService;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\ReverseDateChoice;
use App\Modules\Foundation\database\seeders\ApprovalWorkflowSeeder;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Actions\RecordGoodsReceipt;
use App\Modules\Purchasing\Actions\RecordSupplierBill;
use App\Modules\Purchasing\Actions\RecordSupplierPayment;
use App\Modules\Purchasing\Corrections\SupplierDiscountActionPolicy;
use App\Modules\Purchasing\database\seeders\PurchasingPermissionsSeeder;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierDiscount;
use App\Modules\Purchasing\Models\SupplierPayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ Supplier discounts (ruling P3, 18 Oct 2026): a CorrectionWorld with stock
 * posting on, purchasing permissions seeded, a warehouse, three products (two
 * stocked, one service) and a supplier; orders received, billed and paid
 * through the real actions.
 */
final class DiscountWorld
{
    public CorrectionWorld $w;

    public Warehouse $warehouse;

    public Product $salt;

    public Product $sugar;

    public Product $service;

    public Supplier $supplier;

    public static function make(?CorrectionWorld $w = null): self
    {
        $world = new self;
        $world->w = $w ?? CorrectionWorld::make();
        $business = $world->w->business;

        (new InventoryAccountMappingSeeder)->run();
        (new PurchasingPermissionsSeeder)->run();
        (new ApprovalWorkflowSeeder)->run();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $world->w->enter($business);

        $kg = UnitOfMeasure::create(['business_id' => $business->id, 'name' => 'Kilogram', 'abbreviation' => 'kg']);
        $world->warehouse = Warehouse::create(['business_id' => $business->id, 'name' => 'Savar', 'code' => 'SAV']);
        $product = fn (string $sku, string $name, ProductType $type = ProductType::RawMaterial) => Product::create([
            'business_id' => $business->id, 'unit_of_measure_id' => $kg->id, 'sku' => $sku, 'name' => $name, 'type' => $type,
        ]);
        $world->salt = $product('SALT', 'Salt, bulk');
        $world->sugar = $product('SUGAR', 'Sugar, bulk');
        $world->service = $product('LAB', 'Lab test', ProductType::Service);
        $world->supplier = Supplier::create(['business_id' => $business->id, 'name' => 'Chattogram Salt', 'code' => 'SUP-001']);

        return $world;
    }

    /**
     * An approved order, received in full.
     *
     * @param  list<array{0: Product, 1: string, 2: string}>  $products
     */
    public function order(array $products, string $receivedOn = '2026-10-10'): PurchaseOrder
    {
        $order = app(CreatePurchaseOrder::class)->handle(
            supplier: $this->supplier,
            warehouse: $this->warehouse,
            lines: array_map(fn (array $p) => ['product' => $p[0], 'quantity' => $p[1], 'unit_cost' => $p[2]], $products),
            createdBy: $this->w->owner,
        );
        approveIfPending($order->approvalRequest, $this->w->owner);
        $order = $order->fresh(['lines', 'approvalRequest']);

        app(RecordGoodsReceipt::class)->handle(
            $order,
            $order->lines->map(fn ($line) => ['line' => $line, 'quantity' => (string) $line->quantity_ordered])->all(),
            $this->w->owner,
            receivedOn: Carbon::parse($receivedOn),
            backdateReason: 'test',
        );

        return $order->fresh('lines');
    }

    /** The whole order billed at its own prices, with an optional trade discount. */
    public function bill(PurchaseOrder $order, ?array $discount = null, string $number = 'INV-100', string $on = '2026-10-12'): SupplierBill
    {
        return app(RecordSupplierBill::class)->handle(
            supplier: $this->supplier,
            billNumber: $number,
            billDate: $on,
            lines: $order->lines->map(fn ($line) => ['line' => $line, 'quantity' => (string) $line->quantity_received, 'unitCost' => (string) $line->unit_cost])->all(),
            createdBy: $this->w->owner,
            purchaseOrder: $order,
            tradeDiscount: $discount,
        );
    }

    public function pay(SupplierBill $bill, string $amount, ?string $discount = null, ?string $evidence = null, ?string $reason = null, ?User $by = null): SupplierPayment
    {
        return app(RecordSupplierPayment::class)->handle(
            supplier: $this->supplier,
            moneyAccount: $this->w->bank,
            paidOn: '2026-10-16',
            allocations: [['bill' => $bill, 'amount' => $amount, 'discount' => $discount]],
            createdBy: $by ?? $this->w->owner,
            reference: 'CHQ-1',
            discountEvidence: $evidence,
            discountReason: $reason,
        );
    }

    /** Reverses a discount through the corrections process. */
    public function reverse(SupplierDiscount $discount, User $by): CorrectionResult
    {
        $input = new CorrectionInput(SupplierDiscountActionPolicy::TYPE, (int) $discount->id, DocumentAction::Reverse, new ActionReason(null, 'Supplier withdrew the discount'), null, ReverseDateChoice::OriginalDate, (string) Str::uuid());
        $service = app(CorrectionService::class);

        return $service->submit($input, $by, $service->preview($input, $by)->previewToken);
    }
}
