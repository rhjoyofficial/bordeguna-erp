<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Foundation\Corrections\ClosedPeriodPass;
use App\Modules\Inventory\Actions\Concerns\ConsumesFifoStock;
use App\Modules\Inventory\Enums\MovementDirection;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\EffectiveDate;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RecordStockOut
{
    use ConsumesFifoStock;

    /**
     * @return Collection<int, StockMovement> one movement per batch touched
     */
    public function handle(
        Product $product,
        Warehouse $warehouse,
        string $quantity,
        User $performedBy,
        ?string $note = null,
        MovementReason $reason = MovementReason::Usage,
        ?int $reasonId = null,
        mixed $occurredAt = null,
        // ⭐ Phase 2 (17 Oct 2026): the approved document whose reserved
        // batch slices this issue takes first (see ConsumesFifoStock).
        ?Model $reservedFor = null,
        // ⭐ 24 Oct 2026: the Owner's closed-month exception, from a production
        // run correction only (EffectiveDate::resolve). Null for every other caller.
        ?ClosedPeriodPass $pass = null,
    ): Collection {
        if (! $product->type->isStocked()) {
            throw new DomainException("Product #{$product->id} is a service and cannot hold stock.");
        }

        // ⭐ Initial plan B7 (Joy, 24 Oct 2026; Q7 = A): nothing is sold,
        // used, issued or made from a Not for sale (quarantine) warehouse.
        // A write-off (Adjustment) and a purchase return still take from it.
        $use = [
            MovementReason::Sale->value => 'sale, challan or counter bill',
            MovementReason::Usage->value => 'issue',
            MovementReason::Production->value => 'production run',
            MovementReason::StaffReplacement->value => 'staff replacement',
            MovementReason::OwnerWithdrawal->value => 'owner withdrawal',
        ];
        if (isset($use[$reason->value])) {
            $warehouse->assertTakesStockFor($use[$reason->value]);
        }

        $movedAt = EffectiveDate::resolve($occurredAt, pass: $pass);

        return DB::transaction(function () use ($product, $warehouse, $quantity, $performedBy, $note, $reason, $reasonId, $movedAt, $reservedFor) {
            $consumed = $this->consumeFifo($product, $warehouse, $quantity, $movedAt, $reservedFor);

            return $consumed->map(fn ($portion) => StockMovement::create([
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'batch_id' => $portion['batch']->id,
                'direction' => MovementDirection::Out,
                'reason' => $reason,
                'reason_id' => $reasonId,
                'quantity' => $portion['quantity'],
                'unit_cost' => $portion['batch']->unit_cost,
                'performed_by' => $performedBy->id,
                'note' => $note,
                'occurred_at' => $movedAt,
            ]));
        });
    }
}
