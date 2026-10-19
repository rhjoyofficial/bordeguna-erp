<?php

namespace Tests\Support;

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\SubledgerReconciliation;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\CorrectionInput;
use App\Modules\Foundation\Corrections\CorrectionPreview;
use App\Modules\Foundation\Corrections\CorrectionResult;
use App\Modules\Foundation\Corrections\CorrectionService;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\ReverseDateChoice;
use App\Modules\Foundation\database\seeders\ApprovalWorkflowSeeder;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\CreateInventoryAdjustment;
use App\Modules\Inventory\Actions\RecordOpeningStock;
use App\Modules\Inventory\Actions\RecordStockOut;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\database\seeders\InventoryPermissionsSeeder;
use App\Modules\Inventory\database\seeders\InventoryReasonsSeeder;
use App\Modules\Inventory\Enums\AdjustmentDirection;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Inventory\Models\InventorySetting;
use App\Modules\Inventory\Models\OpeningStock;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\AdjustmentCause;
use App\Modules\Inventory\Support\StockLedger;
use App\Modules\Inventory\Support\StockValue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ Corrections wave 1, B4 (20 Oct 2026): a CorrectionWorld with stock on.
 * Inventory permissions, reasons, workflows and account mappings seeded; two
 * warehouses (Savar, Dhanmondi shop); honey (tracks expiry), salt and ghee;
 * a Staff member who may raise but not approve; Manager = the stock approver.
 *
 * Today is 17 Oct 2026; the opening day is 31 Aug 2026.
 */
final class StockWorld
{
    public CorrectionWorld $w;

    public Warehouse $savar;

    public Warehouse $shop;

    public Product $honey;

    public Product $salt;

    public Product $ghee;

    public User $staff;

    /** `$tag` keeps codes and SKUs apart when another world shares the business (the policy kit). */
    public static function make(?CorrectionWorld $w = null, string $tag = ''): self
    {
        $world = new self;
        $world->w = $w ?? CorrectionWorld::make();
        $business = $world->w->business;

        Role::firstOrCreate(['name' => 'Staff', 'business_id' => $business->id, 'guard_name' => 'web']);
        (new InventoryPermissionsSeeder)->run();
        (new InventoryAccountMappingSeeder)->run();
        (new ApprovalWorkflowSeeder)->run();
        app(InventoryReasonsSeeder::class)->run(app(BusinessContext::class));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $world->w->enter($business);

        $world->staff = $world->w->member('Staff', 'Rafiq (store)'.$tag);

        $pcs = UnitOfMeasure::create(['business_id' => $business->id, 'name' => 'Pieces'.$tag, 'abbreviation' => 'pcs'.$tag]);
        $kg = UnitOfMeasure::create(['business_id' => $business->id, 'name' => 'Kilogram'.$tag, 'abbreviation' => 'kg'.$tag]);
        $world->savar = Warehouse::create(['business_id' => $business->id, 'name' => 'Savar'.$tag, 'code' => 'SAV'.$tag, 'is_default' => true]);
        $world->shop = Warehouse::create(['business_id' => $business->id, 'name' => 'Dhanmondi shop', 'code' => 'DHN'.$tag]);
        $world->honey = Product::create(['business_id' => $business->id, 'unit_of_measure_id' => $pcs->id, 'sku' => 'HONEY-500'.$tag, 'name' => 'Sundarban Honey 500g', 'type' => ProductType::FinishedGood, 'tracks_expiry' => true]);
        $world->salt = Product::create(['business_id' => $business->id, 'unit_of_measure_id' => $kg->id, 'sku' => 'SALT'.$tag, 'name' => 'Pink Salt, bulk', 'type' => ProductType::RawMaterial]);
        $world->ghee = Product::create(['business_id' => $business->id, 'unit_of_measure_id' => $pcs->id, 'sku' => 'GHEE-1'.$tag, 'name' => 'Pure Ghee 1kg', 'type' => ProductType::FinishedGood]);

        return $world;
    }

    /** No self-approval limit: every correction waits for an approver. */
    public function noSelfApproval(): void
    {
        InventorySetting::forCurrentBusiness()->update(['adjustment_approval_threshold' => null]);
    }

    /** Self-approval up to this value. */
    public function selfApproveUpTo(string $value): void
    {
        InventorySetting::forCurrentBusiness()->update(['adjustment_approval_threshold' => $value]);
    }

    /**
     * @param  list<array{0: Product, 1: string, 2: string, 3?: ?string, 4?: ?string}>  $lines  product, quantity, cost, batch, expiry
     */
    public function opening(array $lines, ?Warehouse $warehouse = null, string $asAt = '2026-08-31', ?User $by = null): OpeningStock
    {
        return app(RecordOpeningStock::class)->handle(
            warehouse: $warehouse ?? $this->savar,
            lines: array_map(fn (array $l) => [
                'product' => $l[0], 'quantity' => $l[1], 'unitCost' => $l[2],
                'batchNumber' => $l[3] ?? null, 'expiryDate' => $l[4] ?? ($l[0]->tracks_expiry ? '2027-08-31' : null),
            ], $lines),
            createdBy: $by ?? $this->w->owner,
            asAt: $asAt,
        );
    }

    /**
     * Stock leaving as a sale would: FIFO out, and its cost booked Dr Cost of
     * Goods Sold / Cr Stock on Hand, so the books still reconcile afterwards.
     */
    public function sell(Product $product, string $quantity, string $on = '2026-09-10', ?Warehouse $warehouse = null): void
    {
        $movements = app(RecordStockOut::class)->handle(product: $product, warehouse: $warehouse ?? $this->savar, quantity: $quantity, performedBy: $this->w->owner, occurredAt: $on);
        $value = StockValue::sum($movements)->absolute();

        app(PostJournal::class)->handle(new JournalDraft(
            date: Carbon::parse($on)->startOfDay(),
            lines: [
                JournalLineDraft::debit(Account::bySlug('cost_of_goods_sold')->id, $value),
                JournalLineDraft::credit(Account::bySlug('stock_on_hand')->id, $value),
            ],
            memo: "Test sale of {$quantity} x {$product->name}",
            source: $movements->first(),
        ), $this->w->owner);
    }

    public function reason(string $name, string $context = 'inventory.stock_adjustment'): Reason
    {
        return Reason::query()->where('context', $context)->where('name', $name)->firstOrFail();
    }

    /** Raises a correction through the real action (as the Stock screen does). */
    public function adjust(string $reasonName, AdjustmentDirection $direction, Product $product, string $quantity, ?User $by = null, ?string $unitCost = null, string $on = '2026-10-05', ?Warehouse $warehouse = null, ?StockBatch $batch = null): InventoryAdjustment
    {
        $reason = $this->reason($reasonName);

        return app(CreateInventoryAdjustment::class)->handle(
            type: AdjustmentCause::typeFor($reason),
            direction: $direction,
            product: $product,
            warehouse: $warehouse ?? $this->savar,
            quantity: $quantity,
            reason: $reason,
            createdBy: $by ?? $this->w->owner,
            unitCost: $unitCost,
            effectiveDate: $on,
            batch: $batch,
        );
    }

    public function input(string $type, Model $document, DocumentAction $action, ?array $replacement = null, string $reason = 'Counted wrong on the day', ?string $key = null): CorrectionInput
    {
        return new CorrectionInput($type, (int) $document->getKey(), $action, new ActionReason(null, $reason), $replacement, ReverseDateChoice::OriginalDate, $key ?? (string) Str::uuid());
    }

    public function preview(CorrectionInput $input, User $by): CorrectionPreview
    {
        return app(CorrectionService::class)->preview($input, $by);
    }

    public function submit(CorrectionInput $input, User $by): CorrectionResult
    {
        return app(CorrectionService::class)->submit($input, $by, $this->preview($input, $by)->previewToken);
    }

    /** Units of a product in a warehouse, from the batches (the derived cache). */
    public function onHand(Product $product, ?Warehouse $warehouse = null): string
    {
        return number_format((float) StockBatch::query()->where('product_id', $product->id)->where('warehouse_id', ($warehouse ?? $this->savar)->id)->sum('current_quantity'), 4, '.', '');
    }

    public function balance(string $slug): int
    {
        return (int) DB::table('journal_lines')->where('account_id', Account::bySlug($slug)->id)->sum('amount_minor');
    }

    /**
     * The reconciliation invariants of FINAL-PLAN 6.6.4, all at once: every
     * journal balances, the stock ledger agrees with its batches, and Stock on
     * Hand agrees with the value of the movements.
     */
    public function assertReconciles(): void
    {
        expect((int) DB::table('journal_lines')->sum('amount_minor'))->toBe(0);

        $unbalanced = DB::table('journal_lines')->groupBy('journal_id')->havingRaw('SUM(amount_minor) <> 0')->pluck('journal_id');
        expect($unbalanced->all())->toBe([]);

        expect(StockLedger::verify())->toBe([]);

        $problems = collect(SubledgerReconciliation::reconcileCurrentBusiness()['problems'])
            ->filter(fn (string $problem) => str_contains($problem, 'Stock on Hand'))
            ->values();

        expect($problems->all())->toBe([]);
    }
}
