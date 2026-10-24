<?php

namespace App\Modules\Assets\Actions;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Assets\Enums\MaintenanceState;
use App\Modules\Assets\Models\AssetCount;
use App\Modules\Assets\Models\AssetCountLine;
use App\Modules\Assets\Models\AssetCredential;
use App\Modules\Assets\Models\AssetHolding;
use App\Modules\Assets\Models\AssetItem;
use App\Modules\Assets\Models\AssetMaintenancePlan;
use App\Modules\Assets\Models\AssetMaintenanceRecord;
use App\Modules\Assets\Models\AssetMovement;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\CorrectionLog;
use App\Modules\Foundation\Corrections\Owners;
use App\Modules\Foundation\Corrections\SecondPersonRule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ Corrections wave 3, B11 (24 Oct 2026): a thing leaves the company
 * (FINAL-PLAN 3.5, "Thing sold, scrapped, donated, lost"; slice A5 was never
 * built until now).
 *
 *   dispose   method (sold, scrapped, donated, lost), the day it left (not in
 *             the future, not before it was bought or last moved) and a
 *             reason. Custody is closed with this final event; the last
 *             holder stays in the history and in the snapshot. Its
 *             maintenance plans stop, its logins are flagged for review, a
 *             counted group's pieces leave the holdings.
 *   undo      within 30 days, while it is still Disposed: everything the
 *             snapshot recorded comes back, plans included.
 *
 * ⚠⚠ "Registered by mistake" is NOT a disposal (FINAL-PLAN 8.1, 20): it would
 * record a false loss. A never-used duplicate is deleted to the bin.
 *
 * ⚠ Approval by value: above the business's second-person limit (Tk 20,000,
 * FinancePolicy) only the Owner or a Manager disposes. Below it, the IT
 * Custodian too. ⚠ Nothing here posts a journal: a thing is physical; a
 * fixed asset on the books is disposed on its own page (wave 2).
 */
final class DisposeAssetItem
{
    public function __construct(private readonly SecondPersonRule $limit) {}

    /** Why this person cannot dispose of this thing now, or null. */
    public function blocker(AssetItem $item, User $user): ?string
    {
        if ($item->status === AssetStatus::Disposed) {
            return "{$item->tag} was already disposed.";
        }

        if ($item->linkedFixedAssetId() !== null) {
            return "{$item->tag} is a fixed asset on the books. Dispose it on the fixed asset page.";
        }

        if ($item->isInTransit()) {
            return "{$item->tag} is on its way to another place. Receive it there first.";
        }

        if (($count = $this->openCountOf($item)) !== null) {
            return "{$item->tag} is on the open count {$count->number}. Close or cancel that count first.";
        }

        if (($record = AssetMaintenanceRecord::query()->withoutGlobalScopes()->where('asset_item_id', $item->id)->where('state', MaintenanceState::Open->value)->first()) !== null) {
            return "{$item->tag} has open maintenance {$record->number}. Complete or cancel it first.";
        }

        if (! $item->isIndividual() && AssetHolding::query()->withoutGlobalScopes()->where('asset_item_id', $item->id)->whereNotNull('employee_id')->orWhere(fn ($q) => $q->where('asset_item_id', $item->id)->whereNotNull('party_id'))->exists()) {
            return "Some pieces of {$item->tag} are with people. Take them back first.";
        }

        if ($this->aboveLimit($item) && ! Owners::is($user) && ! $user->hasRole('Manager')) {
            return "{$item->tag} cost more than the approval limit, so the Owner or a Manager disposes of it.";
        }

        return null;
    }

    /** Why the disposal cannot be undone now, or null. */
    public function undoBlocker(AssetItem $item): ?string
    {
        if ($item->status !== AssetStatus::Disposed || $item->disposal_snapshot === null) {
            return "{$item->tag} is not disposed.";
        }

        if ($item->disposed_at === null || $item->disposed_at->copy()->addDays(AssetItem::UNDO_DISPOSAL_DAYS)->isPast()) {
            return "{$item->tag} was disposed more than ".AssetItem::UNDO_DISPOSAL_DAYS.' days ago, so the disposal stands.';
        }

        return null;
    }

    /**
     * @param  array{disposed_on: string, method: string}  $data
     *
     * @throws ValidationException
     */
    public function dispose(AssetItem $item, array $data, User $by, ActionReason $why): AssetItem
    {
        $method = (string) ($data['method'] ?? '');

        if (! in_array($method, AssetItem::DISPOSAL_METHODS, true)) {
            throw ValidationException::withMessages(['method' => 'Say how it left: sold, scrapped, donated or lost.']);
        }

        $on = Carbon::parse($data['disposed_on'] ?? '')->startOfDay();

        if ($on->isFuture()) {
            throw ValidationException::withMessages(['disposed_on' => 'A disposal cannot be dated in the future.']);
        }

        return DB::transaction(function () use ($item, $method, $on, $by, $why) {
            /** @var AssetItem $locked */
            $locked = AssetItem::query()->withoutGlobalScopes()->whereNull('deleted_at')->whereKey($item->id)->lockForUpdate()->firstOrFail();

            if (($refusal = $this->blocker($locked, $by)) !== null) {
                throw ValidationException::withMessages(['reason_text' => $refusal]);
            }

            if ($locked->acquired_on !== null && $on->lt($locked->acquired_on)) {
                throw ValidationException::withMessages(['disposed_on' => "{$locked->tag} was bought on ".$locked->acquired_on->format('d/m/Y').'. It cannot leave before that.']);
            }

            $lastMove = AssetMovement::query()->withoutGlobalScopes()->whereHas('lines', fn ($q) => $q->where('asset_item_id', $locked->id))->max('moved_on');

            if ($lastMove !== null && $on->lt(Carbon::parse($lastMove)->startOfDay())) {
                throw ValidationException::withMessages(['disposed_on' => "{$locked->tag} was last handed over on ".Carbon::parse($lastMove)->format('d/m/Y').'. It cannot leave before that.']);
            }

            $holdings = AssetHolding::query()->withoutGlobalScopes()->where('asset_item_id', $locked->id)->get();
            $plans = AssetMaintenancePlan::query()->withoutGlobalScopes()->where('asset_item_id', $locked->id)->where('is_active', true)->pluck('id');
            $holder = $locked->custodyLabel();

            $snapshot = [
                'status' => $locked->status->value,
                'custodian_employee_id' => $locked->custodian_employee_id,
                'custodian_party_id' => $locked->custodian_party_id,
                'custody_since' => $locked->custody_since?->toDateString(),
                'acknowledged_at' => $locked->acknowledged_at?->toDateTimeString(),
                'holdings' => $holdings->map(fn (AssetHolding $h) => $h->only(['office_id', 'warehouse_id', 'employee_id', 'party_id', 'holder_key', 'quantity']))->values()->all(),
                'paused_plan_ids' => $plans->all(),
                'last_holder' => $holder,
            ];

            AssetHolding::query()->withoutGlobalScopes()->where('asset_item_id', $locked->id)->delete();
            AssetMaintenancePlan::query()->withoutGlobalScopes()->whereIn('id', $plans)->update(['is_active' => false]);

            $flagged = AssetCredential::query()->withoutGlobalScopes()->whereNull('deleted_at')
                ->where('credentialable_type', $locked->getMorphClass())->where('credentialable_id', $locked->id)
                ->where('needs_review', false)->update(['needs_review' => true]);

            $locked->forceFill([
                'status' => AssetStatus::Disposed,
                'custodian_employee_id' => null,
                'custodian_party_id' => null,
                'custody_since' => null,
                'acknowledged_at' => null,
                'disposed_on' => $on,
                'disposed_at' => Carbon::now(),
                'disposed_by' => $by->id,
                'disposal_method' => $method,
                'disposal_reason' => $why->display(),
                'disposal_snapshot' => $snapshot,
            ])->save();

            activity('assets.items')->performedOn($locked)->causedBy($by)
                ->log("Disposed on {$on->format('d/m/Y')} ({$locked->disposalMethodLabel()}), last with {$holder}: {$why->display()}"
                    .($flagged > 0 ? ". {$flagged} login(s) flagged for review" : ''));

            CorrectionLog::event('disposed', $by, $locked, ['reason' => $why->display(), 'method' => $method, 'on' => $on->toDateString()]);

            return $locked->fresh();
        });
    }

    /**
     * @throws ValidationException
     */
    public function undo(AssetItem $item, User $by, ActionReason $why): AssetItem
    {
        return DB::transaction(function () use ($item, $by, $why) {
            /** @var AssetItem $locked */
            $locked = AssetItem::query()->withoutGlobalScopes()->whereNull('deleted_at')->whereKey($item->id)->lockForUpdate()->firstOrFail();

            if (($refusal = $this->undoBlocker($locked)) !== null) {
                throw ValidationException::withMessages(['reason_text' => $refusal]);
            }

            $snapshot = $locked->disposal_snapshot;

            foreach ($snapshot['holdings'] ?? [] as $holding) {
                AssetHolding::create(['business_id' => $locked->business_id, 'asset_item_id' => $locked->id] + $holding);
            }

            AssetMaintenancePlan::query()->withoutGlobalScopes()->whereIn('id', $snapshot['paused_plan_ids'] ?? [])->update(['is_active' => true]);

            $locked->forceFill([
                'status' => AssetStatus::tryFrom((string) ($snapshot['status'] ?? '')) ?? AssetStatus::InStore,
                'custodian_employee_id' => $snapshot['custodian_employee_id'] ?? null,
                'custodian_party_id' => $snapshot['custodian_party_id'] ?? null,
                'custody_since' => $snapshot['custody_since'] ?? null,
                'acknowledged_at' => $snapshot['acknowledged_at'] ?? null,
                'disposed_on' => null,
                'disposed_at' => null,
                'disposed_by' => null,
                'disposal_method' => null,
                'disposal_reason' => null,
                'disposal_snapshot' => null,
            ])->save();

            activity('assets.items')->performedOn($locked)->causedBy($by)->log("Disposal undone: {$why->display()}");
            CorrectionLog::event('disposal_undone', $by, $locked, ['reason' => $why->display()]);

            return $locked->fresh();
        });
    }

    /** The value the approval limit judges: what the thing cost. */
    private function aboveLimit(AssetItem $item): bool
    {
        if ($item->purchase_price === null) {
            return false;
        }

        return $this->limit->applies((int) $item->business_id, Money::ofTaka((string) $item->purchase_price)->minor);
    }

    private function openCountOf(AssetItem $item): ?AssetCount
    {
        $countId = AssetCountLine::query()->where('asset_item_id', $item->id)->pluck('asset_count_id');

        return AssetCount::query()->withoutGlobalScopes()->whereIn('id', $countId)->where('status', 'open')->first();
    }
}
