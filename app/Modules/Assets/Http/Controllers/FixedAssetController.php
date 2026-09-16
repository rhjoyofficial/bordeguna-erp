<?php

namespace App\Modules\Assets\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\Money;
use App\Modules\Assets\Actions\RecordFixedAsset;
use App\Modules\Assets\Models\FixedAsset;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The register of what the business owns and uses rather than sells.
 *
 * ⚠⚠ WHY THIS CONTROLLER EXISTS. `RecordFixedAsset` was complete and tested with
 * no route and no screen, and the whole module loaded migrations only. A
 * delivery van bought through Purchasing becomes STOCK and is expensed as cost
 * of goods sold the moment it is issued - so it passes through one month's P&L
 * and vanishes from the balance sheet. Until this screen existed, that wrong
 * path was the only one a person could reach.
 *
 * ⚠ No accounting behaviour is added here. The period lock, the future-date
 * refusal, asset-accounts-only, the salvage-above-cost refusal and the posting
 * itself all live in the action and stay there.
 */
class FixedAssetController extends Controller
{
    public function index(Request $request): Response
    {
        $assets = FixedAsset::query()
            ->with(['account:id,code,name', 'moneyAccount:id,name', 'supplier:id,name'])
            ->orderByDesc('acquired_on')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $assets->getCollection()->transform(fn (FixedAsset $asset) => [
            'id' => $asset->id,
            'asset_code' => $asset->asset_code,
            'name' => $asset->name,
            // ⚠ toDateString(), not the date cast: a `date` cast serialises in
            // UTC and Dhaka is UTC+6, so a Dhaka acquisition renders a day early.
            'acquired_on' => $asset->acquired_on?->toDateString(),
            'account' => $asset->account?->label(),
            'paid_from' => $asset->moneyAccount?->name,
            'supplier' => $asset->supplier?->name,
            'cost' => Money::ofTaka((string) $asset->cost)->toTaka(),
            'salvage_value' => Money::ofTaka((string) $asset->salvage_value)->toTaka(),
            'useful_life_months' => $asset->useful_life_months,
            'depreciation_starts_on' => $asset->depreciation_starts_on?->toDateString(),
            // ⚠⚠ Both DERIVED from the charge rows, never stored. An
            // `accumulated` column would be a second source of truth that drifts
            // the first time a charge is written by a path that forgets it.
            'accumulated' => $asset->accumulated()->toTaka(),
            'book_value' => Money::ofTaka((string) $asset->cost)
                ->plus($asset->accumulated()->negated())->toTaka(),
            'monthly_charge' => $asset->monthlyCharge()->toTaka(),
            'is_fully_depreciated' => $asset->isFullyDepreciated(),
            // ⚠ Plan §2.12: an asset whose cost is not in the books is not
            // depreciated, and the register says so.
            'cost_on_books' => $asset->cost_on_books,
            'note' => $asset->note,
        ]);

        return Inertia::render('assets/index', [
            'assets' => $assets,
            'canManage' => $request->user()?->can('assets.manage') ?? false,
            'assetAccounts' => $this->capitalisableAccounts(),
            // ⚠ Only accounts that have a ledger mapping. An unmapped one throws
            // MappingNotFound out of the action, so listing it would be offering
            // a guaranteed failure.
            'moneyAccounts' => MoneyAccount::query()->handled()
                ->where('is_active', true)
                ->whereNotNull('account_id')
                ->orderBy('name')
                ->get(['id', 'name', 'type'])
                ->all(),
            'suppliers' => Supplier::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->all(),
            'today' => now()->toDateString(),
        ]);
    }

    public function store(Request $request, RecordFixedAsset $action): RedirectResponse
    {
        $businessId = app(BusinessContext::class)->id();

        $data = $request->validate([
            // ⚠ Unique per business, not globally: the chart is shared across
            // businesses but an asset register is not.
            'asset_code' => ['required', 'string', 'max:64',
                Rule::unique('fixed_assets', 'asset_code')->where('business_id', $businessId)],
            'name' => ['required', 'string', 'max:255'],
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
            'acquired_on' => ['required', 'date'],
            'cost' => ['required', 'numeric', 'gt:0'],
            'salvage_value' => ['nullable', 'numeric', 'gte:0'],
            'useful_life_months' => ['required', 'integer', 'min:1'],
            'depreciation_starts_on' => ['nullable', 'date'],
            'money_account_id' => ['nullable', 'integer', Rule::exists('money_accounts', 'id')
                ->where('business_id', $businessId)],
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')
                ->where('business_id', $businessId)],
            'note' => ['nullable', 'string'],
            'cost_in_opening_balances' => ['nullable', 'boolean'],
        ]);

        try {
            $action->handle(
                assetCode: $data['asset_code'],
                name: $data['name'],
                assetAccount: Account::findOrFail($data['account_id']),
                acquiredOn: $data['acquired_on'],
                cost: (string) $data['cost'],
                usefulLifeMonths: (int) $data['useful_life_months'],
                createdBy: $request->user(),
                moneyAccount: empty($data['money_account_id'])
                    ? null
                    : MoneyAccount::findOrFail($data['money_account_id']),
                supplier: empty($data['supplier_id'])
                    ? null
                    : Supplier::findOrFail($data['supplier_id']),
                salvageValue: (string) ($data['salvage_value'] ?? '0'),
                depreciationStartsOn: $data['depreciation_starts_on'] ?? null,
                note: $data['note'] ?? null,
                costInOpeningBalances: (bool) ($data['cost_in_opening_balances'] ?? false),
            );
        } catch (PeriodClosedException|MappingNotFound $e) {
            throw ValidationException::withMessages(['acquired_on' => $e->getMessage()]);
        }

        return redirect()
            ->route('assets.index')
            ->with('success', 'Asset recorded.');
    }

    /**
     * The accounts a purchase may reasonably be capitalised into.
     *
     * ⚠⚠ NOT simply "every postable Asset account". That set includes Cash at
     * Bank, Accounts Receivable and Stock on Hand - a picker whose choices are
     * overwhelmingly wrong. Narrowed to the NON-CURRENT asset group, derived
     * from the chart's own parent structure rather than a list kept here, so an
     * account Joy adds under it appears without anybody remembering this method.
     *
     * ⚠ Accumulated Depreciation sits in that group and is excluded: the action
     * refuses it outright, for reasons written there.
     *
     * @return array<int, array{id:int, label:string}>
     */
    private function capitalisableAccounts(): array
    {
        $nonCurrent = Account::query()->where('code', '1200')->first();

        return Account::query()
            ->postable()
            ->where('type', AccountType::Asset)
            // ⚠ One level, which is what the seeded chart is. A deeper tree would
            // need a recursive walk; this asserts the shape that exists rather
            // than pretending to handle one that does not.
            ->when($nonCurrent !== null, fn ($q) => $q->where('parent_id', $nonCurrent->id))
            // ⚠⚠ `!=` alone would DROP every account whose slug is NULL, because
            // SQL comparisons against NULL are never true - which is almost the
            // whole list. The null case has to be spelled out.
            ->where(fn ($q) => $q->whereNull('system_slug')
                ->orWhere('system_slug', '!=', 'accumulated_depreciation'))
            ->inChartOrder()
            ->get()
            ->map(fn (Account $account) => ['id' => $account->id, 'label' => $account->label()])
            ->all();
    }
}
