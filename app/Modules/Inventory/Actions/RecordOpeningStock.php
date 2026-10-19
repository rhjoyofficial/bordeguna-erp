<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Enums\JournalType;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\LedgerRounding;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Corrections\ClosedPeriodPass;
use App\Modules\Inventory\Models\OpeningStock;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\BatchNumbers;
use App\Modules\Inventory\Support\EffectiveDate;
use App\Modules\Purchasing\Support\ReceivedBatchDates;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What the warehouse already held on the day the books start.
 *
 * ```
 * Dr  Stock on Hand              the value of the batches created
 *     Cr  Opening Balance Equity
 * ```
 *
 * ⚠⚠ POSTED AS AN OPENING BALANCE, not an ordinary journal. That type is what
 * lets a report tell the photograph of the business apart from a year of
 * trading, and it is what keeps the promise that **Opening Balance Equity reads
 * zero once every opening figure is in**. A trial balance can balance perfectly
 * and still be wrong; that account going to zero is the only check that catches
 * it.
 *
 * ⚠ Sales cost of goods sold is computed from the batches this creates, so the
 * unit costs entered here decide every margin the business reports until the
 * opening stock is sold through.
 */
class RecordOpeningStock
{
    public function __construct(
        private readonly RecordStockIn $recordStockIn,
        private readonly PostJournal $postJournal,
    ) {}

    /**
     * @param  array<int, array{
     *     product: Product,
     *     quantity: string,
     *     unitCost: string,
     *     batchNumber?: string|null,
     *     expiryDate?: string|null
     * }>  $lines
     *
     * @throws ValidationException
     */
    public function handle(
        Warehouse $warehouse,
        array $lines,
        User $createdBy,
        mixed $asAt = null,
        ?string $note = null,
    ): OpeningStock {
        // ⚠ Inventory's standard resolver: refuses a future date and asserts the
        // accounting period is open. Opening stock is dated in the PAST - the day
        // before the books start - so both rules are exactly right here, and
        // RecordStockIn applies the same one to every movement it writes.
        $date = EffectiveDate::resolve($asAt, 'as_at');

        if (! $warehouse->is_active) {
            throw ValidationException::withMessages([
                'warehouse_id' => 'That warehouse is no longer active.',
            ]);
        }

        // ⚠⚠ ONCE PER WAREHOUSE. The unique index is the real guarantee, but
        // refusing here gives a person a sentence instead of a constraint
        // violation. Opening stock entered twice doubles the inventory and the
        // trial balance still balances, because the second entry credits equity
        // just as correctly as the first.
        if (OpeningStock::where('warehouse_id', $warehouse->id)->exists()) {
            throw ValidationException::withMessages([
                // ⭐ Corrections wave 1 (20 Oct 2026): the way to fix it is the
                // opening stock's own Correct, into Opening Balance Equity; an
                // inventory adjustment would land in the write-off account.
                'warehouse_id' => "Opening stock has already been recorded for {$warehouse->name}. "
                    .'Use Correct on it rather than entering it again.',
            ]);
        }

        $lines = array_values(array_filter($lines, fn (array $line) => (float) $line['quantity'] > 0));

        if ($lines === []) {
            throw ValidationException::withMessages([
                'lines' => 'Opening stock needs at least one line with a quantity greater than zero.',
            ]);
        }

        // ⚠⚠ C-INV-E2. These keys must match what the screen renders, or the
        // refusal is real, correct, and completely invisible: the form appears
        // to do nothing at all, which reads as a broken button rather than a
        // rejected line. The screen names `lines.N.product_id` and
        // `lines.N.unit_cost`, so these do too. The action's INPUT keys are a
        // separate vocabulary and deliberately unchanged.
        foreach ($lines as $index => $line) {
            if (! $line['product']->type->isStocked()) {
                throw ValidationException::withMessages([
                    "lines.{$index}.product_id" => "{$line['product']->name} is a service and cannot hold stock.",
                ]);
            }

            if ((float) $line['unitCost'] < 0) {
                throw ValidationException::withMessages([
                    "lines.{$index}.unit_cost" => 'A unit cost cannot be negative.',
                ]);
            }

            // ⚠⚠ The goods-receipt rule, at the other door stock comes in by. A
            // product that tracks expiry counted in without its batch and expiry
            // is invisible to the Expiry screen, the nightly write-off sweep
            // and FEFO, which queues an undated batch behind every dated one.
            // ⭐ PR2 (18 Oct 2026): a blank batch number is no longer missing,
            // it is numbered automatically below (BatchNumbers), so only the
            // expiry can be.
            $missing = ReceivedBatchDates::required(
                (bool) $line['product']->tracks_expiry,
                trim((string) ($line['batchNumber'] ?? '')) === '' ? 'automatic' : $line['batchNumber'],
                $line['expiryDate'] ?? null,
            );

            if ($missing !== []) {
                throw ValidationException::withMessages(collect($missing)
                    ->mapWithKeys(fn (string $problem, string $field) => ["lines.{$index}.{$field}" => $problem])
                    ->all());
            }
        }

        return DB::transaction(function () use ($warehouse, $lines, $createdBy, $date, $note) {
            $opening = OpeningStock::create([
                'business_id' => $warehouse->business_id,
                'warehouse_id' => $warehouse->id,
                'as_at' => $date,
                'note' => $note,
                'created_by' => $createdBy->id,
            ]);

            foreach ($lines as $line) {
                // ⭐ PR2: numbered HERE, one line at a time inside the
                // transaction, so two lines of one product get -01 and -02.
                $line['batchNumber'] = BatchNumbers::resolve($line['product'], $line['batchNumber'] ?? null, $date);

                // ⚠ Through Inventory's own entry point, not a private route.
                // One line makes exactly one batch at a known cost, which is
                // what opening stock means.
                $movement = $this->recordStockIn->handle(
                    product: $line['product'],
                    warehouse: $warehouse,
                    quantity: $line['quantity'],
                    unitCost: $line['unitCost'],
                    performedBy: $createdBy,
                    batchNumber: $line['batchNumber'] ?? null,
                    expiryDate: $line['expiryDate'] ?? null,
                    note: 'Opening stock',
                    occurredAt: $date,
                );

                // Corrections wave 1: the movement names its opening stock.
                StockMovement::query()->whereKey($movement->id)->update(['opening_stock_id' => $opening->id]);

                $opening->lines()->create([
                    'business_id' => $opening->business_id,
                    'product_id' => $line['product']->id,
                    'quantity' => $line['quantity'],
                    'unit_cost' => $line['unitCost'],
                    'batch_number' => $line['batchNumber'] ?? null,
                    'expiry_date' => $line['expiryDate'] ?? null,
                    'stock_movement_id' => $movement->id,
                ]);
            }

            $this->postJournal($opening->fresh(['lines.stockMovement', 'warehouse']), $createdBy);

            return $opening->fresh(['lines.stockMovement', 'warehouse']);
        });
    }

    /**
     * ⚠ Anchored on `inventory.stock_on_hand`, like every other inventory
     * posting: a business whose ledger is not configured must still be able to
     * record what it holds.
     *
     * ⭐ Corrections wave 1 (20 Oct 2026): public, because a CORRECTED opening
     * stock (OpeningStockCorrector) posts its successor through exactly this,
     * so a correction lands in Opening Balance Equity on the opening date by
     * the same rule as the first entry, never in Inventory Write-offs. `$pass`
     * is the Owner's closed-month exception for that correction only.
     */
    public function postJournal(OpeningStock $opening, User $postedBy, ?ClosedPeriodPass $pass = null): ?Journal
    {
        $on = Carbon::parse($opening->as_at)->startOfDay();

        if (AccountMapping::forKeyOn('inventory.stock_on_hand', $on) === null) {
            return null;
        }

        $stock = AccountMapping::resolve('inventory.stock_on_hand', $on);

        // ⚠ Resolved by SLUG rather than a mapping key, deliberately. A mapping
        // key beginning `inventory.` would make Opening Balance Equity a
        // subsystem-owned account, and although opening-balance journals are
        // exempt from that guard, it would still block an accountant from
        // touching 3900 in any ordinary correction. The account is a fixed part
        // of the chart, not a per-business routing decision.
        $equity = Account::bySlug('opening_balance_equity');

        if ($equity === null) {
            throw MappingNotFound::for('opening_balance_equity', $on);
        }

        $lines = [];
        $total = Money::zero();

        foreach ($opening->lines as $line) {
            $value = $line->value();

            if ($value->isZero()) {
                continue;
            }

            $lines[] = JournalLineDraft::debit($stock->id, $value);
            $total = $total->plus($value);
        }

        // ⚠ Opening stock received at no cost is real - donated or sampled
        // goods. The quantity still exists; there is simply nothing to post.
        if ($lines === [] || $total->isZero()) {
            return null;
        }

        $lines[] = JournalLineDraft::credit($equity->id, $total, 'Opening stock');
        $lines = LedgerRounding::balance($lines, $on, "Rounding on opening stock #{$opening->id}");

        return $this->postJournal->handle(new JournalDraft(
            date: $on,
            lines: $lines,
            memo: "Opening stock - {$opening->warehouse->name}",
            source: $opening,
            type: JournalType::OpeningBalance,
        ), $postedBy, pass: $pass);
    }
}
