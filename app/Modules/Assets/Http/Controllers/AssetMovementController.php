<?php

namespace App\Modules\Assets\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assets\Actions\MoveAssets;
use App\Modules\Assets\Corrections\AssetMovementActionPolicy;
use App\Modules\Assets\Enums\AssetCondition;
use App\Modules\Assets\Enums\AssetMovementKind;
use App\Modules\Assets\Enums\AssetMovementStatus;
use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Assets\Models\AssetHolding;
use App\Modules\Assets\Models\AssetItem;
use App\Modules\Assets\Models\AssetMovement;
use App\Modules\Assets\Models\AssetParty;
use App\Modules\Foundation\Actions\StoreAttachment;
use App\Modules\Foundation\Models\Attachment;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Hand-overs (asset management slice A2): recording them, receiving a transfer,
 * and reading them back.
 *
 * ⚠ A movement is never edited. The screen offers only what the rules allow
 * (a thing with nobody for an issue, a thing with an employee for a return);
 * MoveAssets refuses the rest again.
 */
class AssetMovementController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'kind' => ['nullable', Rule::enum(AssetMovementKind::class)],
            'open' => ['nullable', 'boolean'],
        ]);

        $movements = AssetMovement::query()
            ->with(['fromOffice:id,name', 'fromWarehouse:id,name', 'fromEmployee:id,first_name,last_name', 'fromParty:id,name,organisation',
                'toOffice:id,name', 'toWarehouse:id,name', 'toEmployee:id,first_name,last_name', 'toParty:id,name,organisation', 'createdBy:id,name'])
            ->withCount('lines')
            ->when($filters['kind'] ?? null, fn (Builder $query, $kind) => $query->where('kind', $kind))
            ->when($filters['open'] ?? false, fn (Builder $query) => $query->where('status', AssetMovementStatus::Dispatched->value))
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        $movements->getCollection()->transform(fn (AssetMovement $movement) => $this->row($movement));

        return Inertia::render('assets/movements/index', [
            'movements' => $movements,
            'filters' => $filters,
            'kinds' => AssetMovementKind::options(),
            'can_manage' => $request->user()->can('assets.items.manage'),
            'in_transit' => AssetMovement::query()->where('status', AssetMovementStatus::Dispatched->value)->count(),
        ]);
    }

    public function create(Request $request): Response
    {
        $kind = AssetMovementKind::tryFrom((string) $request->query('kind')) ?? AssetMovementKind::Issue;
        $preselected = array_map('intval', (array) $request->query('items', []));

        return Inertia::render('assets/movements/create', [
            'kind' => $kind->value,
            'kinds' => AssetMovementKind::options(),
            'preselected' => $preselected,
            'items' => $this->movable($kind),
            // ⚠ The people MoveAssets will accept, and nobody else (13 Oct 2026).
            'employees' => MoveAssets::receivers(app(BusinessContext::class)->id())
                ->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'employee_code'])
                ->map(fn (Employee $employee) => ['id' => $employee->id, 'label' => $employee->fullName().' ('.$employee->employee_code.')']),
            'parties' => AssetParty::query()->where('is_active', true)->orderBy('name')->get()
                ->map(fn (AssetParty $party) => ['id' => $party->id, 'label' => $party->label(), 'kind' => $party->kind->label()]),
            'places' => $this->places(),
            'conditions' => AssetCondition::options(),
            'today' => now()->toDateString(),
        ]);
    }

    public function store(Request $request, MoveAssets $move, StoreAttachment $store): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::enum(AssetMovementKind::class)],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.item_id' => ['required', 'integer'],
            'lines.*.quantity' => ['nullable', 'integer', 'min:1'],
            'lines.*.condition' => ['nullable', Rule::enum(AssetCondition::class)],
            'employee_id' => ['nullable', 'integer'],
            'party_id' => ['nullable', 'integer'],
            'from_employee_id' => ['nullable', 'integer'],
            'from_party_id' => ['nullable', 'integer'],
            'place' => ['nullable', 'string', 'regex:/^(office|warehouse):\d+$/'],
            // For a counted group whose pieces sit at a second place.
            'from_place' => ['nullable', 'string', 'regex:/^(office|warehouse):\d+$/'],
            'moved_on' => ['required', 'date'],
            // A send-out may say when the thing is expected back (13 Oct 2026).
            'expected_back_on' => ['nullable', 'date', 'after_or_equal:moved_on'],
            'note' => ['nullable', 'string', 'max:2000'],
            'photos' => ['nullable', 'array', 'max:6'],
            'photos.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ], ['expected_back_on.after_or_equal' => 'Expected back cannot be before the day it was sent out.']);

        // ⚠ Only things of THIS business: an id from elsewhere is simply absent.
        $items = AssetItem::query()->whereIn('id', collect($data['lines'])->pluck('item_id'))->get()->keyBy('id');

        $lines = [];

        foreach ($data['lines'] as $line) {
            $item = $items->get((int) $line['item_id']);

            if ($item === null) {
                return back()->withErrors(['lines' => 'One of the things chosen is not in this register.']);
            }

            $lines[] = ['item' => $item, 'quantity' => (int) ($line['quantity'] ?? 1), 'condition' => $line['condition'] ?? null];
        }

        $to = ['employee_id' => $data['employee_id'] ?? null, 'party_id' => $data['party_id'] ?? null,
            'from_employee_id' => $data['from_employee_id'] ?? null, 'from_party_id' => $data['from_party_id'] ?? null];

        if (! empty($data['place'])) {
            [$type, $id] = explode(':', $data['place']);
            $to[$type === 'office' ? 'office_id' : 'warehouse_id'] = (int) $id;
        }

        if (! empty($data['from_place'])) {
            [$type, $id] = explode(':', $data['from_place']);
            $to[$type === 'office' ? 'from_office_id' : 'from_warehouse_id'] = (int) $id;
        }

        $movement = $move->handle(AssetMovementKind::from($data['kind']), $lines, $to, $request->user(), $data['moved_on'], $data['note'] ?? null, $data['expected_back_on'] ?? null);

        foreach ($request->file('photos', []) as $file) {
            $store->handle($movement, $file, AssetMovement::PHOTOS, $request->user());
        }

        return redirect()->route('assets.movements.show', $movement)->with('success', "{$movement->number} recorded.");
    }

    public function show(Request $request, AssetMovement $movement): Response
    {
        $movement->load(['lines.item:id,tag,name,tracking', 'fromOffice', 'fromWarehouse', 'fromEmployee', 'fromParty', 'toOffice', 'toWarehouse', 'toEmployee', 'toParty', 'createdBy:id,name', 'receivedBy:id,name', 'attachments']);

        return Inertia::render('assets/movements/show', [
            'movement' => $this->row($movement) + [
                'note' => $movement->note,
                'received_by' => $movement->receivedBy?->name,
                'received_at' => $movement->received_at?->timezone(config('app.timezone'))->format('Y-m-d H:i'),
                'lines' => $movement->lines->map(fn ($line) => [
                    'item_id' => $line->asset_item_id,
                    'tag' => $line->item?->tag,
                    'name' => $line->item?->name,
                    'quantity' => $line->quantity,
                    'condition' => $line->condition?->label(),
                ])->values(),
                'photos' => $movement->attachments->where('collection', AssetMovement::PHOTOS)->map(fn (Attachment $file) => [
                    'id' => $file->id, 'url' => route('attachments.show', $file->id), 'name' => $file->original_name,
                ])->values(),
            ],
            'can_receive' => $movement->isDispatched() && $request->user()->can('assets.items.manage'),
            // ⭐ Corrections wave 3, B11 (24 Oct 2026): Reverse (done) or Cancel
            // (on its way), from the hand-over's policy, and the reversal chain.
            'actions' => app(AssetMovementActionPolicy::class)->actionsFor($movement, $request->user())->toArray()['actions'],
            'reasons' => AssetItemController::reasonLists(['reversal', 'cancellation']),
            'reversal' => [
                'reverses' => $movement->reverses ? ['id' => $movement->reverses->id, 'number' => $movement->reverses->number] : null,
                'reversed_by' => $movement->reversal ? ['id' => $movement->reversal->id, 'number' => $movement->reversal->number] : null,
                'reversed_at' => $movement->reversed_at?->timezone(config('app.timezone'))->format('Y-m-d H:i'),
                'reason' => $movement->reversal_reason,
                'who' => $movement->reversedBy?->name,
            ],
        ]);
    }

    public function receive(Request $request, AssetMovement $movement, MoveAssets $move): RedirectResponse
    {
        $move->receive($movement, $request->user());

        return back()->with('success', "{$movement->number} received at {$movement->toLabel()}.");
    }

    /**
     * @return array<string, mixed>
     */
    private function row(AssetMovement $movement): array
    {
        return [
            'id' => $movement->id,
            'number' => $movement->number,
            'kind' => $movement->kind->value,
            'kind_label' => $movement->kind->label(),
            'status' => $movement->status->value,
            'status_label' => $movement->status->label(),
            'from' => $movement->fromLabel(),
            'to' => $movement->toLabel(),
            'moved_on' => $movement->moved_on->toDateString(),
            'expected_back_on' => $movement->expected_back_on?->toDateString(),
            'overdue' => $movement->isOverdue(),
            'lines_count' => $movement->lines_count ?? $movement->lines->count(),
            'created_by' => $movement->createdBy?->name,
            'reversed' => $movement->isReversed(),
            'is_reversal' => $movement->isReversal(),
        ];
    }

    /**
     * The things a movement of this kind may take, each with where it is now.
     *
     * @return list<array<string, mixed>>
     */
    private function movable(AssetMovementKind $kind): array
    {
        $query = AssetItem::query()->with(['custodianEmployee:id,first_name,last_name', 'custodianParty:id,name,organisation', 'office:id,name', 'warehouse:id,name', 'holdings'])
            ->whereNull('transit_movement_id')
            ->whereNotIn('status', [AssetStatus::Disposed->value, AssetStatus::Missing->value])
            ->orderBy('tag');

        return $query->get()->filter(fn (AssetItem $item) => match ($kind) {
            AssetMovementKind::Issue, AssetMovementKind::Transfer => ! $item->isIndividual() || ($item->custodian_employee_id === null && $item->custodian_party_id === null),
            // A send-out starts from a place or from the employee holding it (13 Oct 2026).
            AssetMovementKind::SendOut => ! $item->isIndividual() || $item->custodian_party_id === null,
            AssetMovementKind::Return, AssetMovementKind::Reassign => ! $item->isIndividual() ? $item->holdings->contains(fn (AssetHolding $h) => $h->employee_id !== null) : $item->custodian_employee_id !== null,
            AssetMovementKind::ReceiveBack => ! $item->isIndividual() ? $item->holdings->contains(fn (AssetHolding $h) => $h->party_id !== null) : $item->custodian_party_id !== null,
        })->map(fn (AssetItem $item) => [
            'id' => $item->id,
            'tag' => $item->tag,
            'name' => $item->name,
            'is_group' => ! $item->isIndividual(),
            'parent_id' => $item->parent_id,
            // So the form can list what one employee holds (reassign, send out).
            'custodian_employee_id' => $item->custodian_employee_id,
            'where' => $item->locationLabel(),
            'with' => $item->custodyLabel(),
            'condition' => $item->condition->value,
            // For a counted group: how many each holder has, so the form caps the quantity.
            'held' => $item->isIndividual() ? null : $item->holdings->map(fn (AssetHolding $h) => [
                'key' => $h->holder_key, 'employee_id' => $h->employee_id, 'party_id' => $h->party_id, 'quantity' => $h->quantity,
            ])->values(),
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
