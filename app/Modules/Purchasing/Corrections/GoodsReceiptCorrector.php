<?php

namespace App\Modules\Purchasing\Corrections;

use App\Models\User;
use App\Modules\Accounting\Actions\ReverseJournal;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Corrections\CommitContext;
use App\Modules\Foundation\Corrections\Contracts\DocumentActionPolicy;
use App\Modules\Foundation\Corrections\Contracts\DocumentCorrector;
use App\Modules\Foundation\Corrections\CorrectionDates;
use App\Modules\Foundation\Corrections\CorrectionLog;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectLine;
use App\Modules\Foundation\Corrections\ReplacementData;
use App\Modules\Foundation\Corrections\Tk;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Inventory\Actions\PostStockCostDifference;
use App\Modules\Inventory\Actions\RedateStockReceipt;
use App\Modules\Inventory\Actions\TakeBackStockReceipt;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Inventory\Support\BatchHistory;
use App\Modules\Inventory\Support\BatchNumbers;
use App\Modules\Inventory\Support\PurchaseUnitConversion;
use App\Modules\Inventory\Support\SoldStock;
use App\Modules\Inventory\Support\StockValue;
use App\Modules\Purchasing\Actions\PostPurchaseJournals;
use App\Modules\Purchasing\Actions\RecordGoodsReceipt;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Purchasing\Models\LandedCost;
use App\Modules\Purchasing\Models\LandedCostAllocation;
use App\Modules\Purchasing\Models\LandedCostLine;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierBillLine;
use App\Modules\Purchasing\Models\SupplierDiscountAllocation;
use App\Modules\Purchasing\Support\PurchaseLineEntry;
use App\Modules\Purchasing\Support\ReceiptJournals;
use App\Modules\Purchasing\Support\ReceivedBatchDates;
use App\Modules\Purchasing\Support\SupplierChallans;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use Spatie\Activitylog\Models\Activity;

/**
 * ⭐ A goods receipt's own reversal and correction (corrections wave 1,
 * 19 Oct 2026; FINAL-PLAN 3.3).
 *
 * The trigger: two hilsa receipts posted 18 Oct for goods that arrived on
 * 4 Aug and 30 Sep, already billed and paid; a sale of 5 Aug could not be
 * delivered because the stock "did not exist" until 18 Oct. No screen could
 * fix the date.
 *
 *   Reverse   the receipt journal is reversed on the receipt's own date; each
 *             batch it made gives back every unit (TakeBackStockReceipt, only
 *             while untouched); each order line gets its quantity back, so the
 *             order can receive again.
 *
 *   Correct, DATE (the quantity and the lot unchanged; the batch details may
 *             change with it): the SAME batch is kept, because it is the same
 *             physical lot and later sales already point at it.
 *              - the receipt journal is reversed on its own date;
 *              - RedateStockReceipt writes the batch's new `in` on the true
 *                date and an `out` undoing the old one on the old date, and
 *                moves the batch's `received_at`;
 *              - the corrected receipt is a NEW receipt (its own number, the
 *                label "Correction of Receipt #n") whose lines point at the
 *                new movements, and its journal posts on the true date;
 *              - the bills that named the old receipt's lines now name the
 *                corrected receipt's: the bill, its journal and its payments
 *                do not change at all.
 *             Allowed when billed, paid and partly sold. Refused only when a
 *             withdrawal from the batch would then come before the stock
 *             existed (the receipt moved LATER than a sale).
 *
 *   Correct, QUANTITY or LOT: the goods go back out (as Reverse) and the right
 *             receipt comes in through RecordGoodsReceipt (a new batch, its own
 *             journal). Only while untouched and unbilled; a closed month only
 *             through its date.
 *
 *   ⭐ 24 Oct 2026 (wave 1 gaps, the Tk 10,000 rule):
 *   Reverse, goods partly sold or used: what is left goes back out of the
 *             batch on the receipt's own date (TakeBackStockReceipt with the
 *             remainder); the sales keep the cost they went out at; the
 *             difference between the ledger (the whole receipt reversed) and
 *             the stock (only the rest taken back) posts TODAY to Stock Cost
 *             Corrections on Goods Sold (PostStockCostDifference). Above
 *             Tk 10,000, only the Owner (GoodsReceiptActionPolicy).
 *   Correct, QUANTITY, goods partly sold or used (MODE_RESIZE): the SAME batch
 *             is kept (the sales point at it) and the quantity it received is
 *             corrected there, at the same unit cost (RedateStockReceipt with
 *             a quantity), never below what was already used at any point. No
 *             cost moves on the sold units, so no cost difference arises. The
 *             lot of a used batch cannot change. Unbilled only, as before.
 *   Landed costs and supplier discounts no longer block a DATE correction:
 *             the batches stay, and their links move to the corrected receipt
 *             as the bills' do. A quantity or lot Correct still waits for them
 *             (GoodsReceiptAllocationCheck::costChanged says what to undo).
 *
 * ⚠⚠ Contract note: `reverse()` is not told whether it is a plain Reverse or
 * the first half of a Correct. A Correct always carries a successor date and a
 * Reverse never does (CorrectionDates), so that is how it tells; the stock is
 * then moved by `createSuccessor()`, which knows which kind of Correct it is.
 *
 * ⚠ The receipt's cost is the order's price: a wrong price is not corrected
 * here (see GoodsReceiptActionPolicy).
 */
class GoodsReceiptCorrector implements DocumentCorrector
{
    public const MODE_REDATE = 'redate';

    public const MODE_REPLACE = 'replace';

    public const MODE_RESIZE = 'resize';

    public function __construct(
        private readonly GoodsReceiptActionPolicy $policy,
        private readonly ReverseJournal $reverseJournal,
        private readonly PostPurchaseJournals $posting,
        private readonly RedateStockReceipt $redate,
        private readonly TakeBackStockReceipt $takeBack,
        private readonly RecordGoodsReceipt $recordReceipt,
        private readonly PostStockCostDifference $costDifference,
    ) {}

    public function policy(): DocumentActionPolicy
    {
        return $this->policy;
    }

    public function find(int $documentId): ?Model
    {
        return GoodsReceipt::query()->find($documentId);
    }

    public function lockForCommit(int $documentId): Model
    {
        $receipt = GoodsReceipt::query()->whereKey($documentId)->lockForUpdate()->firstOrFail();

        PurchaseOrder::query()->whereKey($receipt->purchase_order_id)->lockForUpdate()->get(['id']);
        $lines = GoodsReceiptLine::query()->where('goods_receipt_id', $receipt->id)->lockForUpdate()->get();
        PurchaseOrderLine::query()->whereIn('id', $lines->pluck('purchase_order_line_id'))->lockForUpdate()->get(['id']);

        $batchIds = StockMovement::query()->whereIn('id', $lines->pluck('stock_movement_id')->filter())->pluck('batch_id');
        StockBatch::query()->whereIn('id', $batchIds)->lockForUpdate()->get(['id']);

        Journal::query()
            ->where('source_type', $receipt->getMorphClass())
            ->where('source_id', $receipt->getKey())
            ->lockForUpdate()
            ->get(['id']);

        return $receipt->load(['lines.stockMovement.batch', 'lines.purchaseOrderLine.product', 'purchaseOrder']);
    }

    public function fingerprint(Model $document): string
    {
        /** @var GoodsReceipt $document */
        $lines = GoodsReceiptLine::query()->where('goods_receipt_id', $document->id)->orderBy('id')
            ->with('stockMovement.batch')
            ->get()
            ->map(fn (GoodsReceiptLine $line) => [
                $line->id, $line->purchase_order_line_id, (string) $line->quantity_received, $line->stock_movement_id,
                $line->stockMovement?->batch === null ? null : [
                    (string) $line->stockMovement->batch->current_quantity,
                    $line->stockMovement->batch->received_at?->toIso8601String(),
                    $line->stockMovement->batch->batch_number,
                    $line->stockMovement->batch->expiry_date?->toDateString(),
                    $line->stockMovement->batch->manufactured_date?->toDateString(),
                    StockMovement::query()->where('batch_id', $line->stockMovement->batch_id)->max('id'),
                ],
            ])->all();

        $journals = Journal::query()
            ->where('source_type', $document->getMorphClass())
            ->where('source_id', $document->getKey())
            ->orderBy('id')
            ->get(['id', 'reverses_journal_id'])
            ->map(fn (Journal $j) => [$j->id, $j->reverses_journal_id])
            ->all();

        $bills = SupplierBillLine::query()
            ->whereIn('goods_receipt_line_id', array_column($lines, 0))
            ->with('bill:id,reversed_at')
            ->orderBy('id')
            ->get()
            ->map(fn ($line) => [$line->id, $line->bill?->reversed_at?->toIso8601String()])
            ->all();

        $lock = AccountingPeriod::current();

        return hash('sha256', (string) json_encode([
            'r' => [
                $document->id, $document->purchase_order_id, $document->received_at?->toIso8601String(), $document->supplier_challan_number,
                $document->note, $document->reversed_at?->toIso8601String(), $document->details_fixed_at?->toIso8601String(),
            ],
            'lines' => $lines,
            'journals' => $journals,
            'bills' => $bills,
            'period' => [$lock?->closed_through?->toDateString(), (bool) $lock?->is_unlocked],
        ]));
    }

    /**
     * The edited receipt, checked as receiving checks it, plus what only a
     * correction must check (see the class note for the two kinds).
     *
     * Input: received_on, supplier_challan_number, note, and per line of the
     * receipt: id, quantity, batch_number, manufactured_date, expiry_date,
     * different_lot.
     */
    public function validateReplacement(Model $document, array $input): ReplacementData
    {
        /** @var GoodsReceipt $document */
        $document->loadMissing(['lines.stockMovement.batch', 'lines.purchaseOrderLine.product', 'purchaseOrder.supplier']);

        $data = Validator::make($input, [
            'received_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'supplier_challan_number' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0'],
            'lines.*.batch_number' => ['nullable', 'string', 'max:100'],
            'lines.*.manufactured_date' => ['nullable', 'date_format:Y-m-d'],
            'lines.*.expiry_date' => ['nullable', 'date_format:Y-m-d'],
            'lines.*.different_lot' => ['nullable', 'boolean'],
        ], [
            'received_on.before_or_equal' => 'A receipt cannot be dated in the future.',
        ], [
            'received_on' => 'received on',
            'lines.*.quantity' => 'quantity',
            'lines.*.batch_number' => 'batch number',
            'lines.*.manufactured_date' => 'manufacture date',
            'lines.*.expiry_date' => 'expiry date',
        ])->validate();

        $refuse = fn (string $sentence) => throw ValidationException::withMessages(['replacement' => $sentence]);
        $date = Carbon::parse($data['received_on'])->startOfDay();
        $original = $this->originalDate($document);
        $byId = collect($data['lines'])->keyBy(fn ($line) => (int) $line['id']);

        if ($byId->keys()->sort()->values()->all() !== $document->lines->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all()) {
            $refuse('The corrected receipt must list exactly the lines of the original. Reload the page and try again.');
        }

        $lines = [];
        $physical = false;
        $labelChanged = false;

        foreach ($document->lines as $line) {
            /** @var GoodsReceiptLine $line */
            $typed = $byId[(int) $line->id];
            $product = $line->purchaseOrderLine?->product;
            $batch = $line->stockMovement?->batch;
            $quantity = number_format((float) $typed['quantity'], 4, '.', '');
            $sameQuantity = PurchaseLineEntry::scaled($quantity) === PurchaseLineEntry::scaled((string) $line->quantity_received);
            $differentLot = (bool) ($typed['different_lot'] ?? false);
            $physical = $physical || ! $sameQuantity || ($differentLot && $batch !== null);

            $label = [
                'batch_number' => $this->text($typed['batch_number'] ?? null),
                'manufactured_date' => $this->text($typed['manufactured_date'] ?? null),
                'expiry_date' => $this->text($typed['expiry_date'] ?? null),
            ];

            if ($batch !== null) {
                $now = [
                    'batch_number' => $batch->batch_number,
                    'manufactured_date' => $batch->manufactured_date?->toDateString(),
                    'expiry_date' => $batch->expiry_date?->toDateString(),
                ];
                $labelChanged = $labelChanged || $label !== $now;

                // ⚠ The trigger case: a label left blank at the door was made
                // "manufactured on the receipt date" (BatchNumbers::label), with
                // the expiry from the shelf life. Those were never on the jar;
                // they follow the receipt to its true day. A typed one stays.
                if ($product !== null && ! $date->isSameDay($original)
                    && $label['manufactured_date'] === $now['manufactured_date'] && $now['manufactured_date'] === $original->toDateString()) {
                    $label['manufactured_date'] = $date->toDateString();

                    if ($label['expiry_date'] === $now['expiry_date'] && $now['expiry_date'] === BatchNumbers::defaultExpiry($product, $now['manufactured_date'])) {
                        $label['expiry_date'] = BatchNumbers::defaultExpiry($product, $label['manufactured_date']);
                    }
                }

                if ($product !== null && (float) $quantity > 0) {
                    $name = "{$product->name} ({$product->sku})";

                    foreach (ReceivedBatchDates::problems($label['manufactured_date'], $label['expiry_date'], $date) as $problem) {
                        $refuse("{$name}: {$problem}");
                    }

                    foreach (ReceivedBatchDates::required((bool) $product->tracks_expiry, $label['batch_number'], $label['expiry_date']) as $problem) {
                        $refuse("{$name}: {$problem}");
                    }
                }
            }

            $lines[(int) $line->id] = ['quantity' => $quantity, 'different_lot' => $differentLot] + $label;
        }

        $challan = $this->text($data['supplier_challan_number'] ?? null);
        $note = $this->text($data['note'] ?? null);
        $dateChanged = ! $date->isSameDay($original);

        if (! $physical && ! $dateChanged) {
            $refuse($labelChanged || $challan !== $this->text($document->supplier_challan_number) || $note !== $this->text($document->note)
                ? 'Only the batch details, challan or note changed. Use Fix batch details for that: it needs no correction.'
                : 'Nothing was changed. Change the date, a quantity or the lot, or go back.');
        }

        if (array_sum(array_map(fn ($l) => PurchaseLineEntry::scaled($l['quantity']), $lines)) <= 0) {
            $refuse('A corrected receipt brings at least one thing in. To undo the whole receipt, use Reverse.');
        }

        $duplicate = SupplierChallans::duplicateOf((int) $document->purchaseOrder?->supplier_id, $challan, (int) $document->id);

        if ($duplicate !== null) {
            $refuse("Challan {$challan} from {$document->purchaseOrder?->supplier?->name} was already received on ".($duplicate->received_at?->format('j M Y') ?? 'an earlier day').' on another receipt.');
        }

        // ⭐ 24 Oct 2026: a quantity change of goods partly sold or used keeps
        // the batch (MODE_RESIZE); untouched goods still come in anew.
        $mode = match (true) {
            ! $physical => self::MODE_REDATE,
            GoodsReceiptStockCheck::touched($document) === null => self::MODE_REPLACE,
            default => self::MODE_RESIZE,
        };

        match ($mode) {
            self::MODE_REDATE => $this->checkRedate($document, $date, $original),
            self::MODE_REPLACE => $this->checkReplace($document, $date, $original, $lines),
            self::MODE_RESIZE => $this->checkResize($document, $date, $lines),
        };

        $total = $mode === self::MODE_REDATE
            ? $this->totalMinor($document)
            : $document->lines->sum(fn (GoodsReceiptLine $line) => $line->purchaseOrderLine === null
                ? 0
                : $line->purchaseOrderLine->valueOf($lines[(int) $line->id]['quantity'])->minor);

        return new ReplacementData(
            date: $date,
            totalMinor: (int) $total,
            values: ['mode' => $mode, 'lines' => $lines, 'challan' => $challan, 'note' => $note],
            input: $data,
        );
    }

    public function replacementInput(ReplacementData $replacement): array
    {
        return $replacement->input;
    }

    public function describeEffects(Model $document, DocumentAction $action, ?ReplacementData $replacement, CorrectionDates $dates): array
    {
        /** @var GoodsReceipt $document */
        $document->loadMissing(['lines.stockMovement.batch', 'lines.purchaseOrderLine.product.unitOfMeasure', 'purchaseOrder']);
        $value = $this->totalMinor($document);
        $on = Tk::date($dates->reversalDate);
        $number = $this->policy->numberOf($document);
        $lines = [];

        if ($replacement !== null && $replacement->values['mode'] === self::MODE_REDATE) {
            $new = Tk::date($replacement->date);
            $lines[] = new EffectLine("{$number} moves from {$on} to {$new}: its batches, their stock movements and its ledger entry move with it.", null, null, 'stock');

            foreach ($document->lines as $line) {
                if (($batch = $line->stockMovement?->batch) !== null) {
                    $lines[] = new EffectLine('Batch '.($batch->batch_number ?? "#{$batch->id}")." counts as received on {$new}, so it can be sold from that day.", null, null, 'stock');
                }
            }

            $bills = SupplierBillLine::query()
                ->whereIn('goods_receipt_line_id', $document->lines->pluck('id'))
                ->whereHas('bill', fn ($bill) => $bill->whereNull('reversed_at'))
                ->with('bill:id,bill_number')
                ->get()
                ->pluck('bill.bill_number')
                ->unique()
                ->filter();

            if ($bills->isNotEmpty()) {
                $lines[] = new EffectLine('Bill '.$bills->implode(', ').' now points at the corrected receipt; nothing on it, and nothing paid, changes.', null, null, 'allocation');
            }

            $lines[] = new EffectLine('The quantity, the cost and the value ('.Tk::of($value).') stay exactly as they were.', null, 0, 'ledger');

            $orderDate = $document->purchaseOrder?->order_date;

            if ($orderDate !== null && $replacement->date->lt($orderDate)) {
                $lines[] = new EffectLine("This is before the order date ({$orderDate->format('j M Y')}): check the dates, or fix the order's date with Fix details.");
            }

            return $lines;
        }

        if ($replacement !== null && $replacement->values['mode'] === self::MODE_RESIZE) {
            $new = Tk::date($replacement->date);

            foreach ($document->lines as $line) {
                $batch = $line->stockMovement?->batch;
                $typed = $replacement->values['lines'][(int) $line->id]['quantity'] ?? (string) $line->quantity_received;
                $product = $line->purchaseOrderLine?->product;

                if ($batch !== null && PurchaseLineEntry::scaled($typed) !== PurchaseLineEntry::scaled((string) $line->quantity_received)) {
                    $lines[] = new EffectLine('Batch '.($batch->batch_number ?? "#{$batch->id}")." counts as receiving {$this->qty($typed)} of {$product?->name} instead of {$this->qty((string) $line->quantity_received)}, on {$new}. What was already sold from it stays sold, at the same cost.", null, null, 'stock');
                }
            }

            $lines[] = new EffectLine(Tk::of($value)." comes off Stock on Hand and Goods Received Not Invoiced on {$on}, and ".Tk::of($replacement->totalMinor)." goes back on, on {$new}.", 'Stock on Hand', $replacement->totalMinor - $value, 'ledger');

            return $lines;
        }

        if ($value > 0) {
            $lines[] = new EffectLine(Tk::of($value)." comes off Stock on Hand and Goods Received Not Invoiced on {$on}.", 'Stock on Hand', -$value, 'ledger');
        }

        // ⭐ 24 Oct 2026: a plain Reverse of goods partly sold or used.
        if ($replacement === null) {
            $shares = GoodsReceiptStockCheck::shares($document);

            foreach ($document->lines as $line) {
                $share = $shares[(int) $line->id] ?? null;

                if ($share !== null && $share->isUsed()) {
                    $product = $line->purchaseOrderLine?->product;
                    $unit = $product?->unitOfMeasure?->abbreviation;
                    $lines[] = new EffectLine("Only what is left ({$this->qty($share->remaining)} {$unit}) goes back out of batch {$share->name()}.", null, null, 'stock');
                    $lines[] = new EffectLine(SoldStock::effectSentence($share->costMinor, $share->used, $unit, $product?->name), 'Stock Cost Corrections on Goods Sold', -$share->costMinor, 'ledger');
                }
            }
        }

        foreach ($document->lines as $line) {
            $product = $line->purchaseOrderLine?->product;
            $unit = $product?->unitOfMeasure?->abbreviation;
            $batch = $line->stockMovement?->batch;

            if ($batch !== null && ($replacement !== null || bccomp((string) $batch->current_quantity, (string) $line->stockMovement->quantity, 4) === 0)) {
                $lines[] = new EffectLine("{$this->qty((string) $line->stockMovement->quantity)} {$unit} of {$product?->name} go back out of batch ".($batch->batch_number ?? "#{$batch->id}").'.', null, null, 'stock');
            }

            $lines[] = new EffectLine("{$document->purchaseOrder?->po_number} can receive {$this->qty((string) $line->quantity_received)} of {$product?->name} again.");
        }

        if ($replacement !== null) {
            $new = Tk::date($replacement->date);

            foreach ($document->lines as $line) {
                $quantity = $replacement->values['lines'][(int) $line->id]['quantity'] ?? '0';

                if ((float) $quantity > 0) {
                    $lines[] = new EffectLine("A new receipt brings in {$this->qty($quantity)} of {$line->purchaseOrderLine?->product?->name} on {$new}, in a new batch.", null, null, 'stock');
                }
            }

            $lines[] = new EffectLine(Tk::of($replacement->totalMinor)." goes onto Stock on Hand and Goods Received Not Invoiced on {$new}.", 'Stock on Hand', $replacement->totalMinor, 'ledger');
        }

        return $lines;
    }

    public function describeStays(Model $document, DocumentAction $action): array
    {
        $number = $this->policy->numberOf($document);

        return $action === DocumentAction::Correct
            ? ["{$number} stays, marked Corrected.", "The corrected receipt gets a new number and the label \"Correction of {$number}\".", 'The reversal gets its own JV number.']
            : ["{$number} stays, marked Reversed.", 'The reversal gets its own JV number.'];
    }

    public function originalDate(Model $document): Carbon
    {
        /** @var GoodsReceipt $document */
        return $this->policy->originalDate($document);
    }

    public function totalMinor(Model $document): int
    {
        /** @var GoodsReceipt $document */
        return $this->policy->valueMinor($document);
    }

    public function makerAndLastEditorIds(Model $document): array
    {
        $lastEditor = Activity::query()
            ->where('subject_type', $document->getMorphClass())
            ->where('subject_id', $document->getKey())
            ->whereIn('event', ['updated', 'fix_batch_details'])
            ->whereNotNull('causer_id')
            ->latest('id')
            ->value('causer_id');

        return array_values(array_unique(array_filter([(int) $document->received_by, $lastEditor === null ? null : (int) $lastEditor])));
    }

    public function journalOf(Model $document): ?Journal
    {
        /** @var GoodsReceipt $document */
        return ReceiptJournals::live($document);
    }

    public function reverse(Model $document, CommitContext $ctx): ?Journal
    {
        /** @var GoodsReceipt $document */
        $journal = $this->journalOf($document);
        $reversal = null;
        $number = $this->policy->numberOf($document);

        if ($journal !== null) {
            try {
                $reversal = $this->reverseJournal->handle(
                    $journal->load('lines'),
                    $ctx->actor,
                    $ctx->dates->reversalDate,
                    "Reversal of {$number}: {$ctx->reason->display()}",
                    $ctx->pass,
                );
            } catch (PeriodClosedException $e) {
                throw ValidationException::withMessages(['reason_text' => $e->getMessage()]);
            }
        }

        // ⚠ Marked NOW, before the successor is written: its challan, its
        // billable lines and its order quantities must stop counting first.
        $this->markOriginal($document, $reversal, $ctx);

        // A plain Reverse has no successor date (see the class note).
        if ($ctx->dates->successorDate === null) {
            $this->takeGoodsBack($document, $ctx);
        }

        return $reversal;
    }

    public function createSuccessor(Model $document, ReplacementData $replacement, CommitContext $ctx): Model
    {
        /** @var GoodsReceipt $document */
        return match ($replacement->values['mode']) {
            self::MODE_REDATE => $this->redateInto($document, $replacement, $ctx),
            self::MODE_RESIZE => $this->resizeInto($document, $replacement, $ctx),
            default => $this->receiveAgain($document, $replacement, $ctx),
        };
    }

    public function journalOfSuccessor(Model $successor): ?Journal
    {
        /** @var GoodsReceipt $successor */
        return ReceiptJournals::live($successor);
    }

    public function markOriginal(Model $document, ?Journal $reversal, CommitContext $ctx): void
    {
        /** @var GoodsReceipt $document */
        $document->forceFill([
            'reversed_on' => $ctx->dates->reversalDate,
            'reversed_at' => $document->reversed_at ?? now(),
            'reversed_by' => $ctx->actor->id,
            'reversal_reason' => $ctx->reason->display(),
            'reversal_journal_id' => $reversal?->id ?? $document->reversal_journal_id,
        ])->save();
    }

    public function successorClass(): string
    {
        return GoodsReceipt::class;
    }

    /**
     * This type's own invariants: marked; no live journal left on it; no live
     * bill still names its lines; a plain Reverse emptied every batch it made;
     * a date correction carried the same value onto the same batches, now
     * received on the corrected day.
     */
    public function assertInvariants(Model $original, ?Model $successor): void
    {
        /** @var GoodsReceipt $original */
        $number = $this->policy->numberOf($original);

        if (! $original->isReversed()) {
            throw new LogicException("{$number} was not marked.");
        }

        if (ReceiptJournals::live($original) !== null) {
            throw new LogicException("{$number} still has a live journal.");
        }

        $lineIds = $original->lines()->pluck('id');

        if (SupplierBillLine::query()->whereIn('goods_receipt_line_id', $lineIds)->whereHas('bill', fn ($b) => $b->whereNull('reversed_at'))->exists()) {
            throw new LogicException("A live bill still names {$number}.");
        }

        $original->load('lines.stockMovement.batch');

        if ($successor === null) {
            foreach ($original->lines as $line) {
                if ($line->stockMovement?->batch !== null && bccomp((string) $line->stockMovement->batch->current_quantity, '0', 4) !== 0) {
                    throw new LogicException("{$number} left goods in a batch it took back.");
                }
            }

            return;
        }

        /** @var GoodsReceipt $successor */
        $successor->load('lines.stockMovement.batch');
        $originalJournal = Journal::query()->where('source_type', $original->getMorphClass())->where('source_id', $original->id)->whereNull('reverses_journal_id')->orderByDesc('id')->first();
        $successorJournal = ReceiptJournals::live($successor);
        $debits = fn (?Journal $j) => $j === null ? 0 : (int) $j->lines()->where('amount_minor', '>', 0)->sum('amount_minor');

        $stockValue = $successor->lines->sum(fn ($line) => $line->stockMovement === null ? 0 : Money::ofProduct((string) $line->stockMovement->quantity, (string) $line->stockMovement->unit_cost)->minor);

        if ($successorJournal !== null && $debits($successorJournal) < $stockValue) {
            throw new LogicException('The corrected receipt\'s journal does not carry its stock.');
        }

        $redated = $successor->lines->contains(fn ($line) => $line->stockMovement !== null
            && $original->lines->contains(fn ($old) => $old->stockMovement?->batch_id === $line->stockMovement->batch_id));

        // ⭐ 24 Oct 2026: a quantity correction of a used batch keeps the batch
        // but not the value; its journal carries its own stock instead.
        $resized = $successor->lines->contains(fn ($line) => $line->stockMovement !== null
            && $original->lines->contains(fn ($old) => $old->stockMovement?->batch_id === $line->stockMovement->batch_id
                && bccomp((string) $old->stockMovement->quantity, (string) $line->stockMovement->quantity, 4) !== 0));

        if ($redated) {
            if (! $resized && $debits($successorJournal) !== $debits($originalJournal)) {
                throw new LogicException('The re-dated receipt does not carry the same value as the original.');
            }

            foreach ($successor->lines as $line) {
                if ($line->stockMovement?->batch !== null && ! $line->stockMovement->batch->received_at->isSameDay($successor->received_at)) {
                    throw new LogicException('A re-dated batch does not carry the corrected date.');
                }
            }
        }
    }

    public function visibleTo(Model $document, User $user): bool
    {
        return $this->policy->canView($document, $user);
    }

    // ─── the two kinds of Correct ──────────────────────────────────────────

    /** The SAME batches, moved to the true date; the bills follow the receipt. */
    private function redateInto(GoodsReceipt $original, ReplacementData $replacement, CommitContext $ctx): GoodsReceipt
    {
        $original->loadMissing(['lines.stockMovement.batch']);
        $number = $this->policy->numberOf($original);
        $note = "Correction of {$number}: {$ctx->reason->display()}";
        $date = $replacement->date->copy()->startOfDay();

        $successor = GoodsReceipt::create([
            'business_id' => $original->business_id,
            'purchase_order_id' => $original->purchase_order_id,
            // The goods were received by the same person; the correction's
            // author is on the link and in the History.
            'received_by' => $original->received_by,
            'received_at' => $date,
            'supplier_challan_number' => $replacement->values['challan'],
            'backdate_reason' => mb_substr($note, 0, 500),
            'note' => $replacement->values['note'],
        ]);

        foreach ($original->lines as $line) {
            $typed = $replacement->values['lines'][(int) $line->id];
            $movement = null;

            if ($line->stockMovement !== null) {
                try {
                    $movement = $this->redate->handle(
                        $line->stockMovement,
                        $date,
                        $ctx->actor,
                        mb_substr($note, 0, 250),
                        $ctx->pass,
                        ['batch_number' => $typed['batch_number'], 'manufactured_date' => $typed['manufactured_date'], 'expiry_date' => $typed['expiry_date']],
                    );
                } catch (InvalidArgumentException|PeriodClosedException $e) {
                    throw ValidationException::withMessages(['replacement' => $e->getMessage()]);
                }
            }

            $successorLine = $successor->lines()->create([
                'business_id' => $original->business_id,
                'purchase_order_line_id' => $line->purchase_order_line_id,
                'quantity_received' => $line->quantity_received,
                'stock_movement_id' => $movement?->id,
                'market_price_status' => $line->market_price_status,
                'market_price_history_id' => $line->market_price_history_id,
            ]);

            // ⭐ 24 Oct 2026: the landed costs and supplier discounts on these
            // batches stay where they are (same batches, same value); their
            // links follow the receipt that stands, as the bills' do.
            $this->moveCostLinks($original, $line, $successor, $successorLine, $ctx);

            // ⚠ A link, not money: the bill, its journal and its payments
            // stay exactly as they are; they now name the receipt that stands.
            $billLines = SupplierBillLine::query()->where('goods_receipt_line_id', $line->id)->get(['id', 'supplier_bill_id']);

            if ($billLines->isNotEmpty()) {
                SupplierBillLine::query()->whereIn('id', $billLines->pluck('id'))->update(['goods_receipt_line_id' => $successorLine->id]);

                foreach (SupplierBill::query()->whereIn('id', $billLines->pluck('supplier_bill_id')->unique())->get() as $bill) {
                    CorrectionLog::event('receipt_link_moved', $ctx->actor, $bill, [
                        'from' => $number,
                        'to' => "Receipt #{$successor->id}",
                        'reason' => $ctx->reason->display(),
                    ]);
                }
            }
        }

        $this->posting->forReceipt($successor->fresh('lines'), $ctx->actor, $ctx->pass);

        return $successor->fresh('lines');
    }

    /** The goods back out, and the right receipt in, through receiving itself. */
    private function receiveAgain(GoodsReceipt $original, ReplacementData $replacement, CommitContext $ctx): GoodsReceipt
    {
        $this->takeGoodsBack($original, $ctx);

        $order = PurchaseOrder::query()->with(['lines.product', 'warehouse', 'approvalRequest', 'supplier'])->findOrFail($original->purchase_order_id);
        $number = $this->policy->numberOf($original);
        $entries = [];

        foreach ($original->lines as $line) {
            $typed = $replacement->values['lines'][(int) $line->id];

            if ((float) $typed['quantity'] <= 0) {
                continue;
            }

            $entries[] = [
                'line' => $order->lines->firstWhere('id', $line->purchase_order_line_id),
                'quantity' => $typed['quantity'],
                'batch_number' => $typed['batch_number'],
                'manufactured_date' => $typed['manufactured_date'],
                'expiry_date' => $typed['expiry_date'],
            ];
        }

        try {
            return $this->recordReceipt->handle(
                $order,
                $entries,
                $ctx->actor,
                $replacement->values['note'],
                $replacement->values['challan'],
                $replacement->date->toDateString(),
                mb_substr("Correction of {$number}: {$ctx->reason->display()}", 0, 500),
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['replacement' => $e->getMessage()]);
        }
    }

    /**
     * Every unit back out of its batch, and the order lines get their quantity
     * back. ⭐ 24 Oct 2026: of goods partly sold or used, what is LEFT goes back
     * out, and the cost difference posts today (the Tk 10,000 rule).
     */
    private function takeGoodsBack(GoodsReceipt $receipt, CommitContext $ctx): void
    {
        $receipt->loadMissing(['lines.stockMovement']);
        $note = mb_substr("Reversal of {$this->policy->numberOf($receipt)}: {$ctx->reason->display()}", 0, 250);
        $shares = GoodsReceiptStockCheck::shares($receipt);
        $partly = GoodsReceiptStockCheck::touched($receipt) !== null;
        $difference = 0;
        $usedQuantity = '0';

        foreach ($receipt->lines as $line) {
            if ($line->stockMovement !== null) {
                // Same instant as the receipt on its own day, so the batch's
                // history never dips below zero; Today when that was chosen.
                $on = $ctx->dates->reversalDate->isSameDay(Carbon::parse($line->stockMovement->occurred_at))
                    ? Carbon::parse($line->stockMovement->occurred_at)
                    : now();
                $share = $shares[(int) $line->id] ?? null;

                if ($partly && $share?->refusal !== null) {
                    throw ValidationException::withMessages(['reason_text' => $share->refusal]);
                }

                try {
                    $out = match (true) {
                        ! $partly || $share === null => $this->takeBack->handle($line->stockMovement, $ctx->actor, $note, $on, $ctx->pass),
                        $share->hasRemainder() => $this->takeBack->handle($line->stockMovement, $ctx->actor, $note, $on, $ctx->pass, $share->remaining),
                        default => null,
                    };
                } catch (InvalidArgumentException|PeriodClosedException $e) {
                    throw ValidationException::withMessages(['reason_text' => $e->getMessage()]);
                }

                // The ledger takes the whole receipt back (its journal is
                // mirrored); the batch gives back only what is left. The gap is
                // what the used units cost, to the paisa.
                if ($partly && $share !== null) {
                    $difference += Money::ofProduct((string) $line->stockMovement->quantity, (string) $line->stockMovement->unit_cost)->minor
                        + ($out === null ? 0 : StockValue::of($out)->minor);
                    $usedQuantity = bcadd($usedQuantity, $share->used, 4);
                }
            }

            $orderLine = PurchaseOrderLine::query()->whereKey($line->purchase_order_line_id)->lockForUpdate()->first();

            if ($orderLine !== null) {
                $left = PurchaseLineEntry::scaled((string) $orderLine->quantity_received) - PurchaseLineEntry::scaled((string) $line->quantity_received);
                $orderLine->update(['quantity_received' => number_format(max(0, $left) / 10000, 4, '.', '')]);
            }
        }

        $this->recomputeOrderStatus((int) $receipt->purchase_order_id);

        if ($difference !== 0) {
            if (($owner = SoldStock::ownerBlocker($difference, $ctx->actor, $ctx->approver)) !== null) {
                throw ValidationException::withMessages(['reason_text' => $owner->message]);
            }

            $this->costDifference->handle($receipt, $this->policy->numberOf($receipt), $difference, $usedQuantity, $note, $ctx->actor, $ctx->approver);
        }
    }

    /** ⭐ 24 Oct 2026: landed cost and supplier discount links follow a re-dated receipt line. */
    private function moveCostLinks(GoodsReceipt $original, GoodsReceiptLine $line, GoodsReceipt $successor, GoodsReceiptLine $successorLine, CommitContext $ctx): void
    {
        $moved = LandedCost::query()->where('goods_receipt_id', $original->id)->update(['goods_receipt_id' => $successor->id])
            + LandedCostLine::query()->where('goods_receipt_line_id', $line->id)->update(['goods_receipt_line_id' => $successorLine->id])
            + LandedCostAllocation::query()->where('goods_receipt_line_id', $line->id)->update(['goods_receipt_line_id' => $successorLine->id])
            + SupplierDiscountAllocation::query()->where('goods_receipt_line_id', $line->id)->update(['goods_receipt_line_id' => $successorLine->id]);

        if ($moved > 0) {
            CorrectionLog::event('receipt_link_moved', $ctx->actor, $successor, [
                'from' => $this->policy->numberOf($original),
                'to' => "Receipt #{$successor->id}",
                'what' => 'landed costs and supplier discounts',
                'reason' => $ctx->reason->display(),
            ]);
        }
    }

    /**
     * ⭐ 24 Oct 2026: the SAME batches, with the corrected quantity (and date),
     * for a receipt part of which was already sold or used.
     */
    private function resizeInto(GoodsReceipt $original, ReplacementData $replacement, CommitContext $ctx): GoodsReceipt
    {
        $original->loadMissing(['lines.stockMovement.batch']);
        $number = $this->policy->numberOf($original);
        $note = "Correction of {$number}: {$ctx->reason->display()}";
        $date = $replacement->date->copy()->startOfDay();

        $successor = GoodsReceipt::create([
            'business_id' => $original->business_id,
            'purchase_order_id' => $original->purchase_order_id,
            'received_by' => $original->received_by,
            'received_at' => $date,
            'supplier_challan_number' => $replacement->values['challan'],
            'backdate_reason' => mb_substr($note, 0, 500),
            'note' => $replacement->values['note'],
        ]);

        foreach ($original->lines as $line) {
            $typed = $replacement->values['lines'][(int) $line->id];
            $movement = null;

            if ($line->stockMovement !== null) {
                try {
                    $movement = $this->redate->handle(
                        $line->stockMovement,
                        $date,
                        $ctx->actor,
                        mb_substr($note, 0, 250),
                        $ctx->pass,
                        ['batch_number' => $typed['batch_number'], 'manufactured_date' => $typed['manufactured_date'], 'expiry_date' => $typed['expiry_date']],
                        $this->stockQuantityFor($line, $typed['quantity']),
                    );
                } catch (InvalidArgumentException|PeriodClosedException $e) {
                    throw ValidationException::withMessages(['replacement' => $e->getMessage()]);
                }
            }

            $successor->lines()->create([
                'business_id' => $original->business_id,
                'purchase_order_line_id' => $line->purchase_order_line_id,
                'quantity_received' => $typed['quantity'],
                'stock_movement_id' => $movement?->id,
                'market_price_status' => $line->market_price_status,
                'market_price_history_id' => $line->market_price_history_id,
            ]);

            $orderLine = PurchaseOrderLine::query()->whereKey($line->purchase_order_line_id)->lockForUpdate()->first();

            if ($orderLine !== null) {
                $now = PurchaseLineEntry::scaled((string) $orderLine->quantity_received)
                    - PurchaseLineEntry::scaled((string) $line->quantity_received)
                    + PurchaseLineEntry::scaled($typed['quantity']);
                $orderLine->update(['quantity_received' => number_format(max(0, $now) / 10000, 4, '.', '')]);
            }
        }

        $this->recomputeOrderStatus((int) $original->purchase_order_id);
        $this->posting->forReceipt($successor->fresh('lines'), $ctx->actor, $ctx->pass);

        return $successor->fresh('lines');
    }

    /** The corrected purchase quantity in the batch's stock unit (the batch froze the factor). */
    private function stockQuantityFor(GoodsReceiptLine $line, string $purchaseQuantity): string
    {
        $factor = $line->stockMovement?->batch?->conversion_factor;

        return $factor === null
            ? number_format((float) $purchaseQuantity, 4, '.', '')
            : PurchaseUnitConversion::toStockQuantity($purchaseQuantity, (string) $factor);
    }

    private function recomputeOrderStatus(int $orderId): void
    {
        $order = PurchaseOrder::query()->find($orderId);

        if ($order === null || $order->status === PurchaseOrderStatus::Cancelled) {
            return;
        }

        $lines = $order->lines()->get(['quantity_ordered', 'quantity_received']);
        $allReceived = $lines->every(fn ($line) => (float) $line->quantity_received >= (float) $line->quantity_ordered);
        $anyReceived = $lines->contains(fn ($line) => (float) $line->quantity_received > 0);

        $order->update(['status' => match (true) {
            $allReceived => PurchaseOrderStatus::Received,
            $anyReceived => PurchaseOrderStatus::PartiallyReceived,
            default => PurchaseOrderStatus::Open,
        }]);
    }

    // ─── checks only a correction needs ────────────────────────────────────

    /**
     * A date correction keeps the batch, so the one thing it can break is the
     * order of events: moved LATER than a sale from the batch, that sale would
     * come before the stock existed.
     */
    private function checkRedate(GoodsReceipt $receipt, Carbon $date, Carbon $original): void
    {
        if (! $date->greaterThan($original)) {
            return;
        }

        foreach ($receipt->lines as $line) {
            $movement = $line->stockMovement;

            if ($movement === null) {
                continue;
            }

            $used = StockMovement::query()
                ->where('batch_id', $movement->batch_id)
                ->whereKeyNot($movement->id)
                ->where('direction', 'out')
                ->where('quantity', '>', 0)
                ->where('occurred_at', '<', $date)
                ->orderBy('occurred_at')
                ->first();

            if ($used !== null) {
                $name = $movement->batch?->batch_number ?? "#{$movement->batch_id}";

                throw ValidationException::withMessages(['replacement' => "Stock from batch {$name} was used on ".Carbon::parse($used->occurred_at)->format('j M Y').", before {$date->format('j M Y')}, so the receipt cannot move to that later day."]);
            }

            // ⭐ 24 Oct 2026: a landed cost or a discount priced the batch on
            // its own day; the goods cannot arrive after their cost changed.
            $priced = StockMovement::query()
                ->where('batch_id', $movement->batch_id)
                ->where('quantity', 0)
                ->where('occurred_at', '<', $date)
                ->orderBy('occurred_at')
                ->first();

            if ($priced !== null) {
                $name = $movement->batch?->batch_number ?? "#{$movement->batch_id}";

                throw ValidationException::withMessages(['replacement' => "Batch {$name}'s cost was changed on ".Carbon::parse($priced->occurred_at)->format('j M Y').", before {$date->format('j M Y')}, so the receipt cannot move to that later day."]);
            }
        }
    }

    /**
     * A quantity or lot correction takes the goods back out and brings the
     * right ones in: only while untouched and unbilled, within the order,
     * and in open months (receiving itself knows no closed-month exception).
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function checkReplace(GoodsReceipt $receipt, Carbon $date, Carbon $original, array $lines): void
    {
        $refuse = fn (string $sentence) => throw ValidationException::withMessages(['replacement' => $sentence]);

        if (($changed = GoodsReceiptAllocationCheck::costChanged($receipt)) !== null) {
            $refuse($changed);
        }

        if (($touched = GoodsReceiptStockCheck::touched($receipt)) !== null) {
            $refuse(str_replace('cannot simply be reversed', 'can only have its date corrected', $touched));
        }

        if (($billed = GoodsReceiptAllocationCheck::billed($receipt)) !== null) {
            $refuse($billed);
        }

        $lock = AccountingPeriod::current();
        $closedThrough = $lock !== null && ! $lock->is_unlocked ? $lock->closed_through : null;

        if ($closedThrough !== null && ($date->lte($closedThrough) || $original->lte($closedThrough))) {
            $refuse('In a closed month only the date of a receipt can be corrected here. Reverse it in an open month and receive it again.');
        }

        $order = $receipt->purchaseOrder;

        if ($order === null || $order->status === PurchaseOrderStatus::Cancelled || $order->approvalRequest?->status !== ApprovalStatus::Approved) {
            $refuse('The order is no longer open for receiving, so only the date can be corrected.');
        }

        foreach ($receipt->lines as $line) {
            $orderLine = $line->purchaseOrderLine;

            if ($orderLine === null) {
                continue;
            }

            $room = PurchaseLineEntry::scaled((string) $orderLine->quantity_ordered)
                - PurchaseLineEntry::scaled((string) $orderLine->quantity_received)
                + PurchaseLineEntry::scaled((string) $line->quantity_received);

            if (PurchaseLineEntry::scaled($lines[(int) $line->id]['quantity']) > $room) {
                $refuse("Only {$this->qty(number_format($room / 10000, 4, '.', ''))} of {$orderLine->product?->name} is on the order for this receipt.");
            }
        }
    }

    /**
     * ⭐ 24 Oct 2026: a quantity correction of goods partly sold or used keeps
     * the batch. Unbilled, no landed cost or discount on it, nothing reserved,
     * the lot unchanged, within the order, and never below what was already
     * used at any point (RedateStockReceipt refuses that again under the lock).
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function checkResize(GoodsReceipt $receipt, Carbon $date, array $lines): void
    {
        $refuse = fn (string $sentence) => throw ValidationException::withMessages(['replacement' => $sentence]);

        if (($changed = GoodsReceiptAllocationCheck::costChanged($receipt)) !== null) {
            $refuse($changed);
        }

        if (($billed = GoodsReceiptAllocationCheck::billed($receipt)) !== null) {
            $refuse($billed);
        }

        if ($receipt->purchaseOrder === null || $receipt->purchaseOrder->status === PurchaseOrderStatus::Cancelled) {
            $refuse('The order is cancelled, so only the date can be corrected.');
        }

        foreach ($receipt->lines as $line) {
            $movement = $line->stockMovement;
            $batch = $movement?->batch;

            if ($movement === null || $batch === null) {
                continue;
            }

            $name = $batch->batch_number ?? "#{$batch->id}";
            $typed = $lines[(int) $line->id];

            if ($typed['different_lot']) {
                $refuse("Goods from batch {$name} were already sold or used, so its lot cannot change: correct its quantity only.");
            }

            if (StockReservation::query()->where('stock_batch_id', $batch->id)->whereNull('released_at')->exists()) {
                $refuse("Some of batch {$name} is reserved for an order, so release that reservation first.");
            }

            $orderLine = $line->purchaseOrderLine;

            if ($orderLine !== null) {
                $room = PurchaseLineEntry::scaled((string) $orderLine->quantity_ordered)
                    - PurchaseLineEntry::scaled((string) $orderLine->quantity_received)
                    + PurchaseLineEntry::scaled((string) $line->quantity_received);

                if (PurchaseLineEntry::scaled($typed['quantity']) > $room) {
                    $refuse("Only {$this->qty(number_format($room / 10000, 4, '.', ''))} of {$orderLine->product?->name} is on the order for this receipt.");
                }
            }

            $stock = $this->stockQuantityFor($line, $typed['quantity']);

            if (! BatchHistory::staysNonNegativeWith($movement, $date, (int) round((float) $stock * 10000))) {
                $refuse("More of batch {$name} was already sold or used than {$this->qty($typed['quantity'])}, so the receipt cannot be corrected to that quantity.");
            }
        }
    }

    private function qty(string $quantity): string
    {
        return rtrim(rtrim(number_format((float) $quantity, 4, '.', ','), '0'), '.');
    }

    private function text(mixed $value): ?string
    {
        $value = $value === null ? null : trim((string) $value);

        return $value === '' ? null : $value;
    }
}
