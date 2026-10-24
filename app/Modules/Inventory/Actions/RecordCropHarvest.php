<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Enums\JournalType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\FiscalYear;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\LedgerRounding;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\Business;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Inventory\Exceptions\UnitNotConvertibleException;
use App\Modules\Inventory\Models\CropHarvest;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\BatchNumbers;
use App\Modules\Inventory\Support\CropHeads;
use App\Modules\Inventory\Support\EffectiveDate;
use App\Modules\Inventory\Support\StockValue;
use App\Modules\Inventory\Support\UnitConversionResult;
use App\Modules\Inventory\Support\UnitConverter;
use App\Modules\Purchasing\Support\ReceivedBatchDates;
use App\Support\DocumentNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * ⭐ Farm harvest (B9, 24 Oct 2026): the season's cost becomes stock.
 *
 * ```
 * Dr  Stock on Hand            the value of the batches made
 *     Cr  Crops in Progress    the amount moved
 * ```
 *
 * Until now this took two entries on harvest day (the farm guide, step 4): a
 * journal to a pass-through head and a production run with a seed input line,
 * because a run needs an input and an expense head, and Stock on Hand takes
 * no manual journal. This is the one door instead: the head the season was
 * collected on, the paddy, the amount.
 *
 *   - the amount defaults to what the head holds; a part of it is allowed
 *     (half the field harvested now); more than it holds is refused, judged
 *     on the lowest balance from the harvest day on (CropHeads)
 *   - a by-product (straw) takes the share of the amount the person gives it;
 *     the main product takes the rest
 *   - each line makes ONE batch at cost = its share / its quantity, through
 *     RecordStockIn like every other receipt, so FIFO and FEFO value it the
 *     way they value a purchase
 *   - the head is credited the amount to the paisa, so a fully harvested head
 *     reads zero; the batches carry 4 decimal places, and the few paisa they
 *     cannot carry go to Rounding Differences (LedgerRounding), never silently
 *     into either side
 *
 * ⚠ All in one transaction, the head's row locked first, so two clicks (or
 * two people) cannot both take the same balance. The popup's idempotency key
 * returns the first harvest to a second click.
 */
class RecordCropHarvest
{
    public function __construct(
        private readonly RecordStockIn $recordStockIn,
        private readonly PostJournal $postJournal,
        private readonly UnitConverter $converter,
    ) {}

    /**
     * @param  array<int, array{product: Product, quantity: string, unit?: ?UnitOfMeasure, cost?: ?string, batchNumber?: ?string, expiryDate?: ?string}>  $lines  the first is the main product; the rest are by-products, each with its `cost` in taka
     * @param  string|null  $amount  taka; null = everything the head holds
     *
     * @throws ValidationException
     */
    public function handle(
        string $harvestedOn,
        Account $cropAccount,
        Warehouse $warehouse,
        array $lines,
        User $createdBy,
        ?string $amount = null,
        ?string $note = null,
        ?string $idempotencyKey = null,
    ): CropHarvest {
        if ($idempotencyKey !== null && ($done = CropHarvest::query()->where('idempotency_key', $idempotencyKey)->first()) !== null) {
            return $done;
        }

        $date = $this->date($harvestedOn);
        $prepared = $this->prepare($date, $cropAccount, $warehouse, $lines);

        return DB::transaction(function () use ($date, $cropAccount, $warehouse, $prepared, $createdBy, $amount, $note, $idempotencyKey) {
            // ⚠ The head's row, locked, then the balance read again under it.
            Account::query()->whereKey($cropAccount->id)->lockForUpdate()->first();

            $total = $this->amount($cropAccount, $date, $amount);
            $shares = $this->shares($prepared, $total);

            $harvest = DocumentNumber::claim('number', fn () => $this->nextNumber($warehouse->business_id, $date), fn (string $number) => CropHarvest::create([
                'business_id' => $warehouse->business_id,
                'number' => $number,
                'harvested_on' => $date->toDateString(),
                'crop_account_id' => $cropAccount->id,
                'warehouse_id' => $warehouse->id,
                'amount_paisa' => $total->minor,
                'note' => $note === null || trim($note) === '' ? null : trim($note),
                'idempotency_key' => $idempotencyKey,
                'created_by' => $createdBy->id,
            ]));

            $stockValue = Money::zero();

            foreach ($prepared as $index => $line) {
                $share = $shares[$index];
                $unitCost = self::unitCost($share, $line['result']->quantity);
                $batchNumber = BatchNumbers::resolve($line['product'], $line['batchNumber'], $date);

                $movement = $this->recordStockIn->handle(
                    product: $line['product'],
                    warehouse: $warehouse,
                    quantity: $line['result']->quantity,
                    unitCost: $unitCost,
                    performedBy: $createdBy,
                    batchNumber: $batchNumber,
                    expiryDate: $line['expiryDate'],
                    note: "Harvest {$harvest->number}",
                    reason: MovementReason::Harvest,
                    occurredAt: $date,
                    manufacturedDate: $date->toDateString(),
                );

                $stockValue = $stockValue->plus(StockValue::of($movement));

                $harvest->lines()->create([
                    'business_id' => $harvest->business_id,
                    'product_id' => $line['product']->id,
                    'is_main' => $index === 0,
                    'quantity' => $line['result']->quantity,
                    'entered_quantity' => $line['result']->enteredQuantity,
                    'entered_unit_id' => $line['result']->enteredUnitId,
                    'conversion_factor' => $line['result']->factor,
                    'cost_paisa' => $share->minor,
                    'unit_cost' => $unitCost,
                    'batch_number' => $batchNumber,
                    'expiry_date' => $line['expiryDate'],
                    'stock_movement_id' => $movement->id,
                ]);
            }

            $journal = $this->post($harvest, $cropAccount, $stockValue, $total, $date, $createdBy);
            $harvest->update(['journal_id' => $journal->id]);

            return $harvest->fresh(['lines.product', 'lines.stockMovement.batch', 'warehouse', 'cropAccount']);
        });
    }

    /**
     * Every check that needs no write: the date, the head, the warehouse, the
     * ledger set up, and each line's product, quantity, unit and expiry.
     *
     * @return list<array{product: Product, result: UnitConversionResult, cost: ?Money, batchNumber: ?string, expiryDate: ?string}>
     *
     * @throws ValidationException
     */
    private function prepare(Carbon $date, Account $cropAccount, Warehouse $warehouse, array $lines): array
    {
        if (! CropHeads::allows($cropAccount)) {
            throw ValidationException::withMessages([
                'crop_account_id' => 'Choose a Crops in Progress head (code '.CropHeads::CODE.' or under it) that takes entries.',
            ]);
        }

        if (! $warehouse->is_active) {
            throw ValidationException::withMessages(['warehouse_id' => 'That warehouse is no longer active.']);
        }

        // ⚠ A harvest without the ledger would put value into stock with
        // nothing taken off the crop head: the books would carry the season
        // twice. Refused, unlike opening stock, which has nothing to move from.
        if (AccountMapping::forKeyOn('inventory.stock_on_hand', $date) === null) {
            throw ValidationException::withMessages([
                'harvested_on' => 'Stock on Hand is not set up in the ledger for that date, so a harvest cannot be posted. Ask the administrator.',
            ]);
        }

        $lines = array_values($lines);

        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'Choose the product that was harvested.']);
        }

        $seen = [];
        $prepared = [];

        foreach ($lines as $index => $line) {
            $product = $line['product'];

            if (! $product->type->isStocked()) {
                throw ValidationException::withMessages(["lines.{$index}.product_id" => "{$product->name} is a service and cannot hold stock."]);
            }

            if (in_array($product->id, $seen, true)) {
                throw ValidationException::withMessages(["lines.{$index}.product_id" => "{$product->name} appears on two lines; combine them into one."]);
            }

            $seen[] = $product->id;

            try {
                $result = $this->converter->toStockUnit($product, (string) $line['quantity'], $line['unit'] ?? null, $date);
            } catch (UnitNotConvertibleException|InvalidArgumentException $e) {
                throw ValidationException::withMessages(["lines.{$index}.quantity" => $e->getMessage()]);
            }

            if (bccomp($result->quantity, '0', 4) <= 0) {
                throw ValidationException::withMessages(["lines.{$index}.quantity" => 'The quantity must be greater than zero.']);
            }

            $cost = null;

            if ($index > 0) {
                try {
                    $cost = Money::ofTaka((string) ($line['cost'] ?? ''));
                } catch (InvalidArgumentException) {
                    throw ValidationException::withMessages(["lines.{$index}.cost" => 'Type the share of the cost in taka.']);
                }

                if ($cost->minor <= 0) {
                    throw ValidationException::withMessages(["lines.{$index}.cost" => 'A by-product needs a share of the cost above zero.']);
                }
            }

            // ⭐ PR2: a product with a shelf life gets its expiry from the
            // harvest day when none is typed; one that tracks expiry and has
            // no shelf life must have it typed (ReceivedBatchDates).
            $expiry = trim((string) ($line['expiryDate'] ?? ''));
            $expiry = $expiry === '' ? BatchNumbers::defaultExpiry($product, $date->toDateString()) : Carbon::parse($expiry)->toDateString();
            $missing = ReceivedBatchDates::required((bool) $product->tracks_expiry, 'automatic', $expiry);

            if ($missing !== []) {
                throw ValidationException::withMessages(collect($missing)
                    ->mapWithKeys(fn (string $problem, string $field) => ["lines.{$index}.{$field}" => $problem])
                    ->all());
            }

            $prepared[] = [
                'product' => $product,
                'result' => $result,
                'cost' => $cost,
                'batchNumber' => ($batch = trim((string) ($line['batchNumber'] ?? ''))) === '' ? null : $batch,
                'expiryDate' => $expiry,
            ];
        }

        return $prepared;
    }

    /**
     * The amount moved: what was typed, or everything the head holds.
     *
     * @throws ValidationException
     */
    private function amount(Account $cropAccount, Carbon $date, ?string $typed): Money
    {
        $available = CropHeads::availableFromMinor($cropAccount, $date);

        if ($available <= 0) {
            throw ValidationException::withMessages([
                'crop_account_id' => "{$cropAccount->label()} holds nothing on ".$date->format('d/m/Y').' to harvest.',
            ]);
        }

        if ($typed === null || trim($typed) === '') {
            return Money::ofMinor($available);
        }

        try {
            $amount = Money::ofTaka(trim($typed));
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['amount' => 'Type the amount in taka.']);
        }

        if ($amount->minor <= 0) {
            throw ValidationException::withMessages(['amount' => 'The amount must be more than zero.']);
        }

        if ($amount->minor > $available) {
            throw ValidationException::withMessages([
                'amount' => "{$cropAccount->label()} holds only Tk ".Money::ofMinor($available)->format().' from '.$date->format('d/m/Y').' on, so no more than that can be harvested.',
            ]);
        }

        return $amount;
    }

    /**
     * Each line's share: by-products what was given, the main product the rest.
     *
     * @return list<Money>
     *
     * @throws ValidationException
     */
    private function shares(array $prepared, Money $total): array
    {
        $byProducts = Money::sum(array_map(fn (array $line) => $line['cost'] ?? Money::zero(), array_slice($prepared, 1)));
        $main = $total->minor - $byProducts->minor;

        if ($main <= 0) {
            throw ValidationException::withMessages([
                'lines.1.cost' => 'The by-products take the whole amount; the main product must keep a share of the cost.',
            ]);
        }

        return [Money::ofMinor($main), ...array_map(fn (array $line) => $line['cost'], array_slice($prepared, 1))];
    }

    private function post(CropHarvest $harvest, Account $cropAccount, Money $stockValue, Money $total, Carbon $date, User $by): Journal
    {
        $stock = AccountMapping::resolve('inventory.stock_on_hand', $date);

        $lines = LedgerRounding::balance([
            JournalLineDraft::debit($stock->id, $stockValue, "Harvest {$harvest->number}"),
            JournalLineDraft::credit($cropAccount->id, $total, "Harvest {$harvest->number}"),
        ], $date, "Rounding on harvest {$harvest->number}");

        return $this->postJournal->handle(new JournalDraft(
            date: $date,
            lines: $lines,
            memo: "Harvest {$harvest->number}: {$cropAccount->label()} into stock",
            source: $harvest,
            type: JournalType::Manual,
        ), $by);
    }

    /**
     * Cost per unit at four decimal places: share over quantity, in integer
     * arithmetic, rounded half up once (RecordProductionRun::unitCostFor).
     */
    public static function unitCost(Money $share, string $quantity): string
    {
        $quantityScaled = (int) round(((float) $quantity) * 10000);

        // paisa * 10^6 / (qty * 10^4) = taka * 10^4.
        $numerator = $share->minor * 1000000;
        $result = intdiv($numerator, $quantityScaled);

        if (($numerator % $quantityScaled) * 2 >= $quantityScaled) {
            $result++;
        }

        return number_format($result / 10000, 4, '.', '');
    }

    /** A bare date is the start of that day; the future and a closed month are refused. */
    private function date(string $harvestedOn): Carbon
    {
        try {
            return EffectiveDate::resolve(Carbon::parse($harvestedOn)->startOfDay(), 'harvest');
        } catch (InvalidArgumentException|PeriodClosedException $e) {
            throw ValidationException::withMessages(['harvested_on' => $e->getMessage()]);
        }
    }

    private function nextNumber(int $businessId, Carbon $date): string
    {
        $business = Business::withoutGlobalScopes()->findOrFail($businessId);
        $prefix = 'HRV-'.FiscalYear::containing($date, $business)->label().'-';

        return DocumentNumber::next(CropHarvest::query(), 'number', $prefix);
    }
}
