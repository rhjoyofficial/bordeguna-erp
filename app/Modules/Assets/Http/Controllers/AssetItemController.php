<?php

namespace App\Modules\Assets\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Support\Money;
use App\Modules\Assets\Actions\SaveAssetItem;
use App\Modules\Assets\Enums\AssetCondition;
use App\Modules\Assets\Enums\AssetPhotoKind;
use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Assets\Enums\AssetTracking;
use App\Modules\Assets\Models\AssetCategory;
use App\Modules\Assets\Models\AssetCount;
use App\Modules\Assets\Models\AssetItem;
use App\Modules\Assets\Models\AssetMaintenancePlan;
use App\Modules\Assets\Models\AssetMaintenanceRecord;
use App\Modules\Assets\Models\AssetMovement;
use App\Modules\Assets\Models\AssetMovementLine;
use App\Modules\Assets\Models\AssetParty;
use App\Modules\Assets\Support\AssetQrCode;
use App\Modules\Foundation\Actions\DeleteAttachment;
use App\Modules\Foundation\Actions\StoreAttachment;
use App\Modules\Foundation\Models\Attachment;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Office;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

/**
 * The register of what the business keeps (asset management slice A1).
 *
 * ⚠⚠ Cost is shown only to someone who may see the financial register
 * (`assets.view`). The Asset Keeper keeps the things, not the prices (plan §2.14).
 */
class AssetItemController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'integer'],
            'place' => ['nullable', 'string', 'regex:/^(office|warehouse):\d+$/'],
            'status' => ['nullable', Rule::enum(AssetStatus::class)],
            'condition' => ['nullable', Rule::enum(AssetCondition::class)],
            'idle' => ['nullable', 'boolean'],
            // With an outside party past the send-out's "expected back" (13 Oct 2026).
            'overdue' => ['nullable', 'boolean'],
        ]);

        $items = AssetItem::query()
            ->with(['category:id,name', 'office:id,name', 'warehouse:id,name', 'parent:id,tag', 'custodianEmployee:id,first_name,last_name', 'custodianParty:id,name,organisation'])
            ->when($filters['q'] ?? null, function (Builder $query, string $q) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
                $query->where(fn (Builder $inner) => $inner
                    ->where('tag', 'like', $like)->orWhere('name', 'like', $like)
                    ->orWhere('brand', 'like', $like)->orWhere('model', 'like', $like)
                    ->orWhere('serial_number', 'like', $like)->orWhere('area', 'like', $like));
            })
            ->when($filters['category'] ?? null, fn (Builder $query, $id) => $query->where('asset_category_id', $id))
            ->when($filters['place'] ?? null, function (Builder $query, string $place) {
                [$type, $id] = explode(':', $place);
                $query->where($type === 'office' ? 'office_id' : 'warehouse_id', (int) $id);
            })
            ->when($filters['status'] ?? null, fn (Builder $query, $status) => $query->where('status', $status))
            // ⚠ Disposed things leave the working list unless asked for.
            ->when(! ($filters['status'] ?? null), fn (Builder $query) => $query->where('status', '!=', AssetStatus::Disposed->value))
            ->when($filters['condition'] ?? null, fn (Builder $query, $condition) => $query->where('condition', $condition))
            ->when($filters['idle'] ?? null, fn (Builder $query) => $query->whereNull('custodian_employee_id')->whereNull('custodian_party_id')->whereNull('transit_movement_id'))
            ->when($filters['overdue'] ?? null, fn (Builder $query) => $query->overdueFromOutside())
            ->orderBy('tag')
            ->paginate(30)
            ->withQueryString();

        // One query for the page's overdue chips, not one per row.
        $overdue = AssetItem::query()->overdueFromOutside()->whereIn('id', $items->getCollection()->pluck('id'))->pluck('id')->flip();

        $items->getCollection()->transform(fn (AssetItem $item) => [
            'id' => $item->id,
            'tag' => $item->tag,
            'name' => $item->name,
            'category' => $item->category?->name,
            'quantity' => $item->quantity,
            'is_group' => ! $item->isIndividual(),
            'brand_model' => trim(implode(' ', array_filter([$item->brand, $item->model]))) ?: null,
            'location' => $item->locationLabel(),
            'status' => $item->status->value,
            'status_label' => $item->status->label(),
            'condition' => $item->condition->value,
            'condition_label' => $item->condition->label(),
            'part_of' => $item->parent?->tag,
            'with' => $item->custodyLabel(),
            'overdue' => $overdue->has($item->id),
        ]);

        return Inertia::render('assets/items/index', [
            'items' => $items,
            'filters' => $filters,
            'categories' => AssetCategory::query()->orderBy('sort')->orderBy('name')->get(['id', 'name']),
            'places' => $this->places(),
            'statuses' => AssetStatus::options(AssetStatus::cases()),
            'conditions' => AssetCondition::options(),
            'can_manage' => $request->user()->can('assets.items.manage'),
            'counts' => [
                'kept' => AssetItem::query()->where('status', '!=', AssetStatus::Disposed->value)->count(),
                'damaged' => AssetItem::query()->where('status', '!=', AssetStatus::Disposed->value)->where('condition', AssetCondition::Damaged->value)->count(),
                'under_repair' => AssetItem::query()->where('status', AssetStatus::UnderRepair->value)->count(),
                'overdue_outside' => AssetItem::query()->overdueFromOutside()->count(),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('assets/items/form', $this->formOptions($request) + ['item' => null]);
    }

    public function store(Request $request, SaveAssetItem $save, StoreAttachment $store): RedirectResponse
    {
        $data = $this->validated($request, creating: true);
        $category = AssetCategory::query()->findOrFail($data['asset_category_id']);

        $items = $save->register($category, $data, $request->user(), (int) ($data['copies'] ?? 1));

        // ⚠ A photo taken at registration shows every copy: they are the same thing.
        foreach ($request->file('photos', []) as $file) {
            foreach ($items as $item) {
                $store->handle($item, $file, AssetPhotoKind::Identity->value, $request->user());
            }
        }

        if ($items->count() === 1) {
            return redirect()->route('assets.items.show', $items->first())->with('success', "Registered {$items->first()->tag}.");
        }

        return redirect()->route('assets.items.index', ['category' => $category->id])
            ->with('success', "Registered {$items->count()} items: {$items->first()->tag} to {$items->last()->tag}.");
    }

    public function show(Request $request, AssetItem $item): Response
    {
        $item->load(['category:id,name,code', 'office:id,name', 'warehouse:id,name', 'parent:id,tag,name', 'parts:id,parent_id,tag,name,status', 'supplier:id,name', 'createdBy:id,name', 'attachments', 'serviceParty:id,name,organisation',
            'custodianEmployee:id,first_name,last_name,employee_code', 'custodianParty:id,name,organisation', 'transitMovement:id,number', 'holdings.employee:id,first_name,last_name', 'holdings.party:id,name,organisation', 'holdings.office:id,name', 'holdings.warehouse:id,name']);
        $seesPrice = $request->user()->can('assets.view');

        $history = Activity::query()
            ->where('subject_type', $item->getMorphClass())
            ->where('subject_id', $item->id)
            ->with('causer')
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (Activity $entry) => [
                'id' => $entry->id,
                'when' => $entry->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i'),
                'who' => $entry->causer?->name,
                'what' => $entry->description,
                'changes' => collect($entry->properties['attributes'] ?? [])
                    ->map(fn ($new, $field) => [
                        'field' => self::FIELD_LABELS[$field] ?? $field,
                        'from' => $this->shown($field, ($entry->properties['old'] ?? [])[$field] ?? null),
                        'to' => $this->shown($field, $new),
                    ])
                    ->values(),
            ]);

        return Inertia::render('assets/items/show', [
            'item' => [
                'id' => $item->id,
                'tag' => $item->tag,
                'name' => $item->name,
                'category' => $item->category?->name,
                'tracking' => $item->tracking->value,
                'tracking_label' => $item->tracking->label(),
                'quantity' => $item->quantity,
                'brand' => $item->brand,
                'model' => $item->model,
                'serial_number' => $item->serial_number,
                'specification' => $item->specification,
                'location' => $item->locationLabel(),
                'status' => $item->status->value,
                'status_label' => $item->status->label(),
                'condition' => $item->condition->value,
                'condition_label' => $item->condition->label(),
                'parent' => $item->parent ? ['id' => $item->parent->id, 'tag' => $item->parent->tag, 'name' => $item->parent->name] : null,
                'parts' => $item->parts->map(fn (AssetItem $part) => ['id' => $part->id, 'tag' => $part->tag, 'name' => $part->name, 'status_label' => $part->status->label()])->values(),
                // ⚠ Dates as Dhaka dates, never a model's UTC timestamp.
                'acquired_on' => $item->acquired_on?->toDateString(),
                'warranty_ends_on' => $item->warranty_ends_on?->toDateString(),
                'warranty_expired' => $item->warranty_ends_on !== null && $item->warranty_ends_on->lt(now()->startOfDay()),
                // Slice A4.
                'service_ends_on' => $item->service_ends_on?->toDateString(),
                'service_expired' => $item->service_ends_on !== null && $item->service_ends_on->lt(now()->startOfDay()),
                'service_party' => $item->serviceParty?->label(),
                'supplier' => $item->supplier?->name,
                'purchase_price' => $seesPrice && $item->purchase_price !== null ? Money::ofTaka((string) $item->purchase_price)->toTaka() : null,
                'note' => $item->note,
                'registered_by' => $item->createdBy?->name,
                'registered_at' => $item->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i'),
                'tag_url' => $item->tagUrl(),
                'qr_svg' => AssetQrCode::svg($item->tagUrl()),
                // Custody (slice A2).
                'custody' => $item->custodyLabel(),
                'custody_since' => $item->custody_since?->toDateString(),
                'acknowledged_at' => $item->acknowledged_at?->timezone(config('app.timezone'))->format('Y-m-d H:i'),
                'in_transit' => $item->transitMovement ? ['id' => $item->transitMovement->id, 'number' => $item->transitMovement->number] : null,
                'holdings' => $item->isIndividual() ? [] : $item->holdings->map(fn ($holding) => ['holder' => $holding->holderLabel(), 'quantity' => $holding->quantity])->values(),
                'can_issue' => $nobodys = ! $item->isInTransit() && ! in_array($item->status, [AssetStatus::Disposed, AssetStatus::Missing], true) && $item->custodian_employee_id === null && $item->custodian_party_id === null,
                'can_return' => $item->custodian_employee_id !== null,
                'can_reassign' => $item->custodian_employee_id !== null,
                'can_receive_back' => $item->custodian_party_id !== null,
                // A send-out starts from its place or from the employee holding it;
                // a transfer only from its place with nobody (13 Oct 2026).
                'can_send_out' => ! $item->isInTransit() && ! in_array($item->status, [AssetStatus::Disposed, AssetStatus::Missing], true) && $item->custodian_party_id === null,
                'can_transfer' => $nobodys,
                // With an outside party: the send-out that put it there, and
                // whether it is past the day it was expected back (13 Oct 2026).
                'outside' => ($sendOut = AssetMovement::openSendOutFor($item)) !== null ? [
                    'party' => $item->custodianParty?->label(),
                    'since' => $sendOut->moved_on->toDateString(),
                    'expected_back_on' => $sendOut->expected_back_on?->toDateString(),
                    'overdue' => $sendOut->isOverdue(),
                    'movement' => ['id' => $sendOut->id, 'number' => $sendOut->number],
                ] : null,
                // Slice A3: a count of its place is open, and what it says so far.
                'open_count' => $this->openCountFor($item),
            ],
            'movements' => AssetMovementLine::query()->where('asset_item_id', $item->id)
                ->with(['movement.fromOffice', 'movement.fromWarehouse', 'movement.fromEmployee', 'movement.fromParty', 'movement.toOffice', 'movement.toWarehouse', 'movement.toEmployee', 'movement.toParty', 'movement.createdBy:id,name'])
                ->get()->sortByDesc('asset_movement_id')->values()
                ->map(fn (AssetMovementLine $line) => [
                    'id' => $line->movement->id,
                    'number' => $line->movement->number,
                    'kind_label' => $line->movement->kind->label(),
                    'status_label' => $line->movement->status->label(),
                    'from' => $line->movement->fromLabel(),
                    'to' => $line->movement->toLabel(),
                    'quantity' => $line->quantity,
                    'moved_on' => $line->movement->moved_on->toDateString(),
                    'by' => $line->movement->createdBy?->name,
                ]),
            'photos' => collect(AssetPhotoKind::cases())->map(fn (AssetPhotoKind $kind) => [
                'kind' => $kind->value,
                'label' => $kind->label(),
                'uploadable' => in_array($kind, AssetPhotoKind::onTheItem(), true),
                'files' => $item->attachments->where('collection', $kind->value)->sortByDesc('id')->map(fn (Attachment $file) => [
                    'id' => $file->id,
                    'url' => route('attachments.show', $file->id),
                    'name' => $file->original_name,
                ])->values(),
            ])->filter(fn (array $group) => $group['uploadable'] || $group['files']->isNotEmpty())->values(),
            'history' => $history,
            // Slice A4: its plans and its records.
            'maintenance' => $item->isIndividual() ? [
                'plans' => $item->maintenancePlans()->with(['assignee:id,name', 'party:id,name,organisation'])
                    ->orderByDesc('is_active')->orderBy('next_due_on')->get()
                    ->map(fn (AssetMaintenancePlan $plan) => AssetMaintenancePlanController::row($plan)),
                'records' => $item->maintenanceRecords()->with(['reportedBy:id,name', 'doneBy:id,name', 'party:id,name,organisation', 'voucher:id,voucher_number'])
                    ->orderByDesc('id')->limit(50)->get()
                    ->map(fn (AssetMaintenanceRecord $record) => AssetMaintenanceController::row($record, $seesPrice)),
            ] : null,
            'sees_money' => $seesPrice,
            'can_manage' => $request->user()->can('assets.items.manage'),
            // Slice 3 (13 Oct 2026): its logins, masked; null = no card for this reader.
            'credentials' => AssetCredentialController::cardFor($request, $item),
        ]);
    }

    public function edit(Request $request, AssetItem $item): Response
    {
        $seesPrice = $request->user()->can('assets.view');

        return Inertia::render('assets/items/form', $this->formOptions($request, $item) + [
            'item' => [
                'id' => $item->id,
                'tag' => $item->tag,
                'asset_category_id' => $item->asset_category_id,
                'tracking' => $item->tracking->value,
                'name' => $item->name,
                'quantity' => $item->quantity,
                'brand' => $item->brand,
                'model' => $item->model,
                'serial_number' => $item->serial_number,
                'specification' => $item->specification,
                'place' => $item->office_id ? "office:{$item->office_id}" : "warehouse:{$item->warehouse_id}",
                'area' => $item->area,
                'status' => $item->status->value,
                'status_is_settable' => in_array($item->status, AssetStatus::settableByHand(), true),
                'status_label' => $item->status->label(),
                'condition' => $item->condition->value,
                'parent_id' => $item->parent_id,
                'acquired_on' => $item->acquired_on?->toDateString(),
                'supplier_id' => $item->supplier_id,
                'purchase_price' => $seesPrice && $item->purchase_price !== null ? Money::ofTaka((string) $item->purchase_price)->toTaka() : null,
                'warranty_ends_on' => $item->warranty_ends_on?->toDateString(),
                'service_ends_on' => $item->service_ends_on?->toDateString(),
                'service_party_id' => $item->service_party_id,
                'note' => $item->note,
            ],
        ]);
    }

    public function update(Request $request, AssetItem $item, SaveAssetItem $save): RedirectResponse
    {
        $save->update($item, $this->validated($request, creating: false), $request->user());

        return redirect()->route('assets.items.show', $item)->with('success', "{$item->tag} saved.");
    }

    /**
     * A scanned QR code lands here. It only ever OPENS the item (plan §2.8).
     */
    public function byTag(Request $request, string $tag): RedirectResponse
    {
        $item = AssetItem::query()->where('tag', $tag)->first();

        if ($item !== null) {
            return redirect()->route('assets.items.show', $item);
        }

        // ⚠ A tag of another of the group's businesses the reader also works in:
        // say which, rather than a bare "not found".
        $elsewhere = AssetItem::query()->withoutGlobalScopes()->where('tag', $tag)
            ->whereIn('business_id', $request->user()->businesses()->select('businesses.id'))
            ->value('business_id');

        abort_if($elsewhere === null, 404);

        $name = Business::query()->whereKey($elsewhere)->value('name');

        return redirect()->route('dashboard')->with('error', "{$tag} belongs to {$name}. Switch to {$name} and scan it again.");
    }

    public function storePhotos(Request $request, AssetItem $item, StoreAttachment $store): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_map(fn (AssetPhotoKind $kind) => $kind->value, AssetPhotoKind::onTheItem()))],
            'photos' => ['required', 'array', 'min:1', 'max:6'],
            'photos.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ]);

        foreach ($request->file('photos', []) as $file) {
            $store->handle($item, $file, $data['kind'], $request->user());
        }

        activity('assets.items')->performedOn($item)->causedBy($request->user())
            ->log(count($data['photos']).' photo(s) added: '.AssetPhotoKind::from($data['kind'])->label());

        return back()->with('success', 'Photo added.');
    }

    public function destroyPhoto(Request $request, AssetItem $item, Attachment $attachment, DeleteAttachment $delete): RedirectResponse
    {
        // ⚠ Only a photo of THIS item, and only one taken on the item itself:
        // hand-over, repair and disposal photos belong to their documents.
        abort_unless(
            $attachment->attachable_type === $item->getMorphClass()
            && (int) $attachment->attachable_id === $item->id
            && in_array($attachment->collection, array_map(fn (AssetPhotoKind $kind) => $kind->value, AssetPhotoKind::onTheItem()), true),
            404,
        );

        $delete->handle($attachment);

        activity('assets.items')->performedOn($item)->causedBy($request->user())->log('A photo was removed');

        return back()->with('success', 'Photo removed.');
    }

    /**
     * The open stock-take of this item's place, if any, and its line.
     *
     * @return array{id: int, number: string, on_list: bool, found: bool|null}|null
     */
    private function openCountFor(AssetItem $item): ?array
    {
        $count = AssetCount::query()->where('status', 'open')
            ->where(fn (Builder $query) => $query
                ->when($item->office_id, fn (Builder $q) => $q->where('office_id', $item->office_id))
                ->when($item->warehouse_id, fn (Builder $q) => $q->orWhere('warehouse_id', $item->warehouse_id)))
            ->first();

        if ($count === null) {
            return null;
        }

        $line = $count->lines()->where('asset_item_id', $item->id)->first();

        return ['id' => $count->id, 'number' => $count->number, 'on_list' => $line !== null, 'found' => $line?->found];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        $data = $request->validate([
            'asset_category_id' => [$creating ? 'required' : 'prohibited', 'integer'],
            'tracking' => [$creating ? 'required' : 'prohibited', Rule::enum(AssetTracking::class)],
            'copies' => [$creating ? 'nullable' : 'prohibited', 'integer', 'min:1', 'max:'.SaveAssetItem::MAX_COPIES],
            'name' => ['required', 'string', 'max:160'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'brand' => ['nullable', 'string', 'max:80'],
            'model' => ['nullable', 'string', 'max:120'],
            'serial_number' => ['nullable', 'string', 'max:120'],
            'specification' => ['nullable', 'string', 'max:5000'],
            'place' => ['required', 'string', 'regex:/^(office|warehouse):\d+$/'],
            'area' => ['nullable', 'string', 'max:120'],
            'status' => ['required', Rule::enum(AssetStatus::class)],
            'condition' => ['required', Rule::enum(AssetCondition::class)],
            'parent_id' => ['nullable', 'integer'],
            'acquired_on' => ['nullable', 'date'],
            'supplier_id' => ['nullable', 'integer'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'warranty_ends_on' => ['nullable', 'date'],
            'service_ends_on' => ['nullable', 'date'],
            'service_party_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:5000'],
            'photos' => ['nullable', 'array', 'max:6'],
            'photos.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ]);

        [$type, $id] = explode(':', $data['place']);
        $data['office_id'] = $type === 'office' ? (int) $id : null;
        $data['warehouse_id'] = $type === 'warehouse' ? (int) $id : null;
        unset($data['place'], $data['photos']);

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(Request $request, ?AssetItem $editing = null): array
    {
        return [
            'categories' => AssetCategory::query()->where('is_active', true)->orderBy('sort')->orderBy('name')
                ->get(['id', 'name', 'code', 'default_tracking'])
                ->map(fn (AssetCategory $category) => ['id' => $category->id, 'name' => $category->name, 'code' => $category->code, 'default_tracking' => $category->default_tracking->value]),
            'places' => $this->places(),
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name']),
            // Active parties, and the one it already names even if switched off.
            'parties' => AssetParty::query()
                ->where(fn (Builder $query) => $query->where('is_active', true)->when($editing?->service_party_id, fn (Builder $q, $id) => $q->orWhere('id', $id)))
                ->orderBy('name')->get()
                ->map(fn (AssetParty $party) => ['id' => $party->id, 'label' => $party->label()]),
            // Items something can be part of: tagged, still kept, not itself.
            'parents' => AssetItem::query()
                ->where('tracking', AssetTracking::Individual->value)
                ->where('status', '!=', AssetStatus::Disposed->value)
                ->when($editing, fn (Builder $query) => $query->whereKeyNot($editing->id))
                ->orderBy('tag')->get(['id', 'tag', 'name'])
                ->map(fn (AssetItem $item) => ['id' => $item->id, 'label' => "{$item->tag} {$item->name}"]),
            'statuses' => AssetStatus::options(AssetStatus::settableByHand()),
            'conditions' => AssetCondition::options(),
            'trackings' => AssetTracking::options(),
            'max_copies' => SaveAssetItem::MAX_COPIES,
            'sees_price' => $request->user()->can('assets.view'),
        ];
    }

    /**
     * Every active office and warehouse, as one list of places.
     *
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

    /**
     * A logged value as a person reads it: a place by its name, a part-of by its
     * tag, a status by its label.
     */
    private function shown(string $field, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($field) {
            'office_id' => Office::query()->whereKey($value)->value('name') ?? "office #{$value}",
            'warehouse_id' => Warehouse::query()->whereKey($value)->value('name') ?? "warehouse #{$value}",
            'parent_id' => AssetItem::query()->whereKey($value)->value('tag') ?? "item #{$value}",
            'custodian_employee_id' => Employee::query()->withoutGlobalScopes()->whereKey($value)->first()?->fullName() ?? "employee #{$value}",
            'custodian_party_id' => AssetParty::query()->withoutGlobalScopes()->whereKey($value)->first()?->label() ?? "party #{$value}",
            'status' => AssetStatus::tryFrom((string) $value)?->label() ?? (string) $value,
            'condition' => AssetCondition::tryFrom((string) $value)?->label() ?? (string) $value,
            // ⚠ The log keeps a date as a UTC timestamp ("2026-10-06T18:00:00Z"
            // for the 7th in Dhaka): cutting it at ten characters showed the day
            // before (found on the A4 walk, 17 Sep 2026).
            'warranty_ends_on', 'service_ends_on' => Carbon::parse((string) $value)->timezone(config('app.timezone'))->toDateString(),
            'service_party_id' => AssetParty::query()->withoutGlobalScopes()->whereKey($value)->first()?->label() ?? "party #{$value}",
            default => is_scalar($value) ? (string) $value : json_encode($value),
        };
    }

    private const FIELD_LABELS = [
        'name' => 'Name',
        'quantity' => 'Quantity',
        'brand' => 'Brand',
        'model' => 'Model',
        'serial_number' => 'Serial number',
        'office_id' => 'Office',
        'warehouse_id' => 'Warehouse',
        'area' => 'Room or spot',
        'status' => 'Status',
        'condition' => 'Condition',
        'parent_id' => 'Part of',
        'warranty_ends_on' => 'Warranty ends',
        'service_ends_on' => 'Service contract ends',
        'service_party_id' => 'Serviced by',
        'custodian_employee_id' => 'Held by',
        'custodian_party_id' => 'With outside party',
    ];
}
