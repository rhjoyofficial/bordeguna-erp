<?php

namespace App\Modules\Assets\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Assets\Actions\RecordMaintenance;
use App\Modules\Assets\Enums\AssetCondition;
use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Assets\Enums\AssetTracking;
use App\Modules\Assets\Enums\MaintenanceKind;
use App\Modules\Assets\Models\AssetItem;
use App\Modules\Assets\Models\AssetMaintenancePlan;
use App\Modules\Assets\Models\AssetMaintenanceRecord;
use App\Modules\Assets\Models\AssetParty;
use App\Modules\Foundation\Actions\StoreAttachment;
use App\Modules\Foundation\Models\Attachment;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Purchasing\Models\ExpenseVoucher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Maintenance (asset management slice A4): what is due, what is open, what was
 * done, and what is about to run out of warranty.
 *
 * ⚠⚠ Cost and voucher are shown only to someone who sees the financial
 * register (`assets.view`). The Asset Keeper keeps the things, not the money.
 */
class AssetMaintenanceController extends Controller
{
    public const ENDING_WITHIN_DAYS = 30;

    public function index(Request $request): Response
    {
        $today = Carbon::today();
        $seesMoney = $request->user()->can('assets.view');

        return Inertia::render('assets/maintenance/index', [
            'due' => AssetMaintenancePlan::query()->due()
                ->with(['item:id,tag,name', 'assignee:id,name', 'party:id,name,organisation'])
                ->orderBy('next_due_on')->limit(200)->get()
                ->map(fn (AssetMaintenancePlan $plan) => AssetMaintenancePlanController::row($plan) + [
                    'tag' => $plan->item?->tag,
                    'name' => $plan->item?->name,
                ]),
            'open' => AssetMaintenanceRecord::query()->where('state', 'open')
                ->with(['item:id,tag,name', 'reportedBy:id,name'])
                ->orderBy('reported_on')->orderBy('id')->limit(200)->get()
                ->map(fn (AssetMaintenanceRecord $record) => self::row($record, $seesMoney)),
            'recent' => AssetMaintenanceRecord::query()->where('state', '!=', 'open')
                ->with(['item:id,tag,name', 'reportedBy:id,name', 'doneBy:id,name', 'party:id,name,organisation', 'voucher:id,voucher_number'])
                ->orderByDesc('closed_at')->orderByDesc('id')->limit(50)->get()
                ->map(fn (AssetMaintenanceRecord $record) => self::row($record, $seesMoney)),
            'ending' => self::endingSoon($today),
            'ending_within_days' => self::ENDING_WITHIN_DAYS,
            'can_manage' => $request->user()->can('assets.items.manage'),
            'sees_money' => $seesMoney,
        ]);
    }

    public function create(Request $request): Response
    {
        $itemId = (int) $request->query('item');
        $planId = (int) $request->query('plan');

        return Inertia::render('assets/maintenance/create', [
            'items' => AssetItem::query()
                ->where('tracking', AssetTracking::Individual->value)
                ->whereNotIn('status', [AssetStatus::Disposed->value, AssetStatus::Missing->value])
                ->orderBy('tag')->get(['id', 'tag', 'name', 'status'])
                ->map(fn (AssetItem $item) => ['value' => (string) $item->id, 'label' => "{$item->tag} {$item->name}", 'status' => $item->status->label()]),
            'plans' => AssetMaintenancePlan::query()->where('is_active', true)->orderBy('title')->get(['id', 'asset_item_id', 'title'])
                ->map(fn (AssetMaintenancePlan $plan) => ['value' => (string) $plan->id, 'label' => $plan->title, 'item_id' => $plan->asset_item_id]),
            'preselected' => ['item' => $itemId ?: null, 'plan' => $planId ?: null],
            'kinds' => MaintenanceKind::options(),
            'conditions' => AssetCondition::options(),
            'today' => Carbon::today()->toDateString(),
        ] + $this->whoOptions($request));
    }

    public function store(Request $request, RecordMaintenance $maintenance, StoreAttachment $store): RedirectResponse
    {
        $data = $request->validate([
            'item_id' => ['required', 'integer'],
            'plan_id' => ['nullable', 'integer'],
            'kind' => ['required', Rule::enum(MaintenanceKind::class)],
            'problem' => ['required', 'string', 'max:'.RecordMaintenance::MAX_TEXT],
            'reported_on' => ['required', 'date'],
            'out_of_service' => ['boolean'],
            'done' => ['boolean'],
            'done_on' => ['nullable', 'date'],
            'work_done' => ['nullable', 'string', 'max:'.RecordMaintenance::MAX_TEXT],
            'condition_after' => ['nullable', Rule::enum(AssetCondition::class)],
            'done_by_user_id' => ['nullable', 'integer'],
            'party_id' => ['nullable', 'integer'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'expense_voucher_id' => ['nullable', 'integer'],
            'photos' => ['nullable', 'array', 'max:6'],
            'photos.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ]);

        // ⚠ Only a thing in this register.
        $item = AssetItem::query()->findOrFail($data['item_id']);
        unset($data['photos']);

        $record = $maintenance->open($item, $data, $request->user());

        foreach ($request->file('photos', []) as $file) {
            $store->handle($record, $file, AssetMaintenanceRecord::PHOTOS, $request->user());
        }

        return redirect()->route('assets.maintenance.show', $record)->with('success', "{$record->number} recorded.");
    }

    public function show(Request $request, AssetMaintenanceRecord $record): Response
    {
        $record->load(['item:id,tag,name,status,condition', 'plan:id,title', 'reportedBy:id,name', 'doneBy:id,name', 'party:id,name,organisation', 'voucher:id,voucher_number,spent_on,total', 'closedBy:id,name', 'attachments']);
        $seesMoney = $request->user()->can('assets.view');

        return Inertia::render('assets/maintenance/show', [
            'record' => self::row($record, $seesMoney) + [
                'plan' => $record->plan ? ['id' => $record->plan->id, 'title' => $record->plan->title] : null,
                'item_status' => $record->item?->status->label(),
                'item_condition' => $record->item?->condition->label(),
                'status_before' => AssetStatus::tryFrom((string) $record->status_before)?->label(),
                'closed_by' => $record->closedBy?->name,
                'closed_at' => $record->closed_at?->timezone(config('app.timezone'))->format('Y-m-d H:i'),
                'cancel_reason' => $record->cancel_reason,
                'photos' => $record->attachments->where('collection', AssetMaintenanceRecord::PHOTOS)->sortByDesc('id')
                    ->map(fn (Attachment $file) => ['id' => $file->id, 'url' => route('attachments.show', $file->id), 'name' => $file->original_name])->values(),
            ],
            'kinds' => MaintenanceKind::options(),
            'conditions' => AssetCondition::options(),
            'today' => Carbon::today()->toDateString(),
            'can_manage' => $request->user()->can('assets.items.manage'),
        ] + $this->whoOptions($request));
    }

    public function complete(Request $request, AssetMaintenanceRecord $record, RecordMaintenance $maintenance): RedirectResponse
    {
        $data = $request->validate([
            'done_on' => ['required', 'date'],
            'work_done' => ['required', 'string', 'max:'.RecordMaintenance::MAX_TEXT],
            'condition_after' => ['nullable', Rule::enum(AssetCondition::class)],
            'done_by_user_id' => ['nullable', 'integer'],
            'party_id' => ['nullable', 'integer'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'expense_voucher_id' => ['nullable', 'integer'],
        ]);

        $done = $maintenance->complete($record, $data, $request->user());

        return back()->with('success', "{$done->number} is done.");
    }

    public function cancel(Request $request, AssetMaintenanceRecord $record, RecordMaintenance $maintenance): RedirectResponse
    {
        $data = $request->validate(['cancel_reason' => ['required', 'string', 'max:500']]);

        $cancelled = $maintenance->cancel($record, $data['cancel_reason'], $request->user());

        return back()->with('success', "{$cancelled->number} cancelled.");
    }

    public function money(Request $request, AssetMaintenanceRecord $record, RecordMaintenance $maintenance): RedirectResponse
    {
        $data = $request->validate([
            'cost' => ['nullable', 'numeric', 'min:0'],
            'expense_voucher_id' => ['nullable', 'integer'],
        ]);

        $maintenance->setMoney($record, $data, $request->user());

        return back()->with('success', 'Cost saved.');
    }

    public function storePhotos(Request $request, AssetMaintenanceRecord $record, StoreAttachment $store): RedirectResponse
    {
        $request->validate([
            'photos' => ['required', 'array', 'min:1', 'max:6'],
            'photos.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ]);

        foreach ($request->file('photos', []) as $file) {
            $store->handle($record, $file, AssetMaintenanceRecord::PHOTOS, $request->user());
        }

        return back()->with('success', 'Photo added.');
    }

    /**
     * One record as a list row.
     *
     * @return array<string, mixed>
     */
    public static function row(AssetMaintenanceRecord $record, bool $seesMoney): array
    {
        return [
            'id' => $record->id,
            'number' => $record->number,
            'item_id' => $record->asset_item_id,
            'tag' => $record->item?->tag,
            'name' => $record->item?->name,
            'kind' => $record->kind->value,
            'kind_label' => $record->kind->label(),
            'state' => $record->state->value,
            'state_label' => $record->state->label(),
            'problem' => $record->problem,
            'reported_by' => $record->reportedBy?->name,
            'reported_on' => $record->reported_on->toDateString(),
            'out_of_service' => $record->out_of_service,
            'done_on' => $record->done_on?->toDateString(),
            'work_done' => $record->work_done,
            'condition_after' => $record->condition_after?->label(),
            'done_by' => $record->whoLabel(),
            'done_by_user_id' => $record->done_by_user_id,
            'party_id' => $record->party_id,
            // ⚠ Money only for whoever sees the financial register.
            'cost' => $seesMoney && $record->cost !== null ? Money::ofTaka((string) $record->cost)->toTaka() : null,
            'voucher' => $seesMoney && $record->voucher ? ['id' => $record->voucher->id, 'number' => $record->voucher->voucher_number] : null,
            'expense_voucher_id' => $seesMoney ? $record->expense_voucher_id : null,
        ];
    }

    /**
     * Warranties and service contracts ending soon, on things still kept.
     *
     * ⚠ Static and public since 15 Oct 2026: the Assets overview counts the
     * same ends, from the same query, so the two screens never disagree.
     *
     * @return list<array<string, mixed>>
     */
    public static function endingSoon(Carbon $today): array
    {
        $until = $today->copy()->addDays(self::ENDING_WITHIN_DAYS)->toDateString();
        $from = $today->toDateString();

        return AssetItem::query()
            ->where('status', '!=', AssetStatus::Disposed->value)
            // ⚠ whereDate, not whereBetween: a date cast is stored with a time on
            // SQLite, and "2026-10-17 00:00:00" sorts after "2026-10-17".
            ->where(fn ($q) => $q->where(fn ($w) => $w->whereDate('warranty_ends_on', '>=', $from)->whereDate('warranty_ends_on', '<=', $until))
                ->orWhere(fn ($w) => $w->whereDate('service_ends_on', '>=', $from)->whereDate('service_ends_on', '<=', $until)))
            ->with('serviceParty:id,name,organisation')
            ->orderBy('tag')->limit(200)->get()
            ->flatMap(fn (AssetItem $item) => collect([
                ['what' => 'Warranty', 'on' => $item->warranty_ends_on, 'by' => null],
                ['what' => 'Service contract', 'on' => $item->service_ends_on, 'by' => $item->serviceParty?->label()],
            ])->filter(fn (array $end) => $end['on'] !== null && $end['on']->toDateString() >= $from && $end['on']->toDateString() <= $until)
                ->map(fn (array $end) => [
                    'item_id' => $item->id,
                    'tag' => $item->tag,
                    'name' => $item->name,
                    'what' => $end['what'],
                    'ends_on' => $end['on']->toDateString(),
                    'by' => $end['by'],
                ]))
            ->sortBy('ends_on')->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function whoOptions(Request $request): array
    {
        $businessId = app(BusinessContext::class)->id();

        return [
            'users' => User::query()->whereHas('businesses', fn ($q) => $q->where('businesses.id', $businessId))
                ->orderBy('name')->get(['id', 'name'])
                ->map(fn (User $user) => ['value' => (string) $user->id, 'label' => $user->name]),
            'parties' => AssetParty::query()->where('is_active', true)->orderBy('name')->get()
                ->map(fn (AssetParty $party) => ['value' => (string) $party->id, 'label' => $party->label().' - '.$party->kind->label()]),
            'vouchers' => $request->user()->can('assets.view') ? $this->vouchers() : [],
            'sees_money' => $request->user()->can('assets.view'),
        ];
    }

    /**
     * Recent expense vouchers of this business, to say which one paid.
     *
     * @return list<array{value: string, label: string}>
     */
    private function vouchers(): array
    {
        return ExpenseVoucher::query()->orderByDesc('spent_on')->orderByDesc('id')->limit(300)
            ->get(['id', 'voucher_number', 'spent_on', 'paid_to', 'total'])
            ->map(fn (ExpenseVoucher $voucher) => [
                'value' => (string) $voucher->id,
                'label' => trim("{$voucher->voucher_number} {$voucher->spent_on?->toDateString()} {$voucher->paid_to}").' - '.Money::ofTaka((string) $voucher->total)->toTaka(),
            ])->values()->all();
    }
}
