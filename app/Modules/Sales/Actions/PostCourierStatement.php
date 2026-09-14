<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\LedgerRounding;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Sales\Models\CourierParcelClaim;
use App\Modules\Sales\Models\CourierStatement;
use App\Modules\Sales\Support\CourierStatements\CourierStatementFormats;
use App\Modules\Sales\Support\CourierStatements\CourierStatementReview;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A courier statement posted: this business's share of the payout lands, the
 * cash on delivery the courier was holding is cleared, and its fees are costs.
 *
 *   Cr 1145 Receivable from Couriers    what the courier collected for this business
 *   Dr 6371 Courier Charge              carrying delivered parcels, net of its discount
 *   Dr 6374 Customer Return Delivery    carrying parcels that came back
 *   Dr 6377 Courier Adjustment          its extra charges
 *   Dr 6378 COD Handling Charge         its cash-on-delivery fee
 *   Dr the bank the payout landed in    this business's share (a credit if negative)
 *
 * ⭐⭐ ONE PAYOUT, TWO BRANDS (Joy, 13 September: one shared Pathao account). The
 * deposit is the whole payout as the bank saw it and must equal the file's total;
 * each business books only its own share into its own row for that bank account,
 * so the shares add up to the deposit. A brand whose fees outweighed its
 * collections books a negative share: its fees were paid out of the other's cash.
 *
 * ⚠ NO DEPOSIT when the statement pays out nothing or less (a courier's "due
 * amount"): the share stays in 1145, whose balance then says the courier is owed.
 *
 * ⚠⚠ Every parcel settled is CLAIMED in `courier_parcel_claims`, which no
 * business scope hides, so the other brand posting the same statement cannot
 * settle it again.
 */
class PostCourierStatement
{
    public function __construct(private readonly PostJournal $postJournal) {}

    /**
     * @throws ValidationException
     */
    public function handle(
        CourierStatement $statement,
        ?MoneyAccount $receivedInto,
        ?string $depositAmount,
        mixed $receivedOn,
        User $postedBy,
    ): CourierStatement {
        return DB::transaction(function () use ($statement, $receivedInto, $depositAmount, $receivedOn, $postedBy) {
            /** @var CourierStatement $statement */
            $statement = CourierStatement::query()->whereKey($statement->id)->lockForUpdate()->with('lines', 'courier')->firstOrFail();

            if (! $statement->isDraft()) {
                throw ValidationException::withMessages(['statement' => "Statement {$statement->statement_reference} has already been posted."]);
            }

            $review = CourierStatementReview::of($statement);

            if (! $review->canPost()) {
                throw ValidationException::withMessages(['statement' => $review->problems[0]]);
            }

            $depositArrived = $review->fileTotal->minor > 0;

            if ($depositArrived) {
                if ($receivedInto === null || $receivedInto->isCourier() || $receivedInto->isGateway() || ! $receivedInto->is_active || $receivedInto->business_id !== $statement->business_id) {
                    throw ValidationException::withMessages(['received_money_account_id' => 'Choose the bank or wallet account the payout arrived in.']);
                }

                if ($receivedInto->account_id === null) {
                    throw ValidationException::withMessages(['received_money_account_id' => "{$receivedInto->name} has no ledger account yet."]);
                }

                $deposit = Money::ofTaka((string) $depositAmount);

                if ($deposit->minor !== $review->fileTotal->minor) {
                    throw ValidationException::withMessages([
                        'deposit_amount' => "The statement pays out {$review->fileTotal->format()}, but {$deposit->format()} arrived. Check the file is this payout's, and that nothing was held back.",
                    ]);
                }

                $on = Carbon::parse($receivedOn ?? 'today')->startOfDay();

                if ($on->isFuture()) {
                    throw ValidationException::withMessages(['received_on' => 'The money cannot have arrived on a day that has not happened yet.']);
                }
            } else {
                $on = Carbon::parse($statement->statement_date)->startOfDay();
            }

            AccountingPeriod::assertOpenFor($on);

            $this->post($statement, $review, $depositArrived ? $receivedInto : null, $on, $postedBy);

            foreach ($review->rows->where('included', true) as $row) {
                $row['line']->forceFill(['consignment_id' => $row['consignment']?->id])->save();

                try {
                    CourierParcelClaim::create([
                        'claim_key' => CourierParcelClaim::keyFor(CourierStatementFormats::provider($statement->courier), $row['line']->consignment_number),
                        'business_id' => $statement->business_id,
                        'courier_statement_line_id' => $row['line']->id,
                    ]);
                } catch (UniqueConstraintViolationException) {
                    throw ValidationException::withMessages([
                        'statement' => "Consignment {$row['line']->consignment_number} was paid out on another statement a moment ago. Nothing was posted.",
                    ]);
                }
            }

            $statement->forceFill([
                'status' => CourierStatement::POSTED,
                'received_money_account_id' => $depositArrived ? $receivedInto->id : null,
                'deposit_amount' => $depositArrived ? $review->fileTotal->toTaka() : null,
                'received_on' => $depositArrived ? $on : null,
                'share_amount' => $review->share->toTaka(),
                'posted_by' => $postedBy->id,
                'posted_at' => now(),
            ])->save();

            return $statement->fresh(['lines', 'courier', 'receivedInto']);
        });
    }

    private function post(CourierStatement $statement, CourierStatementReview $review, ?MoneyAccount $receivedInto, Carbon $on, User $postedBy): void
    {
        $courier = $statement->courier;
        $courierLedger = Account::findOrFail($courier->account_id);
        $memo = "{$courier->name} statement {$statement->statement_reference}";

        $lines = [];

        if (! $review->collected->isZero()) {
            $lines[] = JournalLineDraft::credit($courierLedger->id, $review->collected, "Collected, {$memo}", null, $courier);
        }

        foreach ([
            'courier.delivery_fees' => [$review->deliveryFees, 'Delivery'],
            'courier.return_fees' => [$review->returnFees, 'Returns'],
            'courier.additional_charges' => [$review->additionalCharges, 'Extra charges'],
            'courier.cod_fees' => [$review->codFees, 'COD fee'],
        ] as $key => [$amount, $label]) {
            if (! $amount->isZero()) {
                $lines[] = JournalLineDraft::debit(AccountMapping::resolve($key, $on)->id, $amount, "{$label}, {$memo}");
            }
        }

        if (! $review->share->isZero()) {
            $lines[] = $receivedInto !== null
                ? JournalLineDraft::signed((int) Account::findOrFail($receivedInto->account_id)->id, $review->share, "Payout, {$memo}", null, $receivedInto)
                : JournalLineDraft::signed($courierLedger->id, $review->share, "Held by the courier, {$memo}", null, $courier);
        }

        if ($lines === []) {
            return;
        }

        $this->postJournal->handle(new JournalDraft(
            date: $on,
            lines: LedgerRounding::balance($lines, $on, "Rounding on {$memo}"),
            memo: $memo,
            source: $statement,
        ), $postedBy);
    }
}
