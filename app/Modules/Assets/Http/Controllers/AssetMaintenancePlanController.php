<?php

namespace App\Modules\Assets\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Assets\Actions\SaveMaintenancePlan;
use App\Modules\Assets\Enums\MaintenanceEvery;
use App\Modules\Assets\Models\AssetItem;
use App\Modules\Assets\Models\AssetMaintenancePlan;
use App\Modules\Assets\Models\AssetParty;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Maintenance plans (slice A4): what should be done to a thing, and how often.
 * Added and changed from the thing's own page.
 */
class AssetMaintenancePlanController extends Controller
{
    public function create(Request $request): Response
    {
        $item = AssetItem::query()->findOrFail((int) $request->query('item'));

        return Inertia::render('assets/maintenance/plan-form', $this->options($item) + ['plan' => null]);
    }

    public function store(Request $request, SaveMaintenancePlan $plans): RedirectResponse
    {
        $data = $this->validated($request) + $request->validate(['item_id' => ['required', 'integer']]);
        $item = AssetItem::query()->findOrFail($data['item_id']);

        $plans->save($item, $data, $request->user());

        return redirect()->route('assets.items.show', $item)->with('success', 'Plan added.');
    }

    public function edit(AssetMaintenancePlan $plan): Response
    {
        $item = AssetItem::query()->findOrFail($plan->asset_item_id);

        return Inertia::render('assets/maintenance/plan-form', $this->options($item) + [
            'plan' => [
                'id' => $plan->id,
                'title' => $plan->title,
                'instructions' => $plan->instructions,
                'every_count' => $plan->every_count,
                'every_unit' => $plan->every_unit->value,
                'next_due_on' => $plan->next_due_on->toDateString(),
                'remind_days' => $plan->remind_days,
                'assignee_user_id' => $plan->assignee_user_id,
                'party_id' => $plan->party_id,
                'is_active' => $plan->is_active,
            ],
        ]);
    }

    public function update(Request $request, AssetMaintenancePlan $plan, SaveMaintenancePlan $plans): RedirectResponse
    {
        $item = AssetItem::query()->findOrFail($plan->asset_item_id);

        $plans->save($item, $this->validated($request), $request->user(), $plan);

        return redirect()->route('assets.items.show', $item)->with('success', 'Plan saved.');
    }

    public function setActive(Request $request, AssetMaintenancePlan $plan, SaveMaintenancePlan $plans): RedirectResponse
    {
        $data = $request->validate(['active' => ['required', 'boolean']]);

        $plans->setActive($plan, (bool) $data['active'], $request->user());

        return back()->with('success', $data['active'] ? 'Plan resumed.' : 'Plan paused.');
    }

    /**
     * One plan as a list row.
     *
     * @return array<string, mixed>
     */
    public static function row(AssetMaintenancePlan $plan): array
    {
        return [
            'id' => $plan->id,
            'item_id' => $plan->asset_item_id,
            'title' => $plan->title,
            'every' => $plan->everyLabel(),
            'next_due_on' => $plan->next_due_on->toDateString(),
            'overdue' => $plan->isOverdue(),
            'due_soon' => $plan->remind_from->lte(Carbon::today()),
            'last_done_on' => $plan->last_done_on?->toDateString(),
            'who' => $plan->whoLabel(),
            'is_active' => $plan->is_active,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'instructions' => ['nullable', 'string', 'max:5000'],
            'every_count' => ['required', 'integer', 'min:1', 'max:'.SaveMaintenancePlan::MAX_EVERY],
            'every_unit' => ['required', Rule::enum(MaintenanceEvery::class)],
            'next_due_on' => ['required', 'date'],
            'remind_days' => ['required', 'integer', 'min:0', 'max:'.SaveMaintenancePlan::MAX_REMIND_DAYS],
            'assignee_user_id' => ['nullable', 'integer'],
            'party_id' => ['nullable', 'integer'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function options(AssetItem $item): array
    {
        $businessId = app(BusinessContext::class)->id();

        return [
            'item' => ['id' => $item->id, 'tag' => $item->tag, 'name' => $item->name],
            'units' => MaintenanceEvery::options(),
            'users' => User::query()->whereHas('businesses', fn ($q) => $q->where('businesses.id', $businessId))
                ->orderBy('name')->get(['id', 'name'])
                ->map(fn (User $user) => ['value' => (string) $user->id, 'label' => $user->name]),
            'parties' => AssetParty::query()->where('is_active', true)->orderBy('name')->get()
                ->map(fn (AssetParty $party) => ['value' => (string) $party->id, 'label' => $party->label().' - '.$party->kind->label()]),
            'today' => Carbon::today()->toDateString(),
        ];
    }
}
