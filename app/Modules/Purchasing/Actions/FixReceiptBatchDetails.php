<?php

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\CorrectionLog;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Inventory\Actions\CorrectBatchLabel;
use App\Modules\Purchasing\Corrections\GoodsReceiptActionPolicy;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Purchasing\Support\ReceivedBatchDates;
use App\Modules\Purchasing\Support\SupplierChallans;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ Fix batch details on a goods receipt (corrections wave 1, 19 Oct 2026;
 * FINAL-PLAN example 2 and 8.1 decisions 4 and 5).
 *
 * The SAME physical goods, typed wrong at the door: the batch number, the
 * expiry and manufacture dates of each batch the receipt made, the supplier's
 * challan number and the note. No quantity, no cost, no date of arrival
 * (those are Correct). A reason, always; before and after, who and why in the
 * History; a small "Edited" badge; every use in the Corrections report.
 *
 * Re-checked as receiving checks them, against the day the goods ARRIVED
 * (a batch that is out of date today may well have been fine then):
 * manufacture not after arrival, expiry not before arrival or before
 * manufacture, batch and expiry present when the product tracks expiry, and
 * the challan not already used by this supplier on another receipt. Then the
 * batch is relabelled through Inventory (CorrectBatchLabel), which closes a
 * waiting expired-stock write-off proposal the new expiry no longer supports.
 * FEFO reads the expiry at every pick, so it follows at once.
 *
 * ⚠ Any month, even a closed one: nothing here touches money.
 */
class FixReceiptBatchDetails
{
    public function __construct(
        private readonly GoodsReceiptActionPolicy $policy,
        private readonly CorrectBatchLabel $relabel,
    ) {}

    /**
     * @param  array{supplier_challan_number?: ?string, note?: ?string, lines?: list<array{id: int, batch_number?: ?string, manufactured_date?: ?string, expiry_date?: ?string}>}  $changes
     * @return array{changed: bool, proposals_closed: int}
     */
    public function handle(GoodsReceipt $receipt, array $changes, User $by, ActionReason $reason): array
    {
        return DB::transaction(function () use ($receipt, $changes, $by, $reason) {
            $current = GoodsReceipt::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            $availability = $this->policy->availability($current, $by, DocumentAction::FixBatchDetails);

            if (! $availability->allowed) {
                throw ValidationException::withMessages(['receipt' => $availability->blockers[0]->message]);
            }

            $current->load(['lines.stockMovement.batch', 'lines.purchaseOrderLine.product', 'purchaseOrder.supplier']);
            $arrived = Carbon::parse($current->received_at)->startOfDay();
            $typed = collect($changes['lines'] ?? [])->keyBy(fn ($line) => (int) $line['id']);
            $problems = [];
            $plans = [];

            foreach ($current->lines->values() as $index => $line) {
                /** @var GoodsReceiptLine $line */
                $batch = $line->stockMovement?->batch;

                if ($batch === null || ! $typed->has((int) $line->id)) {
                    continue;
                }

                $entry = $typed[(int) $line->id];
                $label = [
                    'batch_number' => $this->text($entry['batch_number'] ?? null),
                    'manufactured_date' => $this->text($entry['manufactured_date'] ?? null),
                    'expiry_date' => $this->text($entry['expiry_date'] ?? null),
                ];
                $now = [
                    'batch_number' => $batch->batch_number,
                    'manufactured_date' => $batch->manufactured_date?->toDateString(),
                    'expiry_date' => $batch->expiry_date?->toDateString(),
                ];

                if ($label === $now) {
                    continue;
                }

                $product = $line->purchaseOrderLine?->product;

                foreach (ReceivedBatchDates::problems($label['manufactured_date'], $label['expiry_date'], $arrived) as $field => $problem) {
                    $problems["lines.{$index}.{$field}"] = str_replace('cannot be received', 'cannot have been received', $problem);
                }

                foreach (ReceivedBatchDates::required((bool) $product?->tracks_expiry, $label['batch_number'], $label['expiry_date']) as $field => $problem) {
                    $problems["lines.{$index}.{$field}"] ??= $problem;
                }

                $plans[] = [$batch, $label, $now, $product?->name];
            }

            $challan = array_key_exists('supplier_challan_number', $changes) ? $this->text($changes['supplier_challan_number']) : $this->text($current->supplier_challan_number);
            $note = array_key_exists('note', $changes) ? $this->text($changes['note']) : $this->text($current->note);
            $duplicate = SupplierChallans::duplicateOf((int) $current->purchaseOrder?->supplier_id, $challan, (int) $current->id);

            if ($duplicate !== null) {
                $problems['supplier_challan_number'] = "Challan {$challan} from {$current->purchaseOrder?->supplier?->name} was already received on ".($duplicate->received_at?->format('j M Y') ?? 'an earlier day').' on another receipt.';
            }

            if ($problems !== []) {
                throw ValidationException::withMessages($problems);
            }

            $headerChanged = $challan !== $this->text($current->supplier_challan_number) || $note !== $this->text($current->note);

            if ($plans === [] && ! $headerChanged) {
                throw ValidationException::withMessages(['receipt' => 'Nothing was changed.']);
            }

            $old = [];
            $new = [];
            $closed = 0;

            foreach ($plans as [$batch, $label, $now, $name]) {
                $result = $this->relabel->handle($batch, $label, $by, $reason->display());
                $closed += $result['proposals_closed'];

                foreach ($label as $field => $value) {
                    if ($now[$field] !== $value) {
                        $old["{$name}: {$field}"] = $now[$field];
                        $new["{$name}: {$field}"] = $value;
                    }
                }
            }

            if ($headerChanged) {
                foreach (['supplier_challan_number' => $challan, 'note' => $note] as $field => $value) {
                    if ($this->text($current->{$field}) !== $value) {
                        $old[$field] = $current->{$field};
                        $new[$field] = $value;
                    }
                }

                $current->forceFill(['supplier_challan_number' => $challan, 'note' => $note]);
            }

            $current->forceFill(['details_fixed_at' => now()])->save();

            CorrectionLog::event('fix_batch_details', $by, $current, [
                'old' => $old,
                'attributes' => $new,
                'reason' => $reason->display(),
                'proposals_closed' => $closed,
            ]);

            return ['changed' => true, 'proposals_closed' => $closed];
        });
    }

    private function text(mixed $value): ?string
    {
        $value = $value === null ? null : trim((string) $value);

        return $value === '' ? null : $value;
    }
}
