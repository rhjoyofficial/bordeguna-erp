<?php

namespace App\Modules\Assets\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Support\Money;
use App\Modules\Assets\Actions\ChargeDepreciation;
use App\Modules\Assets\Models\FixedAsset;
use App\Modules\Assets\Models\FixedAssetDepreciation;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One month's wear on everything the business owns, run by a person.
 *
 * ⚠⚠ RUN DELIBERATELY, NOT ON A SCHEDULE. The action's own note says so and this
 * screen is what makes that possible: until it existed, `ChargeDepreciation` had
 * no caller at all, so no asset ever wore out in the books and every balance
 * sheet showed vehicles at their original cost for ever.
 *
 * ⚠ Running the same month twice is harmless - a unique index on
 * (asset, year, month) and a check before each charge - so this screen does not
 * re-implement that guard, it relies on it and reports what was skipped.
 */
class DepreciationController extends Controller
{
    public function index(Request $request): Response
    {
        $charges = FixedAssetDepreciation::query()
            ->with('asset:id,asset_code,name')
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->orderBy('fixed_asset_id')
            ->paginate(50)
            ->withQueryString();

        $charges->getCollection()->transform(fn (FixedAssetDepreciation $charge) => [
            'id' => $charge->id,
            'period' => sprintf('%04d-%02d', $charge->year, $charge->month),
            'charged_on' => $charge->charged_on?->toDateString(),
            'asset' => trim(($charge->asset?->asset_code ?? '').' '.($charge->asset?->name ?? '')),
            'amount' => Money::ofTaka((string) $charge->amount)->toTaka(),
        ]);

        // ⚠ The month OFFERED defaults to the last one that has finished. The
        // action refuses an unfinished month outright, so defaulting to the
        // current one would present a form whose default is always rejected.
        $lastComplete = Carbon::now()->subMonthNoOverflow()->startOfMonth();

        return Inertia::render('assets/depreciation', [
            'charges' => $charges,
            'canRun' => $request->user()?->can('assets.depreciation.run') ?? false,
            'defaultYear' => $lastComplete->year,
            'defaultMonth' => $lastComplete->month,
            // ⚠⚠ Surfaced rather than discovered at the moment of running. With
            // no mapping the run is refused (since 13 Oct 2026 it no longer
            // writes charge rows that post nothing), so the screen says so first.
            'hasLedgerMapping' => AccountMapping::forKeyOn('assets.depreciation_expense', Carbon::now()) !== null,
            // FEA-009 (14 Oct 2026): one query, not one sum per asset, and only
            // assets the run would charge: one whose cost is not on the books is
            // skipped by the run, so it is not counted here either.
            'depreciableAssets' => FixedAsset::query()->held()->withSum('depreciations', 'amount')->get()
                ->filter(fn (FixedAsset $asset) => $asset->costIsOnTheBooks() && ! $asset->isFullyDepreciated())
                ->count(),
        ]);
    }

    public function store(Request $request, ChargeDepreciation $action): RedirectResponse
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2999'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        try {
            $result = $action->handle((int) $data['year'], (int) $data['month'], $request->user());
        } catch (PeriodClosedException|MappingNotFound $e) {
            throw ValidationException::withMessages(['period' => $e->getMessage()]);
        }

        return redirect()
            ->route('assets.depreciation.index')
            ->with('success', sprintf(
                '%d asset(s) depreciated for %s, %s charged. %d skipped.%s',
                $result['charged'],
                Carbon::create((int) $data['year'], (int) $data['month'], 1)->format('F Y'),
                $result['total']->toTaka(),
                $result['skipped'],
                // ⚠ Said out loud: these wait for their cost to reach the books.
                $result['not_on_books'] === [] ? '' : ' Not depreciated because their cost is not in the books: '.implode(', ', $result['not_on_books']).'.',
            ));
    }
}
