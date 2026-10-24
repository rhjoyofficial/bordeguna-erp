<?php

namespace App\Modules\Assets\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\ChartPermissions;
use App\Modules\Accounting\Support\Cutover;
use App\Modules\Accounting\Support\Money;
use App\Modules\Assets\Actions\DisposeFixedAsset;
use App\Modules\Assets\Actions\RecordFixedAsset;
use App\Modules\Assets\Corrections\FixedAssetActionPolicy;
use App\Modules\Assets\Models\AssetCategory;
use App\Modules\Assets\Models\FixedAsset;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\ValidatesActionReason;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Support\CorrectionReasons;
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
    use ValidatesActionReason;

    public function index(Request $request): Response
    {
        $policy = app(FixedAssetActionPolicy::class);
        $user = $request->user();

        $assets = FixedAsset::query()
            ->with(['account:id,code,name', 'moneyAccount:id,name', 'supplier:id,name', 'reversedBy:id,name', 'disposedBy:id,name'])
            // FEA-009 (14 Oct 2026): one total per row, loaded with the page.
            ->withSum('depreciations', 'amount')
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
            // ⭐ FEA-004 (14 Oct 2026): the part of `accumulated` written off
            // before cutover, per the opening trial balance, and the life left
            // then. Both shown so the register reads like the accountant's TB.
            'opening_accumulated' => $asset->openingAccumulated()->toTaka(),
            'remaining_life_months' => $asset->remaining_life_months,
            'opening_as_of' => $asset->opening_as_of?->toDateString(),
            'book_value' => Money::ofTaka((string) $asset->cost)
                ->plus($asset->accumulated()->negated())->toTaka(),
            'monthly_charge' => $asset->monthlyCharge()->toTaka(),
            'is_fully_depreciated' => $asset->isFullyDepreciated(),
            // ⚠ Plan §2.12: an asset whose cost is not in the books is not
            // depreciated, and the register says so.
            'cost_on_books' => $asset->cost_on_books,
            'note' => $asset->note,
            // ⭐ Corrections wave 2 (24 Oct 2026): Reverse and Dispose, from the
            // policy (a blocked one stays greyed with its sentence), and the
            // marks a reversed or disposed asset carries.
            'state' => $asset->isReversed() ? 'reversed' : ($asset->isDisposed() ? 'disposed' : 'held'),
            'reversed' => $asset->isReversed() ? [
                'on' => $asset->reversed_on?->toDateString(),
                'by' => $asset->reversedBy?->name,
                'reason' => $asset->reversal_reason,
            ] : null,
            'disposed' => $asset->isDisposed() ? [
                'on' => $asset->disposed_on?->toDateString(),
                'by' => $asset->disposedBy?->name,
                'method' => $asset->disposal_method,
                'proceeds' => Money::ofTaka((string) $asset->disposal_proceeds)->toTaka(),
                'reason' => $asset->disposal_reason,
            ] : null,
            'figures' => DisposeFixedAsset::figures($asset),
            'actions' => $policy->actionsFor($asset, $user)->toArray()['actions'],
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
            // FEA-003 (14 Oct 2026): an opening asset is one owned by this date.
            'cutoverDate' => Cutover::date()->toDateString(),
            'canRecordOpening' => ($user = $request->user()) !== null
                && ($user->is_super_admin || $user->hasRole('Owner') || $user->can(ChartPermissions::STRUCTURE)),
            'categories' => AssetCategory::query()->where('is_active', true)->orderBy('sort')->orderBy('name')
                ->get(['id', 'name', 'code', 'default_useful_life_months'])->all(),
            'correctionReasons' => CorrectionReasons::for(['reversal', 'disposal']),
            // Dispose: where a gain or a loss is booked (an income head, an
            // expense head), the Owner's choice in the dialog.
            'gainLossHeads' => $user?->can(FixedAssetActionPolicy::PERMISSION) ? $this->gainLossHeads() : [],
        ]);
    }

    /**
     * ⭐ Corrections wave 2 (24 Oct 2026): the asset leaves the business. The
     * policy is asked again here and once more under the lock in the action.
     */
    public function dispose(Request $request, FixedAsset $fixedAsset, DisposeFixedAsset $action): RedirectResponse
    {
        $availability = app(FixedAssetActionPolicy::class)->availability($fixedAsset, $request->user(), DocumentAction::Dispose);

        abort_unless($request->user()->can($availability->permission), 403);

        $businessId = app(BusinessContext::class)->id();

        $data = $request->validate([
            'disposed_on' => ['required', 'date'],
            'method' => ['required', Rule::in(DisposeFixedAsset::METHODS)],
            'proceeds' => ['nullable', 'numeric', 'gte:0'],
            'money_account_id' => ['nullable', 'integer', Rule::exists('money_accounts', 'id')->where('business_id', $businessId)],
            'gain_loss_account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            ...$this->actionReasonRules('disposal'),
        ], $this->actionReasonMessages());

        if (! $availability->allowed) {
            throw ValidationException::withMessages(['reason_text' => $availability->blockers[0]->message]);
        }

        $action->handle(
            asset: $fixedAsset,
            disposedOn: $data['disposed_on'],
            method: $data['method'],
            proceeds: (string) ($data['proceeds'] ?? '0'),
            moneyAccount: empty($data['money_account_id']) ? null : MoneyAccount::findOrFail($data['money_account_id']),
            gainOrLossHead: empty($data['gain_loss_account_id']) ? null : Account::findOrFail($data['gain_loss_account_id']),
            by: $request->user(),
            why: $this->actionReason($request),
        );

        return back()->with('success', "{$fixedAsset->asset_code} disposed. It is off the books and will not be depreciated again.");
    }

    /** @return list<array{id: int, label: string, type: string}> */
    private function gainLossHeads(): array
    {
        return Account::query()
            ->whereIn('type', [AccountType::Income, AccountType::Expense])
            ->where('is_postable', true)
            ->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->map(fn (Account $a) => ['id' => $a->id, 'label' => $a->label(), 'type' => $a->type->value])
            ->values()
            ->all();
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
            // A class supplies the life when none is typed (finance decision B4).
            'asset_category_id' => ['nullable', 'integer', Rule::exists('asset_categories', 'id')->where('business_id', $businessId)],
            'useful_life_months' => ['nullable', 'integer', 'min:1', 'required_without:asset_category_id'],
            'depreciation_starts_on' => ['nullable', 'date'],
            'money_account_id' => ['nullable', 'integer', Rule::exists('money_accounts', 'id')
                ->where('business_id', $businessId)],
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')
                ->where('business_id', $businessId)],
            'note' => ['nullable', 'string'],
            'cost_in_opening_balances' => ['nullable', 'boolean'],
            // FEA-004 (14 Oct 2026): an opening asset's figures at cutover.
            'opening_accumulated_depreciation' => ['nullable', 'numeric', 'gte:0'],
            'remaining_life_months' => ['nullable', 'integer', 'min:0'],
        ]);

        $category = empty($data['asset_category_id']) ? null : AssetCategory::query()->findOrFail($data['asset_category_id']);
        $life = $data['useful_life_months'] ?? $category?->default_useful_life_months;

        if ($life === null) {
            throw ValidationException::withMessages(['useful_life_months' => "{$category?->name} has no default life. Type the useful life."]);
        }

        try {
            $action->handle(
                assetCode: $data['asset_code'],
                name: $data['name'],
                assetAccount: Account::findOrFail($data['account_id']),
                acquiredOn: $data['acquired_on'],
                cost: (string) $data['cost'],
                usefulLifeMonths: (int) $life,
                category: $category,
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
                openingAccumulated: (string) ($data['opening_accumulated_depreciation'] ?? '0'),
                remainingLifeMonths: isset($data['remaining_life_months']) ? (int) $data['remaining_life_months'] : null,
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
