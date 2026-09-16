<?php

namespace App\Modules\Assets\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assets\Actions\CountAssets;
use App\Modules\Assets\Enums\AssetCondition;
use App\Modules\Assets\Enums\DiscrepancyResolution;
use App\Modules\Assets\Models\AssetCount;
use App\Modules\Assets\Models\AssetCountLine;
use App\Modules\Assets\Models\AssetDiscrepancy;
use App\Modules\Assets\Models\AssetItem;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Stock-takes and the gaps they find (asset management slice A3).
 */
class AssetCountController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('assets/counts/index', [
            'counts' => AssetCount::query()->with(['office:id,name', 'warehouse:id,name', 'startedBy:id,name', 'closedBy:id,name'])
                ->withCount(['lines', 'discrepancies', 'lines as unmarked_count' => fn ($q) => $q->where('expected', true)->whereNull('found')])
                ->orderByDesc('id')->limit(100)->get()
                ->map(fn (AssetCount $count) => [
                    'id' => $count->id,
                    'number' => $count->number,
                    'place' => $count->placeLabel(),
                    'status' => $count->status,
                    'lines_count' => $count->lines_count,
                    'unmarked_count' => $count->unmarked_count,
                    'discrepancies_count' => $count->discrepancies_count,
                    'started_at' => $count->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i'),
                    'started_by' => $count->startedBy?->name,
                    'closed_at' => $count->closed_at?->timezone(config('app.timezone'))->format('Y-m-d H:i'),
                ]),
            'gaps' => $this->gaps(AssetDiscrepancy::query()->where('state', 'open'), $user),
            'places' => $this->places(),
            'can_manage' => $user->can('assets.items.manage'),
        ]);
    }

    public function store(Request $request, CountAssets $counts): RedirectResponse
    {
        $data = $request->validate([
            'place' => ['required', 'string', 'regex:/^(office|warehouse):\d+$/'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        [$type, $id] = explode(':', $data['place']);
        $business = Business::query()->findOrFail(app(BusinessContext::class)->id());

        $count = $counts->start($business, [$type === 'office' ? 'office_id' : 'warehouse_id' => (int) $id], $request->user(), $data['note'] ?? null);

        return redirect()->route('assets.counts.show', $count)->with('success', "{$count->number} started: {$count->lines->count()} to check.");
    }

    public function show(Request $request, AssetCount $count): Response
    {
        $count->load(['office:id,name', 'warehouse:id,name', 'startedBy:id,name', 'closedBy:id,name',
            'lines.item:id,tag,name,tracking,condition,custodian_employee_id', 'lines.item.custodianEmployee:id,first_name,last_name', 'lines.markedBy:id,name']);

        return Inertia::render('assets/counts/show', [
            'count' => [
                'id' => $count->id,
                'number' => $count->number,
                'place' => $count->placeLabel(),
                'status' => $count->status,
                'note' => $count->note,
                'started_by' => $count->startedBy?->name,
                'started_at' => $count->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i'),
                'closed_by' => $count->closedBy?->name,
                'closed_at' => $count->closed_at?->timezone(config('app.timezone'))->format('Y-m-d H:i'),
            ],
            'lines' => $count->lines->sortBy(fn (AssetCountLine $line) => [$line->expected ? 0 : 1, $line->item?->tag])->values()
                ->map(fn (AssetCountLine $line) => [
                    'item_id' => $line->asset_item_id,
                    'tag' => $line->item?->tag,
                    'name' => $line->item?->name,
                    'is_group' => $line->item?->tracking->value === 'quantity',
                    'with' => $line->item?->custodianEmployee?->fullName(),
                    'current_condition' => $line->item?->condition->value,
                    'expected' => $line->expected,
                    'expected_quantity' => $line->expected_quantity,
                    'found' => $line->found,
                    'found_quantity' => $line->found_quantity,
                    'condition' => $line->condition?->label(),
                    'marked_by' => $line->markedBy?->name,
                ]),
            'gaps' => $this->gaps(AssetDiscrepancy::query()->where('asset_count_id', $count->id), $request->user()),
            'conditions' => AssetCondition::options(),
            'can_manage' => $request->user()->can('assets.items.manage'),
        ]);
    }

    public function mark(Request $request, AssetCount $count, CountAssets $counts): RedirectResponse
    {
        $data = $request->validate([
            'item_id' => ['required', 'integer'],
            'found' => ['required', 'boolean'],
            'pieces' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'condition' => ['nullable', Rule::enum(AssetCondition::class)],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $item = AssetItem::query()->findOrFail($data['item_id']);
        $counts->mark($count, $item, (bool) $data['found'], $request->user(), $data['pieces'] ?? null, $data['condition'] ?? null, $data['note'] ?? null);

        return back()->with('success', "{$item->tag} marked.");
    }

    public function addFound(Request $request, AssetCount $count, CountAssets $counts): RedirectResponse
    {
        $data = $request->validate([
            'tag' => ['required', 'string', 'max:40'],
            'pieces' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'condition' => ['nullable', Rule::enum(AssetCondition::class)],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        // ⚠ By tag, within this business only.
        $item = AssetItem::query()->where('tag', strtoupper(trim($data['tag'])))->first();

        if ($item === null) {
            return back()->withErrors(['tag' => "No thing in this register has the tag {$data['tag']}."]);
        }

        $counts->addFound($count, $item, $request->user(), $data['pieces'] ?? null, $data['condition'] ?? null, $data['note'] ?? null);

        return back()->with('success', "{$item->tag} added as found here.");
    }

    public function close(Request $request, AssetCount $count, CountAssets $counts): RedirectResponse
    {
        $closed = $counts->close($count, $request->user());

        return back()->with('success', "{$closed->number} closed: {$closed->discrepancies->count()} gap(s) to settle.");
    }

    public function resolve(Request $request, AssetDiscrepancy $discrepancy, CountAssets $counts): RedirectResponse
    {
        $data = $request->validate([
            'resolution' => ['required', Rule::enum(DiscrepancyResolution::class)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $counts->resolve($discrepancy, DiscrepancyResolution::from($data['resolution']), $request->user(), $data['note'] ?? null);

        return back()->with('success', 'Settled.');
    }

    /**
     * @param  Builder<AssetDiscrepancy>  $query
     * @return list<array<string, mixed>>
     */
    private function gaps($query, $user): array
    {
        $canWriteOff = $user->can('assets.items.write_off');

        return $query->with(['item:id,tag,name', 'stockTake:id,number', 'responsible:id,name', 'resolvedBy:id,name'])
            ->orderByRaw("state = 'open' desc")->orderByDesc('id')->limit(200)->get()
            ->map(fn (AssetDiscrepancy $gap) => [
                'id' => $gap->id,
                'count_id' => $gap->asset_count_id,
                'count_number' => $gap->stockTake?->number,
                'item_id' => $gap->asset_item_id,
                'tag' => $gap->item?->tag,
                'name' => $gap->item?->name,
                'kind' => $gap->kind->value,
                'kind_label' => $gap->kind->label(),
                'quantity' => $gap->quantity,
                'open' => $gap->isOpen(),
                'responsible' => $gap->responsible?->name,
                'resolution' => $gap->resolution?->label(),
                'resolved_by' => $gap->resolvedBy?->name,
                'note' => $gap->note,
                // What this reader may choose, with what each means.
                'choices' => $gap->isOpen() ? collect($gap->kind->resolutions())
                    ->filter(fn (DiscrepancyResolution $r) => $r !== DiscrepancyResolution::ConfirmedLost || $canWriteOff)
                    ->map(fn (DiscrepancyResolution $r) => ['value' => $r->value, 'label' => $r->label(), 'meaning' => $r->meaningFor($gap->kind)])
                    ->values()->all() : [],
            ])->values()->all();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function places(): array
    {
        $offices = Office::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
            ->map(fn (Office $office) => ['value' => "office:{$office->id}", 'label' => "{$office->name} (office)"]);
        $warehouses = Warehouse::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
            ->map(fn (Warehouse $warehouse) => ['value' => "warehouse:{$warehouse->id}", 'label' => "{$warehouse->name} (warehouse)"]);

        return $offices->concat($warehouses)->values()->all();
    }
}
