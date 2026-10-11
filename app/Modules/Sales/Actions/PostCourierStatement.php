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
use App\Modules\Foundation\Actions\StoreAttachment;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Sales\Models\CourierParcelClaim;
use App\Modules\Sales\Models\CourierStatement;
use App\Modules\Sales\Support\CourierStatements\CourierBalances;
use App\Modules\Sales\Support\CourierStatements\CourierStatementReview;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A courier statement posted: this business's share of the payout lands, the
 * cash on delivery the courier was holding is cleared, and its fees are costs.
 *
 *   Cr 1145 Receivable from Couriers    what the courier collected for this business
 *   Dr courier.delivery_fees (6371)     carrying delivered parcels, NET of its discount
 *   Dr courier.return_fees (6374)       carrying parcels that came back, net
 *   Dr courier.additional_charges (6377) its extra charges; its adjustments by sign
 *   Dr courier.cod_fees (6378)          its cash-on-delivery fee
 *   Cr courier.compensation (4960)      what it paid for a lost or damaged parcel
 *   Dr courier.payable (2165)           an earlier shortfall it netted off this payout
 *   Dr the bank the payout landed in    this business's share (a credit if negative)
 *
 * ⭐⭐ ONE PAYOUT, TWO BRANDS (Joy, 13 September: one shared Pathao account). The
 * deposit is the whole payout as the bank saw it; each business books only its
 * own share into its own row for that bank account, so the shares add up to the
 * deposit. A brand whose fees outweighed its collections books a negative share.
 *
 * ⭐ v2 (11 Oct 2026, ChatGPT rulings 4-6, Joy's approved accounts):
 * - NOTHING PAID OUT and this business's net is below zero: the shortfall is
 *   owed to the courier, Cr courier.payable, never a credit left in 1145.
 *   Nothing paid out and the net is above zero: the courier still holds it, Dr
 *   1145.
 * - SETTLE AGAINST PAYABLE: a later payout the courier netted an earlier
 *   shortfall from. Up to the open payable for this courier; the deposit
 *   expected falls by the same amount.
 * - A DEPOSIT THAT DIFFERS from what is expected is accepted only with a
 *   written reason, and the difference is booked in this business's books: less
 *   arrived = still held by the courier (Dr 1145); more arrived = owed back to
 *   it (Cr courier.payable). The bank reference and a proof file are kept.
 *
 * ⚠⚠ Every line on 1145, courier.payable and courier.compensation names the
 * courier's money account, so each courier company's balance is its own (Joy,
 * 11 Oct 2026). PostJournal refuses the two new heads without it.
 *
 * ⚠⚠ Every parcel settled is CLAIMED in `courier_parcel_claims`, which no
 * business scope hides, so the other brand posting the same statement cannot
 * settle it again.
 */
class PostCourierStatement
{
    public function __construct(
        private readonly PostJournal $postJournal,
        private readonly StoreAttachment $storeAttachment,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(
        CourierStatement $statement,
        ?MoneyAccount $receivedInto,
        ?string $depositAmount,
        mixed $receivedOn,
        User $postedBy,
        ?string $settlePayable = null,
        ?string $differenceReason = null,
        ?string $bankReference = null,
        ?UploadedFile $proof = null,
    ): CourierStatement {
        return DB::transaction(function () use ($statement, $receivedInto, $depositAmount, $receivedOn, $postedBy, $settlePayable, $differenceReason, $bankReference, $proof) {
            /** @var CourierStatement $statement */
            $statement = CourierStatement::query()->whereKey($statement->id)->lockForUpdate()->with('lines', 'courier')->firstOrFail();

            if (! $statement->isDraft()) {
                throw ValidationException::withMessages(['statement' => "Statement {$statement->statement_reference} has already been posted."]);
            }

            $review = CourierStatementReview::of($statement);

            if (! $review->canPost()) {
                throw ValidationException::withMessages(['statement' => $review->problems[0]]);
            }

            $settle = $this->settlement($statement, $review, $settlePayable);
            $expected = Money::ofMinor($review->fileTotal->minor - $settle->minor);
            $depositArrived = $expected->minor > 0;
            $difference = Money::zero();
            $differenceReason = trim((string) $differenceReason) ?: null;

            if ($depositArrived) {
                if ($receivedInto === null || $receivedInto->isCourier() || $receivedInto->isGateway() || ! $receivedInto->is_active || $receivedInto->business_id !== $statement->business_id) {
                    throw ValidationException::withMessages(['received_money_account_id' => 'Choose the bank or wallet account the payout arrived in.']);
                }

                if ($receivedInto->account_id === null) {
                    throw ValidationException::withMessages(['received_money_account_id' => "{$receivedInto->name} has no ledger account yet."]);
                }

                $deposit = Money::ofTaka((string) $depositAmount);

                if ($deposit->minor <= 0) {
                    throw ValidationException::withMessages(['deposit_amount' => 'Enter the amount that arrived.']);
                }

                $difference = Money::ofMinor($deposit->minor - $expected->minor);

                if (! $difference->isZero() && ($differenceReason === null || mb_strlen($differenceReason) < 5)) {
                    throw ValidationException::withMessages([
                        'deposit_amount' => "The statement pays out {$expected->format()}, but {$deposit->format()} arrived. Check the file is this payout's, or say why they differ.",
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

            $this->post($statement, $review, $depositArrived ? $receivedInto : null, $settle, $difference, $on, $postedBy);

            foreach ($review->rows->where('included', true) as $row) {
                $row['line']->forceFill([
                    'consignment_id' => $row['consignment']?->id,
                    'match_method' => $row['match_method'],
                ])->save();

                try {
                    CourierParcelClaim::create([
                        'claim_key' => CourierParcelClaim::keyFor($review->provider, $row['line']->consignment_number),
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
                'deposit_amount' => $depositArrived ? Money::ofMinor($expected->minor + $difference->minor)->toTaka() : null,
                'received_on' => $depositArrived ? $on : null,
                'bank_reference' => trim((string) $bankReference) ?: null,
                'deposit_difference_reason' => $difference->isZero() ? null : $differenceReason,
                'payable_settled_amount' => $settle->isZero() ? null : $settle->toTaka(),
                'share_amount' => $review->share->toTaka(),
                'posted_by' => $postedBy->id,
                'posted_at' => now(),
            ])->save();

            if ($proof !== null) {
                $this->storeAttachment->handle($statement, $proof, CourierStatement::PAYOUT_PROOF, $postedBy);
            }

            return $statement->fresh(['lines', 'courier', 'receivedInto']);
        });
    }

    /**
     * How much of the courier's open payable this payout nets off.
     */
    private function settlement(CourierStatement $statement, CourierStatementReview $review, ?string $settlePayable): Money
    {
        if ($settlePayable === null || trim($settlePayable) === '') {
            return Money::zero();
        }

        $settle = Money::ofTaka($settlePayable);

        if ($settle->isZero()) {
            return $settle;
        }

        $open = CourierBalances::payable($statement->courier);

        if ($settle->minor < 0 || $settle->minor > $open->minor) {
            throw ValidationException::withMessages([
                'settle_payable' => "The ERP shows {$open->format()} owed to {$statement->courier->name}; settle at most that.",
            ]);
        }

        if ($settle->minor > max($review->share->minor, 0)) {
            throw ValidationException::withMessages([
                'settle_payable' => "This statement's share is {$review->share->format()}; it cannot net off more than that.",
            ]);
        }

        return $settle;
    }

    private function post(CourierStatement $statement, CourierStatementReview $review, ?MoneyAccount $receivedInto, Money $settle, Money $difference, Carbon $on, User $postedBy): void
    {
        $courier = $statement->courier;
        $courierLedger = Account::findOrFail($courier->account_id);
        $memo = "{$courier->name} statement {$statement->statement_reference}";
        $payable = fn () => AccountMapping::resolve('courier.payable', $on)->id;

        $lines = [];

        if (! $review->collected->isZero()) {
            $lines[] = JournalLineDraft::credit($courierLedger->id, $review->collected, "Collected, {$memo}", null, $courier);
        }

        foreach ([
            'courier.delivery_fees' => [$review->deliveryFees, 'Delivery, net of discount'],
            'courier.return_fees' => [$review->returnFees, 'Returns, net of discount'],
            'courier.additional_charges' => [$review->additionalCharges, 'Extra charges'],
            'courier.cod_fees' => [$review->codFees, 'COD fee'],
        ] as $key => [$amount, $label]) {
            if (! $amount->isZero()) {
                $lines[] = JournalLineDraft::signed(AccountMapping::resolve($key, $on)->id, $amount, "{$label}, {$memo}");
            }
        }

        // A positive adjustment is money to us: it reduces the courier's charges.
        if (! $review->adjustment->isZero()) {
            $lines[] = JournalLineDraft::signed(AccountMapping::resolve('courier.additional_charges', $on)->id, $review->adjustment->negated(), "Courier adjustment, {$memo}");
        }

        if (! $review->compensation->isZero()) {
            $lines[] = JournalLineDraft::signed(AccountMapping::resolve('courier.compensation', $on)->id, $review->compensation->negated(), "Compensation, {$memo}", null, $courier);
        }

        if (! $settle->isZero()) {
            $lines[] = JournalLineDraft::debit($payable(), $settle, "Earlier shortfall netted off, {$memo}", null, $courier);
        }

        // What this business is left with once the courier netted its payable.
        $remainder = Money::ofMinor($review->share->minor - $settle->minor);

        if ($receivedInto !== null) {
            $bank = Money::ofMinor($remainder->minor + $difference->minor);

            if (! $bank->isZero()) {
                $lines[] = JournalLineDraft::signed((int) Account::findOrFail($receivedInto->account_id)->id, $bank, "Payout, {$memo}", null, $receivedInto);
            }

            if ($difference->isCredit()) {
                $lines[] = JournalLineDraft::debit($courierLedger->id, $difference, "Held back by the courier, {$memo}", null, $courier);
            } elseif ($difference->isDebit()) {
                $lines[] = JournalLineDraft::credit($payable(), $difference, "Paid in excess by the courier, {$memo}", null, $courier);
            }
        } elseif ($remainder->isDebit()) {
            $lines[] = JournalLineDraft::debit($courierLedger->id, $remainder, "Held by the courier, {$memo}", null, $courier);
        } elseif ($remainder->isCredit()) {
            $lines[] = JournalLineDraft::credit($payable(), $remainder, "Owed to the courier, {$memo}", null, $courier);
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
