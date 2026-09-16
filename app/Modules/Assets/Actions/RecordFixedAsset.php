<?php

namespace App\Modules\Assets\Actions;

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Assets\Models\FixedAsset;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Buying something the business will keep.
 *
 *   Dr  the asset account   Cr  the account it was paid from
 *
 * ⚠⚠ This is the entry that keeps a delivery van OFF the profit and loss. R8:
 * an asset bought through Purchasing becomes stock and is expensed as cost of
 * goods sold the moment it is issued, so it would go through one month's P&L
 * and disappear from the balance sheet entirely.
 */
class RecordFixedAsset
{
    public function __construct(private readonly PostJournal $postJournal) {}

    /**
     * @throws ValidationException
     */
    public function handle(
        string $assetCode,
        string $name,
        Account $assetAccount,
        mixed $acquiredOn,
        string $cost,
        int $usefulLifeMonths,
        User $createdBy,
        ?MoneyAccount $moneyAccount = null,
        ?Supplier $supplier = null,
        string $salvageValue = '0',
        mixed $depreciationStartsOn = null,
        ?string $note = null,
        bool $costInOpeningBalances = false,
    ): FixedAsset {
        $acquired = Carbon::parse($acquiredOn)->startOfDay();
        $starts = $depreciationStartsOn ? Carbon::parse($depreciationStartsOn)->startOfDay() : $acquired->copy();

        AccountingPeriod::assertOpenFor($acquired);

        if ($acquired->isFuture()) {
            throw ValidationException::withMessages(['acquired_on' => 'An asset cannot be acquired in the future.']);
        }

        if ($usefulLifeMonths < 1) {
            throw ValidationException::withMessages(['useful_life_months' => 'Useful life must be at least one month.']);
        }

        // ⚠ An asset must sit in an ASSET account. Without this, a van could be
        // filed under Rent and the balance sheet would be short by its value
        // while the books still balanced perfectly.
        if ($assetAccount->type !== AccountType::Asset) {
            throw ValidationException::withMessages(['account_id' => "{$assetAccount->label()} is not an asset account."]);
        }

        if (! $assetAccount->is_postable || ! $assetAccount->is_active) {
            throw ValidationException::withMessages(['account_id' => "{$assetAccount->label()} cannot be posted to."]);
        }

        // ⚠⚠ AN ASSET IS NEVER ACQUIRED INTO AN ACCOUNT THE SOFTWARE POSTS TO
        // ITSELF. Cash at Bank, Stock on Hand, Accounts Receivable and
        // Accumulated Depreciation are all type Asset, postable and active, so
        // every check above passes them - and each one is a different way to
        // make the balance sheet wrong while the trial balance stays at zero:
        //
        //   Stock on Hand           a van becomes inventory, and the next FIFO
        //                           issue writes it off as cost of goods sold
        //   Cash at Bank            Dr cash / Cr cash nets to nothing; the
        //                           purchase leaves NO trace at all
        //   Accumulated Depreciation a DEBIT into the contra-asset cancels real
        //                           depreciation - wrong by twice the value, in
        //                           the direction that flatters the business
        //
        // ⚠ The `system_slug` is the honest test, and it generalises rather than
        // blacklisting three codes: an account the software finds by name is one
        // it posts to on its own, and a genuinely new asset account somebody
        // adds to the chart carries no slug and is unaffected.
        //
        // ⚠⚠ Guarded HERE and not only in the picker. The screen already offers
        // the non-current group only - and a screen filter is a suggestion,
        // while this is a rule. Verified on scratch MySQL, where the engine
        // accepted both Stock on Hand and Cash at Bank before this existed.
        if ($assetAccount->system_slug !== null) {
            throw ValidationException::withMessages([
                'account_id' => $assetAccount->system_slug === 'accumulated_depreciation'
                    ? "{$assetAccount->label()} is where depreciation accumulates, not where an asset is recorded."
                    : "{$assetAccount->label()} is used by the system itself and cannot hold a fixed asset.",
            ]);
        }

        $price = Money::ofTaka($cost);
        $salvage = Money::ofTaka($salvageValue);

        if ($price->minor <= 0) {
            throw ValidationException::withMessages(['cost' => 'An asset must cost something.']);
        }

        // ⚠ Salvage above cost would make the depreciable amount negative and
        // every month's charge a credit - an asset that earns money by ageing.
        if ($salvage->minor > $price->minor) {
            throw ValidationException::withMessages([
                'salvage_value' => 'Salvage value cannot be more than the asset cost.',
            ]);
        }

        if ($starts->lessThan($acquired)) {
            throw ValidationException::withMessages([
                'depreciation_starts_on' => 'Depreciation cannot start before the asset was acquired.',
            ]);
        }

        // ⚠ Paid from an account OR already in the opening balances, never both:
        // the second would put the same cost on the books twice.
        if ($moneyAccount !== null && $costInOpeningBalances) {
            throw ValidationException::withMessages([
                'money_account_id' => 'An asset paid for now is not also in the opening balances. Choose one.',
            ]);
        }

        return DB::transaction(function () use ($assetCode, $name, $assetAccount, $acquired, $starts, $price, $salvage, $usefulLifeMonths, $createdBy, $moneyAccount, $supplier, $note, $costInOpeningBalances) {
            $asset = FixedAsset::create([
                'asset_code' => $assetCode,
                'name' => $name,
                'account_id' => $assetAccount->id,
                'acquired_on' => $acquired,
                'cost' => $price->toTaka(),
                'salvage_value' => $salvage->toTaka(),
                'useful_life_months' => $usefulLifeMonths,
                'depreciation_starts_on' => $starts,
                'money_account_id' => $moneyAccount?->id,
                'supplier_id' => $supplier?->id,
                // ⚠ The recorder's word; the acquisition journal below overrides it.
                'cost_on_books' => $costInOpeningBalances ? FixedAsset::COST_IN_OPENING_BALANCES : null,
                'note' => $note,
                'created_by' => $createdBy->id,
            ]);

            // ⚠ The register is useful even before the ledger exists - knowing
            // what you own is not an accounting question. So the posting is
            // skipped when accounting is not configured, exactly as every other
            // integration does.
            if ($moneyAccount !== null && $this->postingIsPossible($acquired)) {
                if ($moneyAccount->account_id === null) {
                    throw MappingNotFound::for("money account [{$moneyAccount->name}]", $acquired);
                }

                $this->postJournal->handle(new JournalDraft(
                    date: $acquired,
                    lines: [
                        JournalLineDraft::debit($assetAccount->id, $price, $asset->asset_code, $supplier),
                        JournalLineDraft::credit($moneyAccount->account_id, $price, $moneyAccount->label(), null, $moneyAccount),
                    ],
                    memo: "Acquired {$asset->asset_code} - {$asset->name}",
                    source: $asset,
                ), $createdBy);

                $asset->forceFill(['cost_on_books' => FixedAsset::COST_BY_ACQUISITION])->save();
            }

            return $asset->fresh();
        });
    }

    private function postingIsPossible(Carbon $on): bool
    {
        return AccountMapping::forKeyOn('assets.depreciation_expense', $on) !== null
            && app(BusinessContext::class)->id() !== null;
    }
}
