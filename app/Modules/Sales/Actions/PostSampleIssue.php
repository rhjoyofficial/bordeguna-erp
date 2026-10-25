<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Inventory\Actions\RecordStockOut;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\EffectiveDate;
use App\Modules\Inventory\Support\StockValue;
use App\Modules\Sales\Models\SampleIssue;
use App\Modules\Sales\Models\SampleIssueLine;
use App\Modules\Sales\Support\SampleHeads;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * ⭐ Posts an APPROVED sample hand-out (Samples, Joy 25 Oct 2026): the goods
 * leave and the ledger follows, in one transaction.
 *
 *   stock     each line out of the batch the person chose (exactly that
 *             batch, RecordStockOut::fromBatches) or FEFO when none was
 *             chosen, reason `sample`, at each batch's own cost
 *   journal   Dr Samples and Marketing   the goods at cost
 *                 Cr Stock on Hand           one line per movement
 *             Dr Sample Delivery         the delivery charge we bear
 *                 Cr the money account it was paid from, or
 *                 Cr Payable to Couriers (naming the courier's account)
 *
 * NO revenue, NO customer receivable: nobody pays for a sample.
 *
 * ⚠ The goods side is valued per movement through StockValue, exactly as the
 * stock reconciliation values it, so Stock on Hand and the batches agree by
 * construction. A business with no `inventory.stock_on_hand` mapping still
 * gives its samples; only the delivery money (when there is any) is posted.
 *
 * ⚠ A Not for sale (quarantine) warehouse gives no samples: goods held back
 * from customers are not handed to them free either. A product switched to
 * "not for sale" MAY be given (Joy: samples are often not sold as they are).
 */
class PostSampleIssue
{
    public function __construct(
        private readonly RecordStockOut $stockOut,
        private readonly PostJournal $postJournal,
    ) {}

    public function handle(SampleIssue $issue, User $postedBy): SampleIssue
    {
        $on = Carbon::parse($issue->given_on)->startOfDay();

        // ⚠ Re-checked here, not only when it was recorded: a month can close
        // while the hand-out waits for its approver.
        AccountingPeriod::assertOpenFor($on);

        return DB::transaction(function () use ($issue, $postedBy, $on) {
            /** @var SampleIssue $issue */
            $issue = SampleIssue::query()->whereKey($issue->id)->lockForUpdate()->with(['lines.product', 'warehouse', 'deliveryMoneyAccount', 'courierMoneyAccount'])->firstOrFail();

            if ($issue->isPosted() || $issue->isReversed()) {
                throw new RuntimeException("Sample {$issue->number} was already given.");
            }

            $issue->warehouse->assertTakesStockFor('sample');
            $movedAt = EffectiveDate::resolveConsumingDay($on->toDateString(), 'given_on');
            $note = "Sample {$issue->number} to {$issue->recipient_name}";
            $movements = collect();

            foreach ($issue->lines as $line) {
                /** @var SampleIssueLine $line */
                $written = $line->stock_batch_id !== null
                    ? $this->stockOut->fromBatches(
                        product: $line->product,
                        warehouse: $issue->warehouse,
                        picks: [['batch_id' => (int) $line->stock_batch_id, 'quantity' => (string) $line->quantity]],
                        performedBy: $postedBy,
                        note: $note,
                        reason: MovementReason::Sample,
                        occurredAt: $movedAt,
                    )
                    : $this->stockOut->handle(
                        product: $line->product,
                        warehouse: $issue->warehouse,
                        quantity: (string) $line->quantity,
                        performedBy: $postedBy,
                        note: $note,
                        reason: MovementReason::Sample,
                        occurredAt: $movedAt,
                    );

                $line->movements()->attach($written->pluck('id')->all());
                $line->update(['cost_paisa' => StockValue::sum($written)->absolute()->minor]);
                $movements = $movements->merge($written);
            }

            $cost = StockValue::sum($movements)->absolute();
            $journal = $this->journal($issue, $movements->values()->all(), $cost, $on, $postedBy);

            $issue->update([
                'status' => SampleIssue::POSTED,
                'cost_total_paisa' => $cost->minor,
                'journal_id' => $journal?->id,
                'posted_at' => now(),
                'posted_by' => $postedBy->id,
            ]);

            return $issue->fresh(['lines.product', 'lines.movements', 'journal']);
        });
    }

    /**
     * @param  list<StockMovement>  $movements
     */
    private function journal(SampleIssue $issue, array $movements, Money $cost, Carbon $on, User $postedBy): ?Journal
    {
        $lines = [];

        if (! $cost->isZero() && AccountMapping::forKeyOn('inventory.stock_on_hand', $on) !== null) {
            $stock = AccountMapping::resolve('inventory.stock_on_hand', $on);
            $lines[] = JournalLineDraft::debit(SampleHeads::goods($on)->id, $cost, "{$issue->number}: samples to {$issue->recipient_name} at cost");

            foreach ($movements as $movement) {
                $value = StockValue::of($movement);

                if (! $value->isZero()) {
                    $lines[] = JournalLineDraft::signed($stock->id, $value, $movement->note);
                }
            }
        }

        $delivery = $issue->deliveryMoney();

        if (! $delivery->isZero()) {
            $lines[] = JournalLineDraft::debit(SampleHeads::delivery($on)->id, $delivery, "{$issue->number}: delivery of samples to {$issue->recipient_name}");
            $lines[] = $this->deliveryCredit($issue, $delivery, $on);
        }

        if ($lines === []) {
            return null;
        }

        return $this->postJournal->handle(new JournalDraft(
            date: $on,
            lines: $lines,
            memo: "Sample {$issue->number} given to {$issue->recipient_name} (".(SampleIssue::PURPOSES[$issue->purpose] ?? $issue->purpose).'), no money taken',
            source: $issue,
        ), $postedBy);
    }

    /** Who the delivery charge is owed to or paid by: the courier, or a money account. */
    private function deliveryCredit(SampleIssue $issue, Money $delivery, Carbon $on): JournalLineDraft
    {
        if ($issue->courierMoneyAccount !== null) {
            $payable = Account::bySlug('courier_payable') ?? throw MappingNotFound::for('account [courier_payable]', $on);

            return JournalLineDraft::credit($payable->id, $delivery, "{$issue->number}: owed to {$issue->courierMoneyAccount->name}", null, $issue->courierMoneyAccount);
        }

        $account = $issue->deliveryMoneyAccount;

        if ($account?->account_id === null) {
            throw MappingNotFound::for("money account [{$account?->name}]", $on);
        }

        return JournalLineDraft::credit((int) $account->account_id, $delivery, $account->label(), null, $account);
    }
}
