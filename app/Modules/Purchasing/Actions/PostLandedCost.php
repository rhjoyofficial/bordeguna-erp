<?php

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\LedgerRounding;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\MoneyAvailability;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Inventory\Actions\RevalueStockBatch;
use App\Modules\Inventory\Enums\MovementDirection;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Purchasing\Enums\LandedCostStatus;
use App\Modules\Purchasing\Models\LandedCost;
use App\Modules\Purchasing\Models\LandedCostLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Turns an APPROVED landed cost into stock values and one journal.
 *
 *   Dr  inventory.landed_cost (Stock on Hand)   the on-hand share, per batch,
 *                                               each with its revaluation movement
 *   Dr  purchasing.landed_cost_consumed (5160)  the share of stock already used or sold
 *   Dr  the expense head of each expensed line  freight 5300, labour 6096, bank 6471, or named
 *       Cr  Accounts Payable (party = supplier)  what is owed, per supplier
 *       Cr  the money account's ledger head      what was already paid, per account
 *
 * ⚠⚠ The plan is computed AGAIN here, under the transaction, from the batches
 * as they are now: stock can be consumed between the preview and the posting,
 * and the split must match the movements the ledger will be reconciled to.
 * A plan with refusals (Tk 10,000 rule, closed month, missing evidence) stops
 * the posting with its sentence; nothing is written.
 *
 * ⚠ The on-hand share goes through RevalueStockBatch, THE revaluation primitive
 * (quantity 0 + value_delta + batch unit_cost), so StockValue, the subledger
 * reconciliation and FEFO all see it the way they see any revaluation. The
 * value_delta IS the on-hand share in paisa, so the ledger and the movements
 * agree to the paisa by construction.
 */
class PostLandedCost
{
    public function __construct(
        private readonly PlanLandedCostAllocation $planner,
        private readonly RevalueStockBatch $revalue,
        private readonly PostJournal $postJournal,
    ) {}

    public function handle(LandedCost $landedCost, User $postedBy): LandedCost
    {
        if ($landedCost->status !== LandedCostStatus::Approved) {
            throw new RuntimeException("Landed cost {$landedCost->number} is not approved, so it cannot be posted.");
        }

        if ($landedCost->approvalRequest === null || ! $landedCost->approvalRequest->isComplete()) {
            throw new RuntimeException("Landed cost {$landedCost->number} is still waiting for approval.");
        }

        $on = Carbon::parse($landedCost->incurred_on)->startOfDay();

        // ⚠⚠ Re-checked HERE, not only at submission: a month can close while
        // the document waits. The Owner exception is not borrowed (LandedCostPolicy).
        AccountingPeriod::assertOpenFor($on);

        if (AccountMapping::forKeyOn('inventory.stock_on_hand', $on) === null) {
            throw new RuntimeException('Inventory posting is not switched on for this business (inventory.stock_on_hand is unmapped), so a landed cost has nowhere to post.');
        }

        return DB::transaction(function () use ($landedCost, $postedBy, $on) {
            $landedCost = LandedCost::query()->whereKey($landedCost->id)->lockForUpdate()->with(['lines', 'approvalRequest'])->firstOrFail();

            if ($landedCost->status !== LandedCostStatus::Approved) {
                throw new RuntimeException("Landed cost {$landedCost->number} was posted a moment ago.");
            }

            $plan = $this->planner->handle($landedCost);

            if (! $plan->isPostable()) {
                throw new RuntimeException(implode(' ', $plan->refusals));
            }

            $inventory = AccountMapping::resolve('inventory.landed_cost', $on);
            $consumedHead = AccountMapping::resolve('purchasing.landed_cost_consumed', $on);
            $payable = AccountMapping::resolve('purchasing.accounts_payable', $on);

            $lines = [];
            $credits = [];

            foreach ($landedCost->lines as $line) {
                $planned = $plan->lines[$line->id];

                if ($line->isCapitalised()) {
                    $consumedOnLine = Money::zero();

                    foreach ($planned['allocations'] as $allocation) {
                        $batch = StockBatch::query()->whereKey($allocation['batch_id'])->lockForUpdate()->firstOrFail();
                        $onHandShare = Money::ofMinor($allocation['on_hand_share_paisa']);
                        $movementId = null;

                        if (! $onHandShare->isZero() && $allocation['new_unit_cost'] !== null) {
                            $movement = $this->revalue->handle(
                                batch: $batch,
                                direction: MovementDirection::In,
                                newUnitCost: $allocation['new_unit_cost'],
                                valueDelta: $onHandShare->toTaka(),
                                performedBy: $postedBy->id,
                                note: "Landed cost {$landedCost->number}: {$line->cost_type->label()}",
                                occurredAt: $on,
                                reason: MovementReason::LandedCost,
                            );
                            $movementId = $movement->id;

                            $lines[] = JournalLineDraft::debit($inventory->id, $onHandShare, "{$landedCost->number} batch {$batch->batch_number}");
                        }

                        $consumedOnLine = $consumedOnLine->plus(Money::ofMinor($allocation['consumed_share_paisa']));

                        $line->allocations()->create([
                            'business_id' => $landedCost->business_id,
                            'goods_receipt_line_id' => $allocation['receipt_line_id'],
                            'stock_batch_id' => $batch->id,
                            'quantity_received' => $allocation['quantity_received'],
                            'quantity_on_hand' => $allocation['quantity_on_hand'],
                            'quantity_consumed' => $allocation['quantity_consumed'],
                            'share_paisa' => $allocation['share_paisa'],
                            'on_hand_share_paisa' => $allocation['on_hand_share_paisa'],
                            'consumed_share_paisa' => $allocation['consumed_share_paisa'],
                            'new_unit_cost' => $allocation['new_unit_cost'],
                            'stock_movement_id' => $movementId,
                        ]);
                    }

                    if (! $consumedOnLine->isZero()) {
                        $lines[] = JournalLineDraft::debit($consumedHead->id, $consumedOnLine, "{$landedCost->number}: {$line->cost_type->label()} on stock already used");
                    }
                } else {
                    $lines[] = JournalLineDraft::debit($planned['expense_account']->id, $line->amount(), "{$landedCost->number}: ".($line->description ?: $line->cost_type->label()));
                }

                $this->collectCredit($credits, $line);
            }

            foreach ($credits as $credit) {
                if ($credit['supplier'] !== null) {
                    $lines[] = JournalLineDraft::credit($payable->id, $credit['amount'], $landedCost->number, $credit['supplier']);

                    continue;
                }

                $moneyAccount = $credit['money_account'];

                if ($moneyAccount->account_id === null) {
                    throw MappingNotFound::for("money account [{$moneyAccount->name}]", $on);
                }

                // Finance policy B14: no cash box below zero, a bank only to its overdraft.
                MoneyAvailability::assertCanPay($moneyAccount, $credit['amount'], $on, 'money_account_id');

                $lines[] = JournalLineDraft::credit($moneyAccount->account_id, $credit['amount'], $moneyAccount->label(), null, $moneyAccount);
            }

            $lines = LedgerRounding::balance($lines, $on, "Rounding on landed cost {$landedCost->number}");

            $journal = $this->postJournal->handle(new JournalDraft(
                date: $on,
                lines: $lines,
                memo: "Landed cost {$landedCost->number} on goods receipt #{$landedCost->goods_receipt_id}",
                source: $landedCost,
            ), $postedBy);

            $landedCost->update([
                'status' => LandedCostStatus::Posted,
                'journal_id' => $journal->id,
                'posted_at' => now(),
                'posted_by' => $postedBy->id,
            ]);

            return $landedCost->fresh(['lines.allocations', 'approvalRequest', 'journal']);
        });
    }

    /**
     * One credit per payee, however many lines name it.
     *
     * @param  array<string, array{supplier: mixed, money_account: mixed, amount: Money}>  $credits
     */
    private function collectCredit(array &$credits, LandedCostLine $line): void
    {
        $key = $line->supplier_id !== null ? "supplier:{$line->supplier_id}" : "money:{$line->money_account_id}";

        if (! isset($credits[$key])) {
            $credits[$key] = [
                'supplier' => $line->supplier_id !== null ? $line->supplier : null,
                'money_account' => $line->money_account_id !== null ? $line->moneyAccount : null,
                'amount' => Money::zero(),
            ];
        }

        $credits[$key]['amount'] = $credits[$key]['amount']->plus($line->amount());
    }
}
