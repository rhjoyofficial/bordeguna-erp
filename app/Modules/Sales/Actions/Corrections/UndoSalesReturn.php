<?php

namespace App\Modules\Sales\Actions\Corrections;

use App\Models\User;
use App\Modules\Accounting\Actions\ReverseJournal;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\FiscalYear;
use App\Modules\Foundation\Corrections\ClosedPeriodPass;
use App\Modules\Foundation\Models\Business;
use App\Modules\Inventory\Actions\TakeBackStockReceipt;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Sales\Corrections\Checks\SalesLedgerCheck;
use App\Modules\Sales\Enums\SalesReturnDisposition;
use App\Modules\Sales\Models\DeliveryLine;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesReturnLine;
use App\Modules\Sales\Models\SalesReturnLineMovement;
use App\Modules\Sales\Support\ReturnPlacements;
use App\Support\DocumentNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * ⭐ Taking a sales return back (B13, 24 Oct 2026; Joy's R101, gap S5,
 * FINAL-PLAN "Sales return: Reverse while the goods are still in those batches
 * and no money went back"). The goods did not come back, or not like that.
 *
 *   undo()  1. the return's journal is mirrored on the date the correction
 *              rules give (its own date by default): the customer owes again,
 *              the stock leaves 1150 again, GDNI, COGS and the write-off head
 *              go back to where the challan and the invoices left them;
 *           2. every unit put back on the shelf leaves THE SAME batch again, at
 *              the cost it came back at (Inventory's TakeBackStockReceipt, a
 *              movement naming the one it undoes: Sales never writes stock);
 *           3. a MIRROR return is written: every line, layer share, invoice
 *              line credit and invoice allocation of the original, negated. So
 *              the invoices it credited owe again, the challan line may be
 *              returned and billed again, and every report that sums returns
 *              (statements, ageing, the reconciliation, COGS, sales facts, VAT)
 *              nets it out without learning what a reversal is;
 *           4. the challan line's returned counters come back down
 *   mark()  the Reversible columns on the original
 *
 * ⚠ Called only from inside a correction's transaction (SalesReturnCorrector),
 * after the policy's checks (no refund on it, the goods still in their batches
 * at the same cost); every one is asked again here under the locks.
 */
class UndoSalesReturn
{
    /** The line amounts a mirror carries negated (a rate is not an amount). */
    private const LINE_AMOUNTS = [
        'quantity', 'quantity_to_stock', 'quantity_written_off', 'quantity_to_quarantine', 'gdni_relief', 'cogs_relief', 'cost_restored',
        'cost_written_off', 'credit_gross', 'credit_discount', 'credit_net', 'tax_amount', 'tax_inside_gross', 'tax_on_discount',
    ];

    private const CREDIT_AMOUNTS = ['quantity', 'gross', 'discount', 'net', 'tax', 'tax_on_discount'];

    public function __construct(
        private readonly ReverseJournal $reverseJournal,
        private readonly TakeBackStockReceipt $takeBack,
    ) {}

    public function undo(SalesReturn $return, User $by, Carbon $on, string $memo, ?ClosedPeriodPass $pass = null): ?Journal
    {
        if ($return->isMirror() || $return->isReversed()) {
            throw new LogicException("Return {$return->return_number} is already undone.");
        }

        $return->load(['lines.movementLinks', 'lines.invoiceLineCredits', 'allocations']);
        $journal = SalesLedgerCheck::journalOf($return);
        $reversal = $journal === null ? null : $this->reverseJournal->handle($journal->load('lines'), $by, $on, $memo, $pass);

        $mirror = DocumentNumber::claim(
            'return_number',
            fn () => $this->nextReturnNumber($return, $on),
            fn (string $number) => SalesReturn::create([
                'business_id' => $return->business_id,
                'customer_id' => $return->customer_id,
                'delivery_id' => $return->delivery_id,
                'return_number' => $number,
                'reverses_return_id' => $return->id,
                'returned_at' => $on->toDateString(),
                'note' => mb_substr("Reversal of {$return->return_number}: {$memo}", 0, 2000),
                'created_by' => $by->id,
            ]),
        );

        $mirror->forceFill([
            'credit_total' => self::negated((string) $return->credit_total),
            'tax_total' => self::negated((string) ($return->tax_total ?? '0')),
        ])->save();

        // ⭐ Where every unit put back is now (ReturnPlacements), worked out
        // before anything moves.
        $plan = ReturnPlacements::plan($return);

        foreach ($return->lines as $line) {
            /** @var SalesReturnLine $mirrorLine */
            $mirrorLine = $line->replicate(['sales_return_id']);
            $mirrorLine->sales_return_id = $mirror->id;

            foreach (self::LINE_AMOUNTS as $field) {
                $mirrorLine->{$field} = self::negated((string) ($line->{$field} ?? '0'));
            }

            $mirrorLine->save();

            foreach ($line->movementLinks as $link) {
                $steps = $link->disposition === SalesReturnDisposition::Stock && $link->restored_movement_id !== null
                    ? array_values(array_filter($plan, fn (array $step) => (int) $step['link']->id === (int) $link->id))
                    : [];

                if ($steps === []) {
                    SalesReturnLineMovement::create([
                        'business_id' => $link->business_id,
                        'sales_return_line_id' => $mirrorLine->id,
                        'stock_movement_id' => $link->stock_movement_id,
                        'restored_movement_id' => null,
                        'quantity' => self::negated((string) $link->quantity),
                        'disposition' => $link->disposition,
                        'unit_cost' => $link->unit_cost,
                    ]);

                    continue;
                }

                // ⭐ IP3 meets IP14 / IP6: the goods are taken back from where
                // they are now (the quarantine or the other selling warehouse
                // they were moved into, then the restored batch).
                foreach ($steps as $step) {
                    $quantity = number_format($step['scaled'] / 10000, 4, '.', '');
                    $out = $this->takeBack->handle($step['from'], $by, "Return {$return->return_number} reversed", $on, $pass, $quantity, MovementReason::SalesReturn);

                    SalesReturnLineMovement::create([
                        'business_id' => $link->business_id,
                        'sales_return_line_id' => $mirrorLine->id,
                        'stock_movement_id' => $link->stock_movement_id,
                        'restored_movement_id' => $out->id,
                        'quantity' => self::negated($quantity),
                        'disposition' => $link->disposition,
                        'unit_cost' => $link->unit_cost,
                    ]);
                }
            }

            $invoiced = '0';

            foreach ($line->invoiceLineCredits as $credit) {
                $copy = $credit->replicate(['sales_return_line_id']);
                $copy->sales_return_line_id = $mirrorLine->id;

                foreach (self::CREDIT_AMOUNTS as $field) {
                    $copy->{$field} = self::negated((string) ($credit->{$field} ?? '0'));
                }

                $copy->save();
                $invoiced = bcadd($invoiced, (string) $credit->quantity, 4);
            }

            // The challan line's counters come back down by what this line took.
            $deliveryLine = DeliveryLine::query()->whereKey($line->delivery_line_id)->lockForUpdate()->firstOrFail();
            $deliveryLine->forceFill([
                'quantity_returned' => bcsub((string) $deliveryLine->quantity_returned, (string) $line->quantity, 4),
                'quantity_returned_uninvoiced' => bcsub((string) $deliveryLine->quantity_returned_uninvoiced, bcsub((string) $line->quantity, $invoiced, 4), 4),
            ])->save();
        }

        foreach ($return->allocations as $allocation) {
            $mirror->allocations()->create([
                'business_id' => $allocation->business_id,
                'sales_invoice_id' => $allocation->sales_invoice_id,
                'amount' => self::negated((string) $allocation->amount),
            ]);
        }

        return $reversal;
    }

    public function mark(Model $return, User $by, Carbon $on, string $reason, ?Journal $reversal): void
    {
        $return->forceFill([
            'reversed_on' => $on,
            'reversed_at' => now(),
            'reversed_by' => $by->id,
            'reversal_reason' => $reason,
            'reversal_journal_id' => $reversal?->id,
        ])->save();
    }

    /** The return's own series (SRN-...), dated in the reversal's fiscal year. */
    private function nextReturnNumber(SalesReturn $return, Carbon $on): string
    {
        $business = Business::withoutGlobalScopes()->findOrFail($return->business_id);
        $prefix = 'SRN-'.FiscalYear::containing($on, $business)->label().'-';

        return DocumentNumber::next(SalesReturn::query(), 'return_number', $prefix);
    }

    private static function negated(string $value): string
    {
        return bcmul($value === '' ? '0' : $value, '-1', 4);
    }
}
