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
use App\Modules\Sales\Models\CourierStatementLine;
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
 * ⭐ Slice 8: compensation on a line closes the loss claim on its parcel, and
 * a statement dated into a closed month posts on the first open day.
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

                // ⚠⚠ SAL-002 (audit 14 Oct 2026): on a payout shared by two
                // brands, the deposit is measured against the WHOLE file, so
                // each brand saw the whole difference and booked it again (a
                // 50 hold-back on 1,870 left 1,770 in the banks and 100 held
                // back). The difference is now booked ONCE: whatever another
                // business already booked on the same payout is taken off,
                // and this business books only what is left.
                //
                // OWNER DECISION PENDING (audit Q1): which brand should carry
                // it, the first to post, split by share, or a fixed one. Until
                // Joy decides, the WHOLE difference stays with the business
                // that posts the payout with the difference first; the other
                // brand books its share as it is and no difference at all.
                $bookedElsewhere = $this->differenceBookedElsewhere($statement);
                $difference = Money::ofMinor($deposit->minor - $expected->minor - $bookedElsewhere->minor);

                if (! $difference->isZero() && ($differenceReason === null || mb_strlen($differenceReason) < 5)) {
                    $already = $bookedElsewhere->isZero() ? '' : " The other brand already booked {$bookedElsewhere->format()} of the difference on this payout.";

                    throw ValidationException::withMessages([
                        'deposit_amount' => "The statement pays out {$expected->format()}, but {$deposit->format()} arrived.{$already} Check the file is this payout's, or say why they differ.",
                    ]);
                }

                $on = Carbon::parse($receivedOn ?? 'today')->startOfDay();

                if ($on->isFuture()) {
                    throw ValidationException::withMessages(['received_on' => 'The money cannot have arrived on a day that has not happened yet.']);
                }
            } else {
                $on = Carbon::parse($statement->statement_date)->startOfDay();
            }

            // ⭐ Risk 9 (slice 8): a statement or a deposit dated into a closed
            // month posts on the first open day, saying so. The bank's own
            // date stays in received_on for the bank match.
            $postOn = AccountingPeriod::firstOpenOnOrAfter($on);
            $cutoffNote = $postOn->equalTo($on) ? null
                : "Dated {$on->toDateString()}, in a closed period; posted on {$postOn->toDateString()}.";

            $this->post($statement, $review, $depositArrived ? $receivedInto : null, $settle, $difference, $postOn, $postedBy);

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

                // ⭐ Slice 8: compensation for a parcel with a loss claim closes it.
                CourierLossClaims::settleFromStatement($row['line'], $row['consignment'], $postedBy);
            }

            $statement->forceFill([
                'status' => CourierStatement::POSTED,
                'received_money_account_id' => $depositArrived ? $receivedInto->id : null,
                'deposit_amount' => $depositArrived ? $deposit->toTaka() : null,
                'received_on' => $depositArrived ? $on : null,
                'bank_reference' => trim((string) $bankReference) ?: null,
                'deposit_difference_reason' => $difference->isZero() ? null : $differenceReason,
                // SAL-002: what THIS business booked of the difference, so the
                // other brand posting the same payout books only the rest.
                'deposit_difference_booked' => $depositArrived ? $difference->toTaka() : null,
                'payable_settled_amount' => $settle->isZero() ? null : $settle->toTaka(),
                'share_amount' => $review->share->toTaka(),
                'posted_on' => $postOn,
                'cutoff_note' => $cutoffNote,
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
     * SAL-002 (14 Oct 2026): the part of this payout's deposit difference that
     * another business already booked, when two brands share one courier
     * account. The same payout is the same courier, posted in another business,
     * under the same file or the same courier invoice id. A statement posted
     * before the `deposit_difference_booked` column booked the WHOLE difference,
     * so that is what it counts for.
     */
    private function differenceBookedElsewhere(CourierStatement $statement): Money
    {
        $siblings = CourierStatement::query()->withoutGlobalScopes()
            ->where('business_id', '!=', $statement->business_id)
            ->where('status', CourierStatement::POSTED)
            ->where('provider_code', $statement->provider_code)
            ->whereNotNull('deposit_amount')
            ->where(fn ($query) => $query->where('statement_reference', $statement->statement_reference)
                ->when($statement->source_sha256 !== null, fn ($query) => $query->orWhere('source_sha256', $statement->source_sha256)))
            ->get();

        return Money::sum($siblings->map(function (CourierStatement $sibling) {
            if ($sibling->deposit_difference_booked !== null) {
                return Money::ofTaka((string) $sibling->deposit_difference_booked);
            }

            $fileTotal = Money::sum(CourierStatementLine::query()->withoutGlobalScopes()
                ->where('courier_statement_id', $sibling->id)
                ->pluck('payout')
                ->map(fn ($payout) => Money::ofTaka((string) $payout)));

            return Money::ofMinor(Money::ofTaka((string) $sibling->deposit_amount)->minor
                - $fileTotal->minor
                + Money::ofTaka((string) ($sibling->payable_settled_amount ?? '0'))->minor);
        }));
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
