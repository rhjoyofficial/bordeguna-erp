<?php

namespace Tests\Support;

use App\Models\User;
use App\Modules\Accounting\Support\SubledgerReconciliation;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\CorrectionInput;
use App\Modules\Foundation\Corrections\CorrectionPreview;
use App\Modules\Foundation\Corrections\CorrectionResult;
use App\Modules\Foundation\Corrections\CorrectionService;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\ReverseDateChoice;
use App\Modules\Inventory\Models\Product;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Corrections\GoodsReceiptActionPolicy;
use App\Modules\Purchasing\database\seeders\PurchasingPermissionsSeeder;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ Corrections wave 1, purchase documents (19 Oct 2026): a DiscountWorld
 * (stock posting on, a warehouse, salt, sugar, a lab-test service, one
 * supplier) with a Store user (Staff: raises orders and receives goods) and
 * helpers to reverse and correct a goods receipt through the corrections
 * process, as the dialog does.
 */
final class ReceiptWorld
{
    public CorrectionWorld $w;

    public DiscountWorld $d;

    public User $staff;

    public User $buyer;

    public static function make(string $now = '2026-10-19 10:00:00'): self
    {
        $world = new self;
        $world->w = CorrectionWorld::make($now);

        foreach ([$world->w->business, $world->w->other] as $business) {
            Role::firstOrCreate(['name' => 'Staff', 'business_id' => $business->id, 'guard_name' => 'web']);
        }

        $world->d = DiscountWorld::make($world->w);
        (new PurchasingPermissionsSeeder)->run();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $world->w->enter($world->w->business);

        $world->staff = $world->w->member('Staff', 'Store keeper');
        $world->buyer = $world->w->member('Staff', 'Buyer');

        return $world;
    }

    /**
     * An approved order received in full on one day (one receipt).
     *
     * @param  list<array{0: Product, 1: string, 2: string}>  $products
     */
    public function received(array $products, string $on): PurchaseOrder
    {
        return $this->d->order($products, $on);
    }

    /** An approved order nothing has happened to. */
    public function approved(array $products, ?User $by = null): PurchaseOrder
    {
        $order = app(CreatePurchaseOrder::class)->handle(
            supplier: $this->d->supplier,
            warehouse: $this->d->warehouse,
            lines: array_map(fn (array $p) => ['product' => $p[0], 'quantity' => $p[1], 'unit_cost' => $p[2]], $products),
            createdBy: $by ?? $this->w->owner,
        );
        approveIfPending($order->approvalRequest, $this->w->owner);

        return $order->fresh(['lines', 'approvalRequest']);
    }

    public function receiptOf(PurchaseOrder $order): GoodsReceipt
    {
        return GoodsReceipt::query()->live()->where('purchase_order_id', $order->id)->sole();
    }

    /** The receipt as the Correct form sends it, with changes (per line: by product id). */
    public function form(GoodsReceipt $receipt, array $changes = [], array $lineChanges = []): array
    {
        $receipt->load(['lines.stockMovement.batch', 'lines.purchaseOrderLine']);

        return array_merge([
            'received_on' => $receipt->received_at->toDateString(),
            'supplier_challan_number' => $receipt->supplier_challan_number,
            'note' => $receipt->note,
            'lines' => $receipt->lines->map(fn ($line) => array_merge([
                'id' => $line->id,
                'quantity' => (string) (float) $line->quantity_received,
                'batch_number' => $line->stockMovement?->batch?->batch_number,
                'manufactured_date' => $line->stockMovement?->batch?->manufactured_date?->toDateString(),
                'expiry_date' => $line->stockMovement?->batch?->expiry_date?->toDateString(),
                'different_lot' => false,
            ], $lineChanges[$line->purchaseOrderLine->product_id] ?? []))->values()->all(),
        ], $changes);
    }

    public function input(GoodsReceipt $receipt, DocumentAction $action, ?array $replacement = null, string $reason = 'Entered on the wrong day', ?string $key = null): CorrectionInput
    {
        return new CorrectionInput(GoodsReceiptActionPolicy::TYPE, (int) $receipt->id, $action, new ActionReason(null, $reason), $replacement, ReverseDateChoice::OriginalDate, $key ?? (string) Str::uuid());
    }

    public function preview(CorrectionInput $input, User $by): CorrectionPreview
    {
        return app(CorrectionService::class)->preview($input, $by);
    }

    public function submit(CorrectionInput $input, User $by): CorrectionResult
    {
        return app(CorrectionService::class)->submit($input, $by, $this->preview($input, $by)->previewToken);
    }

    public function reverse(GoodsReceipt $receipt, User $by): CorrectionResult
    {
        return $this->submit($this->input($receipt, DocumentAction::Reverse), $by);
    }

    public function correct(GoodsReceipt $receipt, array $replacement, User $by): CorrectionResult
    {
        return $this->submit($this->input($receipt, DocumentAction::Correct, $replacement), $by);
    }

    /** @return list<string> every reconciliation break: debits = credits, stock = ledger, GRNI and AP = documents */
    public function breaks(): array
    {
        return SubledgerReconciliation::reconcileCurrentBusiness()['problems'];
    }
}
