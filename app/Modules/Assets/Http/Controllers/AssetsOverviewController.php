<?php

namespace App\Modules\Assets\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Support\Money;
use App\Modules\Assets\Enums\AssetCondition;
use App\Modules\Assets\Enums\AssetMovementStatus;
use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Assets\Enums\CompanyAccountKind;
use App\Modules\Assets\Enums\MaintenanceState;
use App\Modules\Assets\Models\AssetCategory;
use App\Modules\Assets\Models\AssetCount;
use App\Modules\Assets\Models\AssetDiscrepancy;
use App\Modules\Assets\Models\AssetHolding;
use App\Modules\Assets\Models\AssetItem;
use App\Modules\Assets\Models\AssetMaintenancePlan;
use App\Modules\Assets\Models\AssetMaintenanceRecord;
use App\Modules\Assets\Models\AssetMovement;
use App\Modules\Assets\Models\CompanyAccount;
use App\Modules\Assets\Models\FixedAsset;
use App\Modules\Assets\Models\FixedAssetDepreciation;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Assets overview (module redesign, 15 October 2026): what the business
 * holds, where it is, what needs somebody today, and (for those who may see
 * them) the accounts and the books, on one page, every number a link to the
 * list behind it.
 *
 * ⚠⚠ THREE PERMISSIONS, THREE CARDS. The physical register is
 * `assets.items.view`, the accounts hub `assets.accounts.view`, the money
 * `assets.view`. A card is sent as null, not as zeros, to someone without its
 * permission: an Asset Keeper sees the things and no amount; a hub reader
 * sees the accounts and no thing. The route itself opens for any one of the
 * three (`assets.overview`, AssetsServiceProvider).
 *
 * ⚠ Every figure is read from the same tables and scopes its list reads
 * (`overdueFromOutside`, `due`, `visibleTo`, the register's own book value),
 * so the overview never says a number its list cannot show.
 */
class AssetsOverviewController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $today = Carbon::today();

        $can = [
            'things' => $user->can('assets.items.view'),
            'accounts' => $user->can('assets.accounts.view'),
            'books' => $user->can('assets.view'),
            'manage_things' => $user->can('assets.items.manage'),
        ];

        $things = $can['things'] ? $this->things($today) : null;
        $accounts = $can['accounts'] ? $this->accounts($today) : null;

        return Inertia::render('assets/overview', [
            'today' => $today->toDateString(),
            'can' => $can,
            'needs' => $this->needs($things, $accounts),
            'things' => $things,
            'accounts' => $accounts,
            'books' => $can['books'] ? $this->books() : null,
        ]);
    }

    /**
     * The physical register: counts, split, where it all is, what is out,
     * maintenance and stock-takes.
     *
     * @return array<string, mixed>
     */
    private function things(Carbon $today): array
    {
        $kept = fn (): Builder => AssetItem::query()->where('status', '!=', AssetStatus::Disposed->value);

        $byStatus = $kept()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        // ⚠ "Idle" is the register's own filter: nobody holds it, it is not on
        // its way. The same clause as AssetItemController's `idle`.
        $idle = $kept()->whereNull('custodian_employee_id')->whereNull('custodian_party_id')->whereNull('transit_movement_id');

        $plansDue = AssetMaintenancePlan::query()->due($today)->with('item:id,tag,name')->orderBy('next_due_on')->limit(50)->get();
        $ending = AssetMaintenanceController::endingSoon($today);

        return [
            'count' => $kept()->count(),
            'pieces' => (int) $kept()->sum('quantity'),
            'statuses' => collect([AssetStatus::InUse, AssetStatus::InStore, AssetStatus::UnderRepair, AssetStatus::WithOutsideParty, AssetStatus::Missing])
                ->map(fn (AssetStatus $status) => [
                    'value' => $status->value,
                    // "In store", never "Idle": idle is a custody fact, in store a status (Joy, 15 Oct 2026).
                    'label' => $status === AssetStatus::WithOutsideParty ? 'With outside party' : $status->label(),
                    'count' => (int) ($byStatus[$status->value] ?? 0),
                    'url' => '/assets/items?status='.$status->value,
                ])->values()->all(),
            'damaged_or_poor' => [
                'count' => $kept()->whereIn('condition', [AssetCondition::Damaged->value, AssetCondition::Poor->value])->count(),
                // ⚠ Both conditions, as counted (bug hunt 16 Oct 2026): `poor` alone
                // opened a list shorter than the number on the card.
                'url' => '/assets/items?condition='.AssetItemController::POOR_OR_WORSE,
            ],
            'idle' => ['count' => $idle->count(), 'url' => '/assets/items?idle=1'],
            'overdue_outside' => AssetItem::query()->overdueFromOutside()->count(),
            'on_the_way' => AssetMovement::query()->where('status', AssetMovementStatus::Dispatched->value)->count(),
            'where' => $this->where($kept),
            'outside' => $this->outside($today),
            'maintenance' => [
                'due' => $plansDue->count(),
                'open' => AssetMaintenanceRecord::query()->where('state', MaintenanceState::Open->value)->count(),
                'ending' => count($ending),
                'lines' => $this->maintenanceLines($plansDue, $ending, $today),
            ],
            'counts' => $this->counts(),
        ];
    }

    /**
     * Things by person, by place and by category. People: tagged things by
     * custodian plus counted groups by holding, the idle row last.
     *
     * @param  \Closure(): Builder<AssetItem>  $kept
     * @return array{people: list<array<string, mixed>>, places: list<array<string, mixed>>, categories: list<array<string, mixed>>}
     */
    private function where(\Closure $kept): array
    {
        $tagged = $kept()->whereNotNull('custodian_employee_id')
            ->selectRaw('custodian_employee_id as employee_id, count(*) as n')->groupBy('custodian_employee_id')->pluck('n', 'employee_id');
        $held = AssetHolding::query()->whereNotNull('employee_id')->where('quantity', '>', 0)
            ->whereHas('item', fn (Builder $item) => $item->where('status', '!=', AssetStatus::Disposed->value))
            ->selectRaw('employee_id, count(distinct asset_item_id) as n')->groupBy('employee_id')->pluck('n', 'employee_id');
        $perEmployee = collect($tagged)->map(fn ($n) => (int) $n);
        foreach ($held as $employeeId => $n) {
            $perEmployee[$employeeId] = ($perEmployee[$employeeId] ?? 0) + (int) $n;
        }
        $names = Employee::query()->whereIn('id', $perEmployee->keys())->get(['id', 'first_name', 'last_name'])->keyBy('id');

        $people = $perEmployee->map(fn (int $n, $id) => [
            'label' => $names->get($id)?->fullName() ?? 'Employee '.$id,
            'count' => $n,
            // ⚠ The register has no "held by" filter; the row opens the whole list.
            'url' => '/assets/items',
            'muted' => false,
        ])->sortByDesc('count')->take(6)->values();

        $idle = $kept()->whereNull('custodian_employee_id')->whereNull('custodian_party_id')->whereNull('transit_movement_id')->count();
        if ($idle > 0) {
            $people->push(['label' => 'Nobody, in store', 'count' => $idle, 'url' => '/assets/items?idle=1', 'muted' => true]);
        }

        $byOffice = $kept()->whereNotNull('office_id')->selectRaw('office_id, count(*) as n')->groupBy('office_id')->pluck('n', 'office_id');
        $byWarehouse = $kept()->whereNotNull('warehouse_id')->selectRaw('warehouse_id, count(*) as n')->groupBy('warehouse_id')->pluck('n', 'warehouse_id');
        $offices = Office::query()->whereIn('id', $byOffice->keys())->get(['id', 'name'])->keyBy('id');
        $warehouses = Warehouse::query()->whereIn('id', $byWarehouse->keys())->get(['id', 'name'])->keyBy('id');

        $places = collect($byOffice)->map(fn ($n, $id) => [
            'label' => $offices->get($id)?->name ?? 'Office '.$id, 'count' => (int) $n, 'url' => '/assets/items?'.http_build_query(['place' => "office:{$id}"]), 'muted' => false,
        ])->values()->concat(collect($byWarehouse)->map(fn ($n, $id) => [
            'label' => $warehouses->get($id)?->name ?? 'Warehouse '.$id, 'count' => (int) $n, 'url' => '/assets/items?'.http_build_query(['place' => "warehouse:{$id}"]), 'muted' => false,
        ])->values())->sortByDesc('count')->values();

        $byCategory = $kept()->selectRaw('asset_category_id, count(*) as n')->groupBy('asset_category_id')->pluck('n', 'asset_category_id');
        $categories = AssetCategory::query()->whereIn('id', $byCategory->keys())->orderBy('sort')->orderBy('name')->get(['id', 'name'])
            ->map(fn (AssetCategory $category) => [
                'label' => $category->name, 'count' => (int) $byCategory[$category->id], 'url' => '/assets/items?category='.$category->id, 'muted' => false,
            ])->sortByDesc('count')->values();

        return ['people' => $people->all(), 'places' => $places->all(), 'categories' => $categories->all()];
    }

    /**
     * Things with an outside party, the overdue ones first.
     *
     * @return array{total: int, rows: list<array<string, mixed>>}
     */
    private function outside(Carbon $today): array
    {
        $query = AssetItem::query()->whereNotNull('custodian_party_id')->where('status', '!=', AssetStatus::Disposed->value);
        $total = (clone $query)->count();

        $rows = (clone $query)->with('custodianParty:id,name,organisation')->orderBy('tag')->limit(50)->get()
            ->map(function (AssetItem $item) use ($today) {
                $sendOut = AssetMovement::openSendOutFor($item);
                $back = $sendOut?->expected_back_on;

                return [
                    'id' => $item->id,
                    'tag' => $item->tag,
                    'name' => $item->name,
                    'with' => $item->custodianParty?->label() ?? '-',
                    'expected_back_on' => $back?->toDateString(),
                    // Positive once the day has passed; null when no day was promised.
                    'days_late' => $back !== null && $back->lt($today) ? (int) $back->diffInDays($today) : null,
                    'url' => '/assets/items/'.$item->id,
                ];
            })
            ->sortBy([['days_late', 'desc'], ['expected_back_on', 'asc']])
            ->take(6)->values()->all();

        return ['total' => $total, 'rows' => $rows];
    }

    /**
     * The next few maintenance dates: plans due, warranties and contracts
     * ending, in date order. `days` is negative when the day has passed.
     *
     * @param  Collection<int, AssetMaintenancePlan>  $plansDue
     * @param  list<array<string, mixed>>  $ending
     * @return list<array{on: string, text: string, days: int, url: string}>
     */
    private function maintenanceLines(Collection $plansDue, array $ending, Carbon $today): array
    {
        $days = fn (string $on) => (int) $today->diffInDays(Carbon::parse($on), false);

        $plans = $plansDue->map(fn (AssetMaintenancePlan $plan) => [
            'on' => $plan->next_due_on->toDateString(),
            'text' => trim(($plan->item?->name ?? '').', '.$plan->title, ', '),
            'days' => $days($plan->next_due_on->toDateString()),
            'url' => '/assets/items/'.$plan->asset_item_id,
        ]);

        $ends = collect($ending)->map(fn (array $end) => [
            'on' => $end['ends_on'],
            'text' => $end['name'].', '.($end['what'] === 'Warranty' ? 'warranty ends' : 'service contract ends'),
            'days' => $days($end['ends_on']),
            'url' => '/assets/items/'.$end['item_id'],
        ]);

        return $plans->concat($ends)->sortBy('on')->take(5)->values()->all();
    }

    /**
     * The open stock-take, if any, the last one closed, and the gaps still to settle.
     *
     * @return array{open: array<string, mixed>|null, last_closed: array<string, mixed>|null, gaps: int}
     */
    private function counts(): array
    {
        $with = fn (Builder $query) => $query->with(['office:id,name', 'warehouse:id,name'])
            ->withCount(['lines', 'discrepancies', 'lines as unmarked_count' => fn ($q) => $q->where('expected', true)->whereNull('found')]);

        $open = $with(AssetCount::query()->where('status', 'open'))->orderByDesc('id')->first();
        $closed = $with(AssetCount::query()->where('status', 'closed'))->orderByDesc('closed_at')->orderByDesc('id')->first();

        return [
            'open' => $open ? [
                'id' => $open->id,
                'number' => $open->number,
                'place' => $open->placeLabel(),
                'lines' => $open->lines_count,
                'marked' => $open->lines_count - $open->unmarked_count,
                'started_on' => $open->created_at?->timezone(config('app.timezone'))->toDateString(),
                'url' => '/assets/counts/'.$open->id,
            ] : null,
            'last_closed' => $closed ? [
                'id' => $closed->id,
                'number' => $closed->number,
                'place' => $closed->placeLabel(),
                'lines' => $closed->lines_count,
                'closed_on' => $closed->closed_at?->timezone(config('app.timezone'))->toDateString(),
                'gaps' => $closed->discrepancies_count,
                'url' => '/assets/counts/'.$closed->id,
            ] : null,
            'gaps' => AssetDiscrepancy::query()->where('state', 'open')->count(),
        ];
    }

    /**
     * The accounts hub as numbers: on record, renewing, overdue, shared with
     * us by the group, and the split by kind.
     *
     * @return array<string, mixed>
     */
    private function accounts(Carbon $today): array
    {
        $businessId = (int) app(BusinessContext::class)->id();
        // ⚠ The hub's own base: visible to this business, not closed. Suspended
        // accounts count as on record, as they do on the hub.
        $open = fn (): Builder => CompanyAccount::query()->visibleTo($businessId)->open();
        $limit = $today->copy()->addDays(CompanyAccount::REMIND_DAYS_FIRST)->toDateString();

        $byKind = $open()->selectRaw('kind, count(*) as n')->groupBy('kind')->pluck('n', 'kind');

        return [
            'on_record' => $open()->count(),
            'renewing_soon' => $open()->whereNotNull('renews_on')->whereDate('renews_on', '>=', $today->toDateString())->whereDate('renews_on', '<=', $limit)->count(),
            'overdue' => $open()->whereNotNull('renews_on')->whereDate('renews_on', '<', $today->toDateString())->count(),
            // Recorded by another business of the group and shared with this one: the hub's `isHomeOf` test, as a count.
            'shared' => $open()->where('company_accounts.business_id', '!=', $businessId)->count(),
            'by_kind' => collect(CompanyAccountKind::cases())
                ->map(fn (CompanyAccountKind $kind) => ['value' => $kind->value, 'label' => $kind->label(), 'count' => (int) ($byKind[$kind->value] ?? 0)])
                ->filter(fn (array $row) => $row['count'] > 0)->values()->all(),
            'soon' => $open()->whereNotNull('renews_on')->whereDate('renews_on', '<=', $limit)->orderBy('renews_on')->limit(5)->get()
                ->map(fn (CompanyAccount $account) => [
                    'id' => $account->id,
                    'name' => $account->name,
                    'kind_label' => $account->kind->label(),
                    'renews_on' => $account->renews_on->toDateString(),
                    'tone' => $account->renewalTone($today),
                    'url' => '/assets/accounts/'.$account->id,
                ])->values()->all(),
        ];
    }

    /**
     * The books, summed the way the fixed assets register computes each row:
     * cost, what the charge rows (plus the opening figure) have written off,
     * and the difference. Nothing stored, nothing re-derived.
     *
     * @return array<string, mixed>
     */
    private function books(): array
    {
        // FEA-009: one total per row, loaded with the list, as the register does.
        $assets = FixedAsset::query()->withSum('depreciations', 'amount')->get();

        $cost = Money::sum($assets->map(fn (FixedAsset $asset) => Money::ofTaka((string) $asset->cost)));
        $writtenOff = Money::sum($assets->map(fn (FixedAsset $asset) => $asset->accumulated()));
        $bookValue = $cost->plus($writtenOff->negated());

        // The run would charge these: cost in the books and not yet written down (DepreciationController's count).
        $depreciable = $assets->filter(fn (FixedAsset $asset) => $asset->costIsOnTheBooks() && ! $asset->isFullyDepreciated());
        $monthly = Money::sum($depreciable->map(fn (FixedAsset $asset) => $asset->monthlyCharge()));

        $lastRun = FixedAssetDepreciation::query()->orderByDesc('year')->orderByDesc('month')->first(['year', 'month']);

        return [
            'cost' => $cost->format(),
            'written_off' => $writtenOff->format(),
            'book_value' => $bookValue->format(),
            'percent_left' => $cost->minor > 0 ? (int) round($bookValue->minor * 100 / $cost->minor) : 0,
            'monthly' => $monthly->format(),
            'to_write_down' => $depreciable->count(),
            'last_run' => $lastRun ? Carbon::create($lastRun->year, $lastRun->month, 1)->format('F Y') : null,
            'assets' => $assets->count(),
        ];
    }

    /**
     * The "Needs you" rows, in the order of how loud they are: red first, then
     * amber. A row belongs to the card whose permission the person holds.
     *
     * @param  array<string, mixed>|null  $things
     * @param  array<string, mixed>|null  $accounts
     * @return list<array{key: string, title: string, hint: string, count: int, tone: string, url: string}>
     */
    private function needs(?array $things, ?array $accounts): array
    {
        $rows = [];

        if ($things !== null) {
            $rows[] = ['key' => 'overdue_outside', 'title' => 'Overdue back from outside', 'hint' => 'Sent out, expected back date has passed', 'count' => $things['overdue_outside'], 'tone' => 'danger', 'url' => '/assets/items?overdue=1'];
        }
        if ($accounts !== null) {
            $rows[] = ['key' => 'renewal_overdue', 'title' => 'Account renewal overdue', 'hint' => 'Renewal date has passed', 'count' => $accounts['overdue'], 'tone' => 'danger', 'url' => '/assets/accounts?renewing=1'];
        }
        if ($things !== null) {
            $rows[] = ['key' => 'on_the_way', 'title' => 'Hand-overs on the way', 'hint' => 'Dispatched, not yet received at the other end', 'count' => $things['on_the_way'], 'tone' => 'warning', 'url' => '/assets/movements?open=1'];
            $rows[] = ['key' => 'maintenance_due', 'title' => 'Maintenance due', 'hint' => 'Routine care and check-ups at or past their day', 'count' => $things['maintenance']['due'], 'tone' => 'warning', 'url' => '/assets/maintenance'];
            $rows[] = ['key' => 'open_jobs', 'title' => 'Open repair jobs', 'hint' => 'Faults reported or work under way', 'count' => $things['maintenance']['open'], 'tone' => 'warning', 'url' => '/assets/maintenance'];
            $rows[] = ['key' => 'gaps', 'title' => 'Stock-take gaps to settle', 'hint' => 'Expected but not found, or found where not expected', 'count' => $things['counts']['gaps'], 'tone' => 'warning', 'url' => '/assets/counts'];
        }
        if ($accounts !== null) {
            $rows[] = ['key' => 'renewing_soon', 'title' => 'Accounts renewing in 30 days', 'hint' => 'Renew before the date, or mark renewed', 'count' => $accounts['renewing_soon'], 'tone' => 'warning', 'url' => '/assets/accounts?renewing=1'];
        }
        if ($things !== null) {
            $rows[] = ['key' => 'cover_ending', 'title' => 'Cover ending in 30 days', 'hint' => 'Warranty or service contract running out', 'count' => $things['maintenance']['ending'], 'tone' => 'info', 'url' => '/assets/maintenance'];
        }

        // ⚠ Only rows with something in them: an empty page is good news, not six zeros.
        return array_values(array_filter($rows, fn (array $row) => $row['count'] > 0));
    }
}
