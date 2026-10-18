<?php

namespace App\Modules\Purchasing\Actions;

use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\VoucherAccounts;
use App\Modules\Inventory\Enums\MovementDirection;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Support\UnitDimension;
use App\Modules\Purchasing\Enums\LandedCostAllocationMethod;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Purchasing\Models\LandedCost;
use App\Modules\Purchasing\Models\LandedCostLine;
use App\Modules\Purchasing\Support\LandedCostPlan;
use App\Modules\Purchasing\Support\LandedCostPolicy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Works out, without writing anything, where every paisa of a landed cost
 * goes (Phase 1, 17 Oct 2026, ChatGPT ruling Q4).
 *
 * For a CAPITALISED line:
 *  1. allocate the amount over the receipt by the line's own method: the whole
 *     of it onto one receipt line (direct), or across every stocked line in
 *     proportion to the value received (value), the units received in stock
 *     units (quantity) or the weight received (weight; 18 Oct 2026, D-A);
 *  2. split each batch's share between the units still on hand and the units
 *     already consumed, from the batch's own movements (FIFO evidence);
 *  3. the on-hand share restates the batch's cost (RevalueStockBatch); the
 *     consumed share goes to cost of sales (purchasing.landed_cost_consumed).
 *
 * For an EXPENSED line: the head it posts to, exactly as an expense voucher
 * would (the type's seeded head, or the head named on an "other" line).
 *
 * ⚠⚠ INTEGER PAISA THROUGHOUT, and the parts always sum to the whole: shares
 * are floored and the leftover paisa go onto the LARGEST share; the on-hand
 * part is rounded half up and the consumed part is the remainder. A ledger
 * that is a paisa out is a ledger somebody has to explain.
 *
 * ⚠⚠ REFUSES WITH A SENTENCE rather than guessing: a receipt line with no
 * stock batch behind it, a batch whose history holds a transfer or a return to
 * the supplier (Phase 2), a date before the goods arrived, a closed month, a
 * consumed share over Joy's Tk 10,000 rule, a missing mapping. Never onto
 * write-off.
 */
class PlanLandedCostAllocation
{
    /**
     * Each batch's unit cost as the lines already planned leave it.
     *
     * ⚠⚠ Found 18 Oct 2026: two capitalised lines on one batch (carriage AND
     * unloading on the same delivery) each restated the cost from the batch's
     * ORIGINAL cost, so the second revaluation overwrote the first: the value
     * moved by both, the unit cost by one. Each line now starts from where the
     * previous one left the batch.
     *
     * @var array<int, string>
     */
    private array $costSoFar = [];

    public function handle(LandedCost $landedCost): LandedCostPlan
    {
        $this->costSoFar = [];

        $landedCost->loadMissing([
            'goodsReceipt.lines.stockMovement.batch',
            'goodsReceipt.lines.purchaseOrderLine.product:id,name,sku,unit_of_measure_id,weight_grams',
            'goodsReceipt.lines.purchaseOrderLine.product.unitOfMeasure',
            'lines',
        ]);

        $on = Carbon::parse($landedCost->incurred_on)->startOfDay();
        $receipt = $landedCost->goodsReceipt;
        $refusals = [];
        $planned = [];
        $capitalised = Money::zero();
        $onHand = Money::zero();
        $consumed = Money::zero();
        $expensed = Money::zero();

        if ($receipt === null) {
            return new LandedCostPlan([], $capitalised, $onHand, $consumed, $expensed, ['The goods receipt this landed cost belongs to no longer exists.']);
        }

        if ($landedCost->lines->isEmpty()) {
            $refusals[] = 'Add at least one cost line.';
        }

        $receivedOn = $receipt->received_at ? Carbon::parse($receipt->received_at)->startOfDay() : null;

        if ($receivedOn !== null && $on->lessThan($receivedOn)) {
            $refusals[] = 'The cost is dated '.$on->format('j M Y').', before the goods arrived on '.$receivedOn->format('j M Y').'. A cost cannot be added to stock that was not there yet.';
        }

        if (($closed = LandedCostPolicy::closedMonthRefusal($on)) !== null) {
            $refusals[] = $closed;
        }

        $stocked = $receipt->lines->filter(fn (GoodsReceiptLine $line) => $line->stockMovement?->batch !== null);

        foreach ($landedCost->lines as $line) {
            if ($line->isCapitalised()) {
                [$allocations, $problems] = $this->allocate($line, $receipt->lines, $stocked, $on);

                foreach ($problems as $problem) {
                    $refusals[] = $problem;
                }

                foreach ($allocations as $allocation) {
                    $capitalised = $capitalised->plus(Money::ofMinor($allocation['share_paisa']));
                    $onHand = $onHand->plus(Money::ofMinor($allocation['on_hand_share_paisa']));
                    $consumed = $consumed->plus(Money::ofMinor($allocation['consumed_share_paisa']));
                }

                $planned[$line->id] = [
                    'line_id' => $line->id,
                    'treatment' => $line->treatment->value,
                    'default_treatment' => $line->defaultTreatment()->value,
                    'basis' => $line->allocation_method->value,
                    'basis_label' => $line->allocation_method->label(),
                    'expense_account' => null,
                    'allocations' => $allocations,
                ];

                continue;
            }

            [$account, $problem] = $this->expenseHead($line, $on);

            if ($problem !== null) {
                $refusals[] = $problem;
            }

            $expensed = $expensed->plus($line->amount());

            $planned[$line->id] = [
                'line_id' => $line->id,
                'treatment' => $line->treatment->value,
                'default_treatment' => $line->defaultTreatment()->value,
                'basis' => null,
                'basis_label' => null,
                'expense_account' => $account,
                'allocations' => [],
            ];
        }

        if (($limit = LandedCostPolicy::consumedRefusal($consumed)) !== null) {
            $refusals[] = $limit;
        }

        return new LandedCostPlan($planned, $capitalised, $onHand, $consumed, $expensed, array_values(array_unique($refusals)));
    }

    /**
     * @param  Collection<int, GoodsReceiptLine>  $receiptLines
     * @param  Collection<int, GoodsReceiptLine>  $stocked
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    private function allocate(LandedCostLine $line, Collection $receiptLines, Collection $stocked, Carbon $on): array
    {
        $label = $line->cost_type->label().' line of '.Money::ofMinor((int) $line->amount_paisa)->format();

        if ($line->allocation_method === LandedCostAllocationMethod::Direct) {
            $target = $receiptLines->firstWhere('id', $line->goods_receipt_line_id);

            if ($target === null) {
                return [[], ["{$label}: pick the receipt line it belongs to."]];
            }

            if ($target->stockMovement?->batch === null) {
                return [[], ["{$label}: receipt line #{$target->id} put nothing into stock (a service or an unstocked line), so there is no batch to add the cost to."]];
            }

            $targets = collect([$target]);
            $bases = [$target->id => ['weight' => '1', 'quantity' => null, 'unit' => null]];
        } else {
            if ($stocked->isEmpty()) {
                return [[], ["{$label}: no line of this receipt put anything into stock, so there is nothing to spread the cost over."]];
            }

            $targets = $stocked;

            [$bases, $problem] = match ($line->allocation_method) {
                LandedCostAllocationMethod::Quantity => $this->quantityBases($stocked, $label),
                LandedCostAllocationMethod::Weight => $this->weightBases($stocked, $label),
                default => $this->valueBases($stocked, $label),
            };

            if ($problem !== null) {
                return [[], [$problem]];
            }
        }

        $shares = $this->spread((int) $line->amount_paisa, array_map(fn (array $basis) => $basis['weight'], $bases));
        $allocations = [];
        $problems = [];

        foreach ($targets as $receiptLine) {
            $movement = $receiptLine->stockMovement;
            $batch = $movement->batch;

            [$received, $onHand, $consumed, $problem] = $this->history($batch, $movement, $on);

            if ($problem !== null) {
                $problems[] = "{$label}: {$problem}";

                continue;
            }

            $share = $shares[$receiptLine->id];
            $onHandShare = $received === 0 ? 0 : $this->divideHalfUp($share * $onHand, $received);
            $consumedShare = $share - $onHandShare;
            $oldCost = $this->costSoFar[$batch->id] ?? number_format((float) $batch->unit_cost, 4, '.', '');
            $newCost = $onHand === 0 || $onHandShare === 0 ? null : $this->restatedCost($oldCost, $onHandShare, $onHand);

            $allocations[] = [
                'receipt_line_id' => $receiptLine->id,
                'batch_id' => $batch->id,
                'batch_number' => $batch->batch_number,
                'product' => $receiptLine->purchaseOrderLine?->product?->name,
                'quantity_received' => $this->unscale($received),
                'quantity_on_hand' => $this->unscale($onHand),
                'quantity_consumed' => $this->unscale($consumed),
                // ⭐ 18 Oct 2026: what this batch's share was computed from.
                'basis_quantity' => $bases[$receiptLine->id]['quantity'],
                'basis_unit' => $bases[$receiptLine->id]['unit'],
                'share_paisa' => $share,
                'on_hand_share_paisa' => $onHandShare,
                'consumed_share_paisa' => $consumedShare,
                'old_unit_cost' => $oldCost,
                'new_unit_cost' => $newCost,
            ];

            if ($newCost !== null) {
                $this->costSoFar[$batch->id] = $newCost;
            }
        }

        return [$allocations, $problems];
    }

    /**
     * By value received: each line's quantity x unit cost, in paisa.
     *
     * @param  Collection<int, GoodsReceiptLine>  $stocked
     * @return array{0: array<int, array{weight: string, quantity: ?string, unit: ?string}>, 1: ?string}
     */
    private function valueBases(Collection $stocked, string $label): array
    {
        $bases = [];

        foreach ($stocked as $receiptLine) {
            $movement = $receiptLine->stockMovement;
            $value = Money::ofProduct((string) $movement->quantity, (string) $movement->unit_cost)->minor;
            $bases[$receiptLine->id] = ['weight' => (string) max($value, 0), 'quantity' => Money::ofMinor($value)->toTaka(), 'unit' => 'Tk'];
        }

        if ($this->sumOf($bases) === '0') {
            return [[], "{$label}: the receipt's lines carry no value to spread the cost by."];
        }

        return [$bases, null];
    }

    /**
     * By quantity received, in stock units.
     *
     * ⚠⚠ Units are added up only when they ARE one kind of thing: every line
     * in the same unit, or every line metric of one dimension (kg and g become
     * grams, L and mL become millilitres). Grams and pieces do not add up, and
     * neither do jars and boxes: how many pieces a box holds is a fact about
     * one product, not a conversion (UnitOfMeasure). Refused with the lines
     * named, never guessed.
     *
     * @param  Collection<int, GoodsReceiptLine>  $stocked
     * @return array{0: array<int, array{weight: string, quantity: ?string, unit: ?string}>, 1: ?string}
     */
    private function quantityBases(Collection $stocked, string $label): array
    {
        $units = $stocked->map(fn (GoodsReceiptLine $line) => $line->purchaseOrderLine?->product?->unitOfMeasure);

        if ($units->contains(fn ($unit) => $unit === null)) {
            return [[], "{$label}: a line of this receipt has no stock unit, so quantities cannot be added up. Spread it by value instead."];
        }

        $sameUnit = $units->pluck('id')->unique()->count() === 1;
        $metric = ! $sameUnit
            && $units->every(fn (UnitOfMeasure $unit) => $unit->isMetric())
            && $units->pluck('dimension')->unique()->count() === 1;

        if (! $sameUnit && ! $metric) {
            $mix = $stocked->map(fn (GoodsReceiptLine $line) => ($line->purchaseOrderLine?->product?->name ?? "line #{$line->id}")
                .' ('.$this->unitName($line->purchaseOrderLine?->product?->unitOfMeasure).')')
                ->unique()->implode(', ');

            return [[], "{$label}: quantity cannot be added up across this receipt because it mixes units that do not convert: {$mix}. Spread it by value or by weight, or put it on one line."];
        }

        $bases = [];

        foreach ($stocked as $receiptLine) {
            $scaled = $this->scale((string) $receiptLine->stockMovement->quantity);
            $unit = $receiptLine->purchaseOrderLine->product->unitOfMeasure;

            if ($metric) {
                $base = bcmul((string) $scaled, (string) $unit->factor_to_base, 0);
                $bases[$receiptLine->id] = [
                    'weight' => $base,
                    'quantity' => bcdiv($base, '10000', 4),
                    'unit' => $unit->dimension === UnitDimension::VOLUME ? 'mL' : 'g',
                ];

                continue;
            }

            $bases[$receiptLine->id] = ['weight' => (string) $scaled, 'quantity' => $this->unscale($scaled), 'unit' => $this->unitName($unit)];
        }

        if ($this->sumOf($bases) === '0') {
            return [[], "{$label}: the receipt's lines carry no quantity to spread the cost by."];
        }

        return [$bases, null];
    }

    /**
     * By weight received: quantity x the product's weight per stock unit
     * (products.weight_grams), in grams. A product kept in a mass unit with no
     * weight set weighs what its unit says (1 kg = 1,000 g).
     *
     * ⚠ A product with no weight at all is refused BY NAME: a share computed
     * as if it weighed nothing would load its cost onto the others.
     *
     * @param  Collection<int, GoodsReceiptLine>  $stocked
     * @return array{0: array<int, array{weight: string, quantity: ?string, unit: ?string}>, 1: ?string}
     */
    private function weightBases(Collection $stocked, string $label): array
    {
        $bases = [];
        $missing = [];

        foreach ($stocked as $receiptLine) {
            $product = $receiptLine->purchaseOrderLine?->product;
            $unit = $product?->unitOfMeasure;
            $grams = $product?->weight_grams !== null && (int) $product->weight_grams > 0 ? (string) $product->weight_grams : null;

            if ($grams === null && $unit !== null && $unit->isMetric() && $unit->dimension === UnitDimension::MASS) {
                $grams = (string) $unit->factor_to_base;
            }

            if ($grams === null) {
                $missing[] = $product?->name ?? "line #{$receiptLine->id}";

                continue;
            }

            $base = bcmul((string) $this->scale((string) $receiptLine->stockMovement->quantity), $grams, 0);
            $bases[$receiptLine->id] = ['weight' => $base, 'quantity' => bcdiv($base, '10000', 4), 'unit' => 'g'];
        }

        if ($missing !== []) {
            return [[], "{$label}: no weight is set for ".implode(', ', array_unique($missing)).'. Set the weight on the product, or spread it by value or quantity.'];
        }

        if ($this->sumOf($bases) === '0') {
            return [[], "{$label}: the receipt's lines weigh nothing to spread the cost by."];
        }

        return [$bases, null];
    }

    /** @param  array<int, array{weight: string}>  $bases */
    private function sumOf(array $bases): string
    {
        return array_reduce($bases, fn (string $carry, array $basis) => bcadd($carry, $basis['weight'], 0), '0');
    }

    private function unitName(?UnitOfMeasure $unit): string
    {
        return (string) ($unit?->abbreviation ?: $unit?->name ?: 'no unit');
    }

    /**
     * What the batch's movements say: units received by THIS receipt line,
     * units still on hand, units consumed or sold. Scaled by 10,000 (I2).
     *
     * ⚠ A batch whose history holds a transfer out or a return to the supplier
     * has units that are neither on hand nor consumed; following the cost to
     * another warehouse or back to the supplier is Phase 2, so it is refused
     * with the reason rather than split wrongly.
     *
     * @return array{0: int, 1: int, 2: int, 3: ?string}
     */
    private function history(StockBatch $batch, StockMovement $receiptMovement, Carbon $on): array
    {
        if ($batch->received_at !== null && $on->lessThan(Carbon::parse($batch->received_at)->startOfDay())) {
            return [0, 0, 0, "batch {$batch->batch_number} arrived after the cost's date."];
        }

        $received = $this->scale((string) $receiptMovement->quantity);
        $onHand = $this->scale((string) $batch->current_quantity);

        $movements = StockMovement::query()
            ->where('batch_id', $batch->id)
            ->where('id', '!=', $receiptMovement->id)
            ->get(['id', 'direction', 'reason', 'quantity']);

        $unfollowable = $movements->first(fn (StockMovement $m) => in_array($m->reason, [MovementReason::Transfer, MovementReason::PurchaseReturn], true)
            && (float) $m->quantity > 0);

        if ($unfollowable !== null) {
            $what = $unfollowable->reason === MovementReason::Transfer ? 'was partly transferred to another warehouse' : 'was partly returned to the supplier';

            return [0, 0, 0, "batch {$batch->batch_number} {$what}, so its cost cannot be followed yet (Phase 2). Resolve this receipt by hand or split the cost by a direct line on another receipt line."];
        }

        $consumed = 0;

        foreach ($movements as $movement) {
            $quantity = $this->scale((string) $movement->quantity);

            if ($movement->direction === MovementDirection::Out) {
                $consumed += $quantity;
            } else {
                // A sales return restores the layer: those units are on hand again.
                $consumed -= $quantity;
            }
        }

        if ($received !== $onHand + $consumed) {
            return [0, 0, 0, "batch {$batch->batch_number}'s movements do not add up to what this receipt put in (received {$this->unscale($received)}, on hand {$this->unscale($onHand)}, consumed {$this->unscale($consumed)}); run inventory:verify-ledger before posting."];
        }

        return [$received, $onHand, max($consumed, 0), null];
    }

    /**
     * Floors each part, then puts the leftover paisa on the LARGEST part so the
     * parts sum exactly to the whole. Ties go to the first largest.
     *
     * ⚠ Arbitrary precision (bcmath, 18 Oct 2026): a weight basis is grams x
     * 10,000, and amount x weight overflows a 64-bit integer on a few tonnes.
     *
     * @param  array<int, string>  $weights  non-negative whole numbers, as strings
     * @return array<int, int>
     */
    private function spread(int $amount, array $weights): array
    {
        $total = array_reduce($weights, fn (string $carry, string $weight) => bcadd($carry, $weight, 0), '0');
        $shares = [];
        $given = 0;

        foreach ($weights as $key => $weight) {
            $shares[$key] = (int) bcdiv(bcmul((string) $amount, $weight, 0), $total, 0);
            $given += $shares[$key];
        }

        $remainder = $amount - $given;

        if ($remainder !== 0) {
            $largest = array_key_first($shares);

            foreach ($shares as $key => $share) {
                if ($share > $shares[$largest]) {
                    $largest = $key;
                }
            }

            $shares[$largest] += $remainder;
        }

        return $shares;
    }

    /**
     * @return array{0: ?Account, 1: ?string}
     */
    private function expenseHead(LandedCostLine $line, Carbon $on): array
    {
        $label = $line->cost_type->label().' line of '.Money::ofMinor((int) $line->amount_paisa)->format();
        $account = null;

        if ($line->expense_account_id !== null) {
            $account = Account::query()->find($line->expense_account_id);
        } elseif (($key = $line->cost_type->expenseMappingKey()) !== null) {
            $account = AccountMapping::forKeyOn($key, $on)?->account;

            if ($account === null) {
                return [null, "{$label}: no ledger head is mapped for {$key} on {$on->format('j M Y')}. Map it under Accounts > Account mappings, or name the head on the line."];
            }
        }

        if ($account === null) {
            return [null, "{$label}: name the expense head this cost belongs to."];
        }

        if ($account->type !== AccountType::Expense || ! $account->is_postable || ! $account->is_active) {
            return [$account, "{$label}: {$account->label()} is not a postable expense head."];
        }

        // ⚠ PUR-002: a head another module owns is posted only by that module.
        // The typed heads (5300, 6096, 6471) are SHARED by SubsystemAccounts,
        // so they pass; a hand-named head is checked like a voucher line.
        if (($refusal = VoucherAccounts::refusal($account, $on)) !== null) {
            return [$account, "{$label}: {$account->label()}: {$refusal}"];
        }

        return [$account, null];
    }

    /** cost + on-hand share / units on hand, to four places, half up. "40.0000". */
    private function restatedCost(string $unitCost, int $onHandShareMinor, int $onHandScaled): string
    {
        // paisa per unit = minor x 10^4 / scaled units; taka x 10^4 = that x 100.
        $deltaCost4 = $this->divideHalfUp($onHandShareMinor * 1_000_000, $onHandScaled);
        $oldCost4 = (int) round(((float) $unitCost) * 10000);

        return number_format(($oldCost4 + $deltaCost4) / 10000, 4, '.', '');
    }

    private function divideHalfUp(int $numerator, int $denominator): int
    {
        $quotient = intdiv($numerator, $denominator);

        if (($numerator % $denominator) * 2 >= $denominator) {
            $quotient++;
        }

        return $quotient;
    }

    private function scale(string $quantity): int
    {
        return (int) round(((float) $quantity) * 10000);
    }

    private function unscale(int $scaled): string
    {
        return number_format($scaled / 10000, 4, '.', '');
    }
}
