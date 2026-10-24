<?php

namespace App\Modules\Assets\Actions;

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\MoneyLedgers;
use App\Modules\Assets\Corrections\FixedAssetActionPolicy;
use App\Modules\Assets\Models\FixedAsset;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\CorrectionLog;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\Tk;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\MoneyAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ Corrections wave 2, B8 (24 Oct 2026): a fixed asset leaves the business
 * (FINAL-PLAN 3.5, "Dispose / Sell with the gain or loss journal. Depreciation
 * stops."). One journal, through PostJournal:
 *
 *   Dr  Accumulated Depreciation   what has been written off (the charges,
 *                                  plus an opening asset's figure at cutover)
 *   Dr  the money account          what it fetched, when it was sold
 *   Dr  the loss head              when it fetched less than its book value
 *       Cr  the asset account      its cost
 *       Cr  the gain head          when it fetched more than its book value
 *
 * ⚠ The asset is KEPT and marked Disposed (date, method, money, by, why), and
 * the run never charges it again (FixedAsset::isDepreciableIn).
 *
 * ⚠ The gain or loss head is the Owner's choice in the dialog (an income head
 * for a gain, an expense head for a loss): the chart has no disposal head of
 * its own, and inventing one is the accountant's call, not this action's.
 *
 * ⚠ Dated on the day it left, which must be an open day, not in the future,
 * not before it was bought, and not before depreciation already charged (a
 * month charged after the asset left would be wear on something not owned).
 */
class DisposeFixedAsset
{
    public const METHODS = ['sold', 'scrapped', 'donated', 'lost'];

    public function __construct(
        private readonly PostJournal $postJournal,
        private readonly FixedAssetActionPolicy $policy,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(
        FixedAsset $asset,
        string $disposedOn,
        string $method,
        string $proceeds,
        ?MoneyAccount $moneyAccount,
        ?Account $gainOrLossHead,
        User $by,
        ActionReason $why,
    ): FixedAsset {
        return DB::transaction(function () use ($asset, $disposedOn, $method, $proceeds, $moneyAccount, $gainOrLossHead, $by, $why) {
            // ⚠ Re-read under a lock: two Dispose clicks, or a Dispose and a
            // Reverse, cannot both take the asset off the books.
            $asset = FixedAsset::query()->whereKey($asset->getKey())->lockForUpdate()->firstOrFail();
            $availability = $this->policy->availability($asset, $by, DocumentAction::Dispose);

            if (! $availability->allowed) {
                throw ValidationException::withMessages(['reason_text' => $availability->blockers[0]->message]);
            }

            $on = Carbon::parse($disposedOn)->startOfDay();
            $this->assertDate($asset, $on);

            if (! in_array($method, self::METHODS, true)) {
                throw ValidationException::withMessages(['method' => 'Choose how it left: sold, scrapped, donated or lost.']);
            }

            $received = Money::ofTaka($proceeds === '' ? '0' : $proceeds);

            if ($received->minor < 0) {
                throw ValidationException::withMessages(['proceeds' => 'What it fetched cannot be below zero.']);
            }

            if ($method !== 'sold' && $received->minor > 0) {
                throw ValidationException::withMessages(['proceeds' => 'Only a sale brings money in. Choose Sold, or leave the amount at zero.']);
            }

            if ($received->minor > 0) {
                if ($moneyAccount === null || ! $moneyAccount->is_active || $moneyAccount->account_id === null) {
                    throw ValidationException::withMessages(['money_account_id' => 'Choose the money account the sale money went into.']);
                }

                if (($refusal = $moneyAccount->handRefusal()) !== null) {
                    throw ValidationException::withMessages(['money_account_id' => $refusal]);
                }
            }

            $cost = Money::ofTaka((string) $asset->cost);
            $accumulated = $asset->accumulated();
            $bookValue = $cost->plus($accumulated->negated());
            $difference = $received->minor - $bookValue->minor; // > 0 gain, < 0 loss

            $head = $difference === 0 ? null : $this->head($gainOrLossHead, $difference > 0);
            $lines = [];

            if ($accumulated->minor > 0) {
                if (AccountMapping::forKeyOn('assets.accumulated_depreciation', $on) === null) {
                    throw ValidationException::withMessages(['disposed_on' => 'Accumulated Depreciation has no ledger head yet. Map it under Accounting, Account mappings, then dispose of the asset.']);
                }

                $lines[] = JournalLineDraft::debit(AccountMapping::resolve('assets.accumulated_depreciation', $on)->id, $accumulated, $asset->asset_code);
            }

            if ($received->minor > 0) {
                $lines[] = JournalLineDraft::debit((int) $moneyAccount->account_id, $received, $moneyAccount->label(), null, $moneyAccount);
            }

            if ($difference < 0) {
                $lines[] = JournalLineDraft::debit($head->id, Money::ofMinor(-$difference), "Loss on {$asset->asset_code}");
            }

            $lines[] = JournalLineDraft::credit((int) $asset->account_id, $cost, $asset->asset_code);

            if ($difference > 0) {
                $lines[] = JournalLineDraft::credit($head->id, Money::ofMinor($difference), "Gain on {$asset->asset_code}");
            }

            try {
                $journal = $this->postJournal->handle(new JournalDraft(
                    date: $on,
                    lines: $lines,
                    memo: "Disposed {$asset->asset_code} ({$method}) - {$asset->name}: {$why->display()}",
                    source: $asset,
                ), $by);
            } catch (PeriodClosedException $e) {
                throw ValidationException::withMessages(['disposed_on' => $e->getMessage()]);
            }

            $asset->forceFill([
                'disposed_on' => $on,
                'disposed_at' => now(),
                'disposed_by' => $by->id,
                'disposal_method' => $method,
                'disposal_proceeds' => $received->toTaka(),
                'disposal_money_account_id' => $received->minor > 0 ? $moneyAccount?->id : null,
                'disposal_account_id' => $head?->id,
                'disposal_reason' => $why->display(),
                'disposal_journal_id' => $journal->id,
            ])->save();

            CorrectionLog::event('disposed', $by, $asset, [
                'reason' => $why->display(),
                'date' => $on->toDateString(),
                'method' => $method,
                'proceeds' => Tk::of($received->minor),
                'book_value' => Tk::of($bookValue->minor),
                'journal' => $journal->journal_number,
            ]);

            return $asset->fresh();
        });
    }

    /**
     * What the dialog shows before the Owner confirms: book value, and what the
     * gain or loss would be for a given amount received.
     *
     * @return array{cost: string, accumulated: string, book_value: string, book_value_minor: int}
     */
    public static function figures(FixedAsset $asset): array
    {
        $cost = Money::ofTaka((string) $asset->cost);
        $accumulated = $asset->accumulated();
        $book = $cost->plus($accumulated->negated());

        return [
            'cost' => $cost->toTaka(),
            'accumulated' => $accumulated->toTaka(),
            'book_value' => $book->toTaka(),
            'book_value_minor' => $book->minor,
        ];
    }

    private function assertDate(FixedAsset $asset, Carbon $on): void
    {
        if ($on->isFuture()) {
            throw ValidationException::withMessages(['disposed_on' => 'An asset cannot leave the business in the future.']);
        }

        if ($on->lessThan($asset->acquired_on->copy()->startOfDay())) {
            throw ValidationException::withMessages(['disposed_on' => "{$asset->asset_code} was acquired on ".Tk::date($asset->acquired_on).', so it cannot leave before that.']);
        }

        $lastCharge = $asset->depreciations()->max('charged_on');

        if ($lastCharge !== null && $on->lessThan(Carbon::parse($lastCharge)->startOfDay())) {
            throw ValidationException::withMessages(['disposed_on' => 'Depreciation was charged through '.Tk::date(Carbon::parse($lastCharge)).', so it cannot leave before that.']);
        }

        if (AccountingPeriod::isClosedOn($on)) {
            throw ValidationException::withMessages(['disposed_on' => $on->format('F Y').' is closed. Choose an open day.']);
        }
    }

    private function head(?Account $head, bool $gain): Account
    {
        $want = $gain ? AccountType::Income : AccountType::Expense;

        if ($head === null) {
            throw ValidationException::withMessages(['gain_loss_account_id' => $gain
                ? 'It fetched more than its book value: choose the income head the gain goes to.'
                : 'It fetched less than its book value: choose the expense head the loss goes to.']);
        }

        if ($head->type !== $want || ! $head->is_postable || ! $head->is_active || MoneyLedgers::isMoneyHead($head)) {
            throw ValidationException::withMessages(['gain_loss_account_id' => $gain
                ? "{$head->label()} is not an income head that can be posted to."
                : "{$head->label()} is not an expense head that can be posted to."]);
        }

        return $head;
    }
}
