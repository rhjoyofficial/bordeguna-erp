<?php

namespace App\Modules\Assets\Actions;

use App\Models\User;
use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Assets\Enums\MaintenanceEvery;
use App\Modules\Assets\Models\AssetItem;
use App\Modules\Assets\Models\AssetMaintenancePlan;
use App\Modules\Assets\Models\AssetParty;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Add or change what should be done to a thing, and how often (slice A4).
 *
 * ⚠⚠ Every rule is HERE: a plan belongs to one tagged thing that is still kept
 * and not lost, repeats at least once a day and at most every 366 units, and is
 * done by a person of this business or an active outside party of it, never
 * both.
 */
final class SaveMaintenancePlan
{
    public const MAX_EVERY = 366;

    public const MAX_REMIND_DAYS = 90;

    /**
     * @param  array<string, mixed>  $a
     *
     * @throws ValidationException
     */
    public function save(AssetItem $item, array $a, User $by, ?AssetMaintenancePlan $plan = null): AssetMaintenancePlan
    {
        if ($plan !== null && $plan->asset_item_id !== $item->id) {
            throw ValidationException::withMessages(['plan' => 'That plan belongs to another thing.']);
        }

        if (! $item->isIndividual()) {
            throw ValidationException::withMessages(['item' => 'Maintenance is planned for a tagged thing, not a counted group.']);
        }

        if (in_array($item->status, [AssetStatus::Disposed, AssetStatus::Missing], true)) {
            throw ValidationException::withMessages(['item' => "{$item->tag} is {$item->status->label()}; nothing can be planned for it."]);
        }

        $title = trim((string) ($a['title'] ?? ''));

        if ($title === '') {
            throw ValidationException::withMessages(['title' => 'Say what should be done.']);
        }

        $every = (int) ($a['every_count'] ?? 0);

        if ($every < 1 || $every > self::MAX_EVERY) {
            throw ValidationException::withMessages(['every_count' => 'Repeat every 1 to '.self::MAX_EVERY.'.']);
        }

        $unit = MaintenanceEvery::tryFrom((string) ($a['every_unit'] ?? ''));

        if ($unit === null) {
            throw ValidationException::withMessages(['every_unit' => 'Choose days, weeks or months.']);
        }

        if (blank($a['next_due_on'] ?? null)) {
            throw ValidationException::withMessages(['next_due_on' => 'Say when it is next due.']);
        }

        $due = Carbon::parse($a['next_due_on'])->startOfDay();

        if ($due->year < 2000 || $due->gt(Carbon::today()->addYears(10))) {
            throw ValidationException::withMessages(['next_due_on' => 'Give a due date within the next ten years.']);
        }

        $remind = (int) ($a['remind_days'] ?? 3);

        if ($remind < 0 || $remind > self::MAX_REMIND_DAYS) {
            throw ValidationException::withMessages(['remind_days' => 'Remind between 0 and '.self::MAX_REMIND_DAYS.' days ahead.']);
        }

        [$assigneeId, $partyId] = $this->who($item->business_id, $a['assignee_user_id'] ?? null, $a['party_id'] ?? null);

        $values = [
            'title' => mb_substr($title, 0, 160),
            'instructions' => filled($a['instructions'] ?? null) ? mb_substr(trim((string) $a['instructions']), 0, 5000) : null,
            'every_count' => $every,
            'every_unit' => $unit,
            'next_due_on' => $due,
            'remind_days' => $remind,
            'remind_from' => $due->copy()->subDays($remind),
            'assignee_user_id' => $assigneeId,
            'party_id' => $partyId,
        ];

        if ($plan === null) {
            $plan = AssetMaintenancePlan::create($values + [
                'business_id' => $item->business_id,
                'asset_item_id' => $item->id,
                'created_by' => $by->id,
            ]);

            activity('assets.items')->performedOn($item)->causedBy($by)
                ->log("Maintenance planned: {$plan->title} ({$plan->everyLabel()}, next {$due->toDateString()})");

            return $plan;
        }

        $plan->forceFill($values)->save();

        return $plan->fresh();
    }

    /**
     * @throws ValidationException
     */
    public function setActive(AssetMaintenancePlan $plan, bool $active, User $by): AssetMaintenancePlan
    {
        $item = $plan->item()->withoutGlobalScopes()->firstOrFail();

        if ($active && in_array($item->status, [AssetStatus::Disposed, AssetStatus::Missing], true)) {
            throw ValidationException::withMessages(['plan' => "{$item->tag} is {$item->status->label()}; its plan stays paused."]);
        }

        if ($plan->is_active !== $active) {
            $plan->forceFill(['is_active' => $active])->save();

            activity('assets.items')->performedOn($item)->causedBy($by)
                ->log(($active ? 'Maintenance plan resumed: ' : 'Maintenance plan paused: ').$plan->title);
        }

        return $plan;
    }

    /**
     * Who does the work: a user of this business, an active outside party of
     * it, or neither.
     *
     * @return array{0: int|null, 1: int|null}
     *
     * @throws ValidationException
     */
    public static function who(int $businessId, mixed $userId, mixed $partyId): array
    {
        $userId = filled($userId) ? (int) $userId : null;
        $partyId = filled($partyId) ? (int) $partyId : null;

        if ($userId !== null && $partyId !== null) {
            throw ValidationException::withMessages(['assignee_user_id' => 'Choose a person or an outside party, not both.']);
        }

        if ($userId !== null && ! User::query()->whereKey($userId)->whereHas('businesses', fn ($q) => $q->where('businesses.id', $businessId))->exists()) {
            throw ValidationException::withMessages(['assignee_user_id' => 'That person does not work in this business.']);
        }

        if ($partyId !== null && ! AssetParty::query()->withoutGlobalScopes()->whereKey($partyId)->where('business_id', $businessId)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['party_id' => 'That outside party is not an active one of this business.']);
        }

        return [$userId, $partyId];
    }
}
