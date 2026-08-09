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

        // ⚠⚠ FOUND WHILE BUILDING THE SCREEN, 9 August. Accumulated Depreciation
        // is an Asset account, postable and active, so every check above passed
        // it - and filing a van's cost there would put a DEBIT into the
        // contra-asset that cancels real accumulated depreciation. The trial
        // balance stays at zero and the balance sheet is wrong by twice the
        // asset's value, in the direction that makes the business look richer.
        //
        // ⚠ Guarded in the ACTION rather than only in the picker, because a
        // screen-level filter is a suggestion and this is a rule. The system
        // slug is the honest test: this account exists for the software's own
        // posting and is never somewhere a person files a purchase.
        if ($assetAccount->system_slug === 'accumulated_depreciation') {
            throw ValidationException::withMessages([
                'account_id' => "{$assetAccount->label()} is where depreciation accumulates, not where an asset is recorded.",
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

        return DB::transaction(function () use ($assetCode, $name, $assetAccount, $acquired, $starts, $price, $salvage, $usefulLifeMonths, $createdBy, $moneyAccount, $supplier, $note) {
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
