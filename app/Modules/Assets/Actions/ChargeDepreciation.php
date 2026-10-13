<?php

namespace App\Modules\Assets\Actions;

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Assets\Models\FixedAsset;
use App\Modules\Foundation\Models\AccountingPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * One month's wear on everything the business owns.
 *
 *   Dr  Depreciation   Cr  Accumulated Depreciation
 *
 * ⚠⚠ RUN DELIBERATELY, NOT ON A SCHEDULE - yet. This posts real cost into a
 * month, and the nightly expiry sweep set the precedent: it PROPOSES a write-off
 * and a human approves. Scheduling this before anybody has watched it run a few
 * times would mean money moving in the books with nobody looking. Recorded as a
 * decision in the readiness review; scheduling it later is one line.
 *
 * ⚠ Idempotent twice over: a unique index on (asset, year, month) in the
 * database, and a check before each charge. The index is the one that matters -
 * an in-code check is one retry away from being wrong.
 */
class ChargeDepreciation
{
    public function __construct(private readonly PostJournal $postJournal) {}

    /**
     * @return array{charged: int, skipped: int, total: Money}
     */
    public function handle(int $year, int $month, User $postedBy): array
    {
        // ⚠ Charged on the LAST DAY of the month it belongs to, not the day the
        // command happened to be run. Running November's depreciation in
        // December must not put November's cost into December.
        $chargedOn = Carbon::create($year, $month, 1)->endOfMonth()->startOfDay();

        // ⚠⚠ A MONTH THAT HAS NOT FINISHED CANNOT BE DEPRECIATED. Found by a
        // test that looped forward through future periods: PostJournal refused
        // the future-dated journal, which was correct but arrived as a
        // confusing ledger error rather than a statement about depreciation.
        // The asset has not worn out yet, and charging it early would move cost
        // into a month the business has not lived through.
        if ($chargedOn->isFuture()) {
            throw ValidationException::withMessages([
                'period' => sprintf(
                    '%s has not finished yet, so it cannot be depreciated.',
                    $chargedOn->format('F Y'),
                ),
            ]);
        }

        AccountingPeriod::assertOpenFor($chargedOn);

        $charged = 0;
        $skipped = 0;
        $notOnBooks = [];
        $total = Money::zero();

        foreach (FixedAsset::query()->with('depreciations')->orderBy('id')->get() as $asset) {
            // ⚠⚠ Named, not silently skipped: somebody has to put its cost in the
            // books (plan §2.12).
            if (! $asset->costIsOnTheBooks()) {
                $notOnBooks[] = $asset->asset_code;

                continue;
            }

            if (! $asset->isDepreciableIn($year, $month)) {
                $skipped++;

                continue;
            }

            if ($asset->depreciations->firstWhere(fn ($row) => $row->year === $year && $row->month === $month)) {
                $skipped++;

                continue;
            }

            $amount = $asset->monthlyCharge();

            if ($amount->minor <= 0) {
                $skipped++;

                continue;
            }

            // ⚠⚠ Refused BEFORE anything is written. The charge row used to be
            // created and the journal then silently skipped when the expense
            // head was unmapped: the register said "depreciated" for a month
            // the ledger never saw, and the unique index blocked charging it
            // properly once the mapping was fixed. An asset whose cost is on the
            // books has a ledger, so a missing head is a setup gap to name.
            if (AccountMapping::forKeyOn('assets.depreciation_expense', $chargedOn) === null
                || AccountMapping::forKeyOn('assets.accumulated_depreciation', $chargedOn) === null) {
                throw ValidationException::withMessages([
                    'period' => 'Depreciation has no ledger heads yet. Map Depreciation Expense and Accumulated Depreciation under Accounting, Account mappings, then charge the month.',
                ]);
            }

            DB::transaction(function () use ($asset, $year, $month, $chargedOn, $amount, $postedBy) {
                $charge = $asset->depreciations()->create([
                    'business_id' => $asset->business_id,
                    'year' => $year,
                    'month' => $month,
                    'charged_on' => $chargedOn,
                    'amount' => $amount->toTaka(),
                ]);

                $expense = AccountMapping::resolve('assets.depreciation_expense', $chargedOn);
                $accumulated = AccountMapping::resolve('assets.accumulated_depreciation', $chargedOn);

                $this->postJournal->handle(new JournalDraft(
                    date: $chargedOn,
                    lines: [
                        JournalLineDraft::debit($expense->id, $amount, $asset->asset_code),
                        // ⚠ A CREDIT to an ASSET account, which looks wrong and
                        // is right: accumulated depreciation is a contra-asset,
                        // sitting under Assets and carrying a credit balance so
                        // the original cost stays visible beside what has been
                        // written off it.
                        JournalLineDraft::credit($accumulated->id, $amount, $asset->asset_code),
                    ],
                    memo: "Depreciation {$year}-".str_pad((string) $month, 2, '0', STR_PAD_LEFT)." - {$asset->asset_code}",
                    source: $charge,
                ), $postedBy);
            });

            $charged++;
            $total = $total->plus($amount);
        }

        return ['charged' => $charged, 'skipped' => $skipped, 'total' => $total, 'not_on_books' => $notOnBooks];
    }
}
