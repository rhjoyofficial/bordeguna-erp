<?php

namespace App\Modules\Sales\Couriers\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Couriers\Fees\CourierFeeVariance;
use App\Modules\Sales\Couriers\Fees\FeeBrackets;
use App\Modules\Sales\Couriers\Models\CourierRateCard;
use App\Modules\Sales\Couriers\Models\CourierZoneBand;
use App\Modules\Sales\Couriers\Providers\CourierProviderRegistry;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sales > Courier fees (courier hub slice 5): what the ERP expected each parcel
 * to cost, what the courier quoted, and what its statement charged, with the
 * parcels left out of the learning and why.
 *
 * ⚠ Our COST only. The customer's delivery charge is revenue and lives on the
 * invoice; it is never compared here.
 */
class CourierFeesController extends Controller
{
    public function index(Request $request, CourierFeeVariance $variance, CourierProviderRegistry $registry): Response
    {
        $filters = $request->validate([
            'provider' => ['nullable', 'string', 'max:40'],
            'band' => ['nullable', 'string', 'max:40'],
            'bracket' => ['nullable', 'string', 'max:20'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $filters['from'] ??= now('Asia/Dhaka')->subDays(89)->toDateString();
        $filters['to'] ??= now('Asia/Dhaka')->toDateString();
        $filters += ['provider' => null, 'band' => null, 'bracket' => null];

        $providers = collect($registry->connectedCodes())
            ->map(fn (string $code) => ['code' => $code, 'name' => $registry->get($code)->name()])
            ->values();

        return Inertia::render('sales/courier-fees/index', [
            'filters' => $filters,
            'report' => $variance->report($filters),
            'providers' => $providers,
            'bands' => CourierZoneBand::query()->orderBy('provider')->orderBy('sort_order')->get(['provider', 'code', 'name']),
            'brackets' => FeeBrackets::labels(),
            'cards' => $providers->map(fn (array $p) => [
                'provider' => $p['code'],
                'version' => CourierRateCard::effectiveFor($p['code'])?->version,
            ])->values(),
            'can_propose' => $request->user()->can('couriers.ratecards.propose'),
        ]);
    }
}
