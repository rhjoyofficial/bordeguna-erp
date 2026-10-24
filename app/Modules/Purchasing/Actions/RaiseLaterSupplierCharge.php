<?php

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Purchasing\Enums\LandedCostTreatment;
use App\Modules\Purchasing\Enums\LandedCostType;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\LandedCost;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ Initial plan B1 (R54, 25 Oct 2026): "additional post-receipt charges the
 * supplier claims later", entered on the purchase itself.
 *
 * ⚠ NOT a second engine. The charge is an ordinary landed cost on the order's
 * goods receipt, owed to the order's supplier: SaveLandedCost writes the draft
 * (LC-...), then the same send-and-post RaiseOrderCharges uses: sent, approved
 * on the spot only for whoever may, posted when allowed, and posting raises the
 * supplier's bill for it (and holds for the purchasing approval when the
 * amount needs one). Whatever stops it is said, never hidden; the draft stays
 * on the Landed costs screen.
 */
class RaiseLaterSupplierCharge
{
    public function __construct(
        private readonly SaveLandedCost $save,
        private readonly RaiseOrderCharges $charges,
    ) {}

    /** The cost types a supplier's later charge may be: those the engine can book without asking for a head. */
    public static function types(): array
    {
        return array_values(array_filter(LandedCostType::cases(), fn (LandedCostType $type) => $type->defaultTreatment() === LandedCostTreatment::Capitalize || $type->expenseMappingKey() !== null));
    }

    /**
     * @return array{landed_cost: LandedCost, notes: list<string>}
     *
     * @throws ValidationException
     */
    public function handle(PurchaseOrder $order, string $incurredOn, string $costType, string $amount, ?string $description, User $by, ?GoodsReceipt $receipt = null): array
    {
        $receipt ??= GoodsReceipt::query()->where('purchase_order_id', $order->id)->whereNull('reversed_at')->latest('id')->first();

        if ($receipt === null || (int) $receipt->purchase_order_id !== (int) $order->id || $receipt->isReversed()) {
            throw ValidationException::withMessages(['later_charge' => "Nothing has been received on {$order->po_number} yet. A later charge goes onto the goods it is for: receive them first."]);
        }

        if (! in_array(LandedCostType::tryFrom($costType), self::types(), true)) {
            throw ValidationException::withMessages(['cost_type' => 'Pick what the charge is for.']);
        }

        if (Carbon::parse($incurredOn)->lt(Carbon::parse($receipt->received_at)->startOfDay())) {
            throw ValidationException::withMessages(['incurred_on' => 'A later charge cannot be dated before the goods were received.']);
        }

        $landedCost = DB::transaction(fn () => $this->save->handle(
            receipt: $receipt,
            incurredOn: $incurredOn,
            lines: [[
                'cost_type' => $costType,
                'allocation_method' => 'value',
                'amount' => Money::ofTaka($amount)->toTaka(),
                'supplier_id' => $order->supplier_id,
                'description' => $description,
            ]],
            actor: $by,
            note: "Later charge from the supplier on {$order->po_number}",
        ));

        $notes = [];
        $landedCost = $this->charges->sendAndPost($landedCost, $by, $notes);

        return ['landed_cost' => $landedCost, 'notes' => $notes];
    }
}
