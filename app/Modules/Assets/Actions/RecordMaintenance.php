<?php

namespace App\Modules\Assets\Actions;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Assets\Enums\AssetCondition;
use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Assets\Enums\MaintenanceKind;
use App\Modules\Assets\Enums\MaintenanceState;
use App\Modules\Assets\Models\AssetItem;
use App\Modules\Assets\Models\AssetMaintenancePlan;
use App\Modules\Assets\Models\AssetMaintenanceRecord;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Purchasing\Models\ExpenseVoucher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Faults reported and work done on a thing (slice A4, plan §4e).
 *
 * ⚠⚠ Every rule is HERE:
 * - only a tagged thing still kept and not lost;
 * - "out of service" sets it Under repair, and closing puts the old status
 *   back only if nothing else has changed it meanwhile (a send-out to a
 *   repair shop, a hand-over, a hand edit);
 * - dates never in the future, and the work never done before it was reported;
 * - doing a plan's work moves the plan on from the day it was done, never back;
 * - a done or cancelled record never changes, except its money;
 * - ⛔ money only from whoever sees the financial register (plan §2.14), and
 *   a voucher only of the same business. No payment is made here.
 */
final class RecordMaintenance
{
    public const MAX_TEXT = 2000;

    /**
     * Open a record: a fault, or work to be done. With `done` set it is
     * recorded as already finished, in the same step.
     *
     * @param  array<string, mixed>  $a
     *
     * @throws ValidationException
     */
    public function open(AssetItem $item, array $a, User $by): AssetMaintenanceRecord
    {
        $kind = MaintenanceKind::tryFrom((string) ($a['kind'] ?? ''));

        if ($kind === null) {
            throw ValidationException::withMessages(['kind' => 'Say what kind of work it is.']);
        }

        $problem = $this->text($a['problem'] ?? null);

        if ($problem === null) {
            throw ValidationException::withMessages(['problem' => 'Say what is wrong, or what is to be done.']);
        }

        $reportedOn = $this->date($a['reported_on'] ?? null, 'reported_on') ?? Carbon::today();
        $planId = filled($a['plan_id'] ?? null) ? (int) $a['plan_id'] : null;

        return DB::transaction(function () use ($item, $a, $by, $kind, $problem, $reportedOn, $planId) {
            /** @var AssetItem $locked */
            $locked = AssetItem::query()->withoutGlobalScopes()->whereKey($item->id)->lockForUpdate()->firstOrFail();

            $this->workable($locked);

            if ($planId !== null && ! AssetMaintenancePlan::query()->withoutGlobalScopes()->whereKey($planId)->where('asset_item_id', $locked->id)->exists()) {
                throw ValidationException::withMessages(['plan_id' => "That plan is not one of {$locked->tag}'s."]);
            }

            $outOfService = (bool) ($a['out_of_service'] ?? false);
            $statusBefore = null;

            if ($outOfService && in_array($locked->status, [AssetStatus::InStore, AssetStatus::InUse], true)) {
                $statusBefore = $locked->status->value;
                $locked->forceFill(['status' => AssetStatus::UnderRepair])->save();
            }

            $record = AssetMaintenanceRecord::create([
                'business_id' => $locked->business_id,
                'number' => $this->nextNumber($locked->business_id),
                'asset_item_id' => $locked->id,
                'asset_maintenance_plan_id' => $planId,
                'kind' => $kind,
                'state' => MaintenanceState::Open,
                'problem' => $problem,
                'reported_by' => $by->id,
                'reported_on' => $reportedOn,
                'out_of_service' => $outOfService,
                'status_before' => $statusBefore,
            ]);

            activity('assets.items')->performedOn($locked)->causedBy($by)
                ->log("{$record->number} opened: {$kind->label()} - ".mb_strimwidth($problem, 0, 120, '...'));

            if ($a['done'] ?? false) {
                return $this->complete($record, $a, $by);
            }

            return $record;
        });
    }

    /**
     * The holder of a thing says something is wrong with it (from My assets).
     *
     * @throws ValidationException
     */
    public function report(AssetItem $item, string $problem, User $by): AssetMaintenanceRecord
    {
        $employee = Employee::query()->withoutGlobalScopes()->where('user_id', $by->id)->first();

        // ⚠ Only the person the register says holds it.
        if ($employee === null || $item->custodian_employee_id !== $employee->id) {
            throw ValidationException::withMessages(['item' => "{$item->tag} is not recorded as with you."]);
        }

        $open = AssetMaintenanceRecord::query()->withoutGlobalScopes()
            ->where('asset_item_id', $item->id)->where('state', MaintenanceState::Open->value)
            ->where('kind', MaintenanceKind::Repair->value)->value('number');

        if ($open !== null) {
            throw ValidationException::withMessages(['item' => "A problem with {$item->tag} is already open as {$open}. The keeper has it."]);
        }

        return $this->open($item, ['kind' => MaintenanceKind::Repair->value, 'problem' => $problem], $by);
    }

    /**
     * @param  array<string, mixed>  $a
     *
     * @throws ValidationException
     */
    public function complete(AssetMaintenanceRecord $record, array $a, User $by): AssetMaintenanceRecord
    {
        return DB::transaction(function () use ($record, $a, $by) {
            $locked = $this->lockOpen($record);

            $doneOn = $this->date($a['done_on'] ?? null, 'done_on');

            if ($doneOn === null) {
                throw ValidationException::withMessages(['done_on' => 'Say when the work was done.']);
            }

            if ($doneOn->lt($locked->reported_on)) {
                throw ValidationException::withMessages(['done_on' => 'The work cannot be done before it was reported ('.$locked->reported_on->toDateString().').']);
            }

            $workDone = $this->text($a['work_done'] ?? null);

            if ($workDone === null) {
                throw ValidationException::withMessages(['work_done' => 'Say what was done.']);
            }

            $condition = null;

            if (filled($a['condition_after'] ?? null)) {
                $condition = AssetCondition::tryFrom((string) $a['condition_after'])
                    ?? throw ValidationException::withMessages(['condition_after' => 'Choose a condition.']);
            }

            [$doneById, $partyId] = SaveMaintenancePlan::who($locked->business_id, $a['done_by_user_id'] ?? null, $a['party_id'] ?? null);

            $locked->forceFill([
                'state' => MaintenanceState::Done,
                'done_on' => $doneOn,
                'work_done' => $workDone,
                'condition_after' => $condition,
                'done_by_user_id' => $doneById,
                'party_id' => $partyId,
                'closed_by' => $by->id,
                'closed_at' => Carbon::now(),
            ] + ($by->can('assets.view') ? $this->money($locked, $a) : []))->save();

            /** @var AssetItem $item */
            $item = AssetItem::query()->withoutGlobalScopes()->whereKey($locked->asset_item_id)->lockForUpdate()->firstOrFail();

            if ($condition !== null) {
                $item->forceFill(['condition' => $condition])->save();
            }

            $this->putStatusBack($locked, $item);

            if ($locked->asset_maintenance_plan_id !== null) {
                /** @var AssetMaintenancePlan $plan */
                $plan = AssetMaintenancePlan::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($locked->asset_maintenance_plan_id);

                // ⚠ Recording older work late never pulls the plan backwards.
                if ($plan->last_done_on === null || $doneOn->gte($plan->last_done_on)) {
                    $next = $plan->every_unit->after($doneOn, $plan->every_count);
                    $plan->forceFill([
                        'last_done_on' => $doneOn,
                        'next_due_on' => $next,
                        'remind_from' => $next->copy()->subDays($plan->remind_days),
                    ])->save();
                }
            }

            activity('assets.items')->performedOn($item)->causedBy($by)
                ->log("{$locked->number} done on {$doneOn->toDateString()}: ".mb_strimwidth($workDone, 0, 120, '...'));

            return $locked->fresh();
        });
    }

    /**
     * @throws ValidationException
     */
    public function cancel(AssetMaintenanceRecord $record, ?string $reason, User $by): AssetMaintenanceRecord
    {
        $reason = $this->text($reason);

        if ($reason === null) {
            throw ValidationException::withMessages(['cancel_reason' => 'Say why it is cancelled.']);
        }

        return DB::transaction(function () use ($record, $reason, $by) {
            $locked = $this->lockOpen($record);

            $locked->forceFill([
                'state' => MaintenanceState::Cancelled,
                'cancel_reason' => mb_substr($reason, 0, 500),
                'closed_by' => $by->id,
                'closed_at' => Carbon::now(),
            ])->save();

            /** @var AssetItem $item */
            $item = AssetItem::query()->withoutGlobalScopes()->whereKey($locked->asset_item_id)->lockForUpdate()->firstOrFail();
            $this->putStatusBack($locked, $item);

            activity('assets.items')->performedOn($item)->causedBy($by)->log("{$locked->number} cancelled: {$reason}");

            return $locked->fresh();
        });
    }

    /**
     * What the work cost and which voucher paid it, open or done.
     *
     * @param  array<string, mixed>  $a
     *
     * @throws ValidationException
     */
    public function setMoney(AssetMaintenanceRecord $record, array $a, User $by): AssetMaintenanceRecord
    {
        if (! $by->can('assets.view')) {
            throw ValidationException::withMessages(['cost' => 'Only someone who sees the financial register records what work cost.']);
        }

        return DB::transaction(function () use ($record, $a, $by) {
            /** @var AssetMaintenanceRecord $locked */
            $locked = AssetMaintenanceRecord::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($record->id);

            if ($locked->state === MaintenanceState::Cancelled) {
                throw ValidationException::withMessages(['cost' => "{$locked->number} was cancelled."]);
            }

            $locked->forceFill($this->money($locked, $a))->save();

            $voucher = $locked->expense_voucher_id ? ExpenseVoucher::query()->withoutGlobalScopes()->whereKey($locked->expense_voucher_id)->value('voucher_number') : null;

            // ⚠ The item's history is read by people who may not see money:
            // it says that the money was recorded, never how much.
            activity('assets.items')->performedOn(AssetItem::query()->withoutGlobalScopes()->findOrFail($locked->asset_item_id))->causedBy($by)
                ->log("{$locked->number}: cost recorded".($voucher ? ", paid by {$voucher}" : ''));

            return $locked->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $a
     * @return array{cost: string|null, expense_voucher_id: int|null}
     *
     * @throws ValidationException
     */
    private function money(AssetMaintenanceRecord $record, array $a): array
    {
        $cost = null;

        if (filled($a['cost'] ?? null)) {
            $money = Money::ofTaka((string) $a['cost']);

            if ($money->minor < 0) {
                throw ValidationException::withMessages(['cost' => 'A cost cannot be negative.']);
            }

            $cost = $money->toTaka();
        }

        $voucherId = filled($a['expense_voucher_id'] ?? null) ? (int) $a['expense_voucher_id'] : null;

        if ($voucherId !== null && ! ExpenseVoucher::query()->withoutGlobalScopes()->whereKey($voucherId)->where('business_id', $record->business_id)->exists()) {
            throw ValidationException::withMessages(['expense_voucher_id' => 'That voucher is not one of this business.']);
        }

        return ['cost' => $cost, 'expense_voucher_id' => $voucherId];
    }

    /**
     * @throws ValidationException
     */
    private function workable(AssetItem $item): void
    {
        if (! $item->isIndividual()) {
            throw ValidationException::withMessages(['item' => 'Maintenance is kept for a tagged thing, not a counted group.']);
        }

        if ($item->status === AssetStatus::Disposed) {
            throw ValidationException::withMessages(['item' => "{$item->tag} was disposed."]);
        }

        if ($item->status === AssetStatus::Missing) {
            throw ValidationException::withMessages(['item' => "{$item->tag} is written down as missing. Settle that first."]);
        }
    }

    /**
     * ⚠ Only if it is still Under repair and nobody outside has it: a send-out
     * to a repair shop, or a hand edit, decided its status since.
     */
    private function putStatusBack(AssetMaintenanceRecord $record, AssetItem $item): void
    {
        $before = AssetStatus::tryFrom((string) $record->status_before);

        if ($before !== null && $item->status === AssetStatus::UnderRepair && $item->custodian_party_id === null && $item->transit_movement_id === null) {
            $item->forceFill(['status' => $before])->save();
        }
    }

    /**
     * @throws ValidationException
     */
    private function lockOpen(AssetMaintenanceRecord $record): AssetMaintenanceRecord
    {
        /** @var AssetMaintenanceRecord $locked */
        $locked = AssetMaintenanceRecord::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($record->id);

        if (! $locked->isOpen()) {
            throw ValidationException::withMessages(['record' => "{$locked->number} is already {$locked->state->label()}."]);
        }

        return $locked;
    }

    /**
     * @throws ValidationException
     */
    private function date(mixed $value, string $field): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        $date = Carbon::parse((string) $value)->startOfDay();

        if ($date->isFuture()) {
            throw ValidationException::withMessages([$field => 'That date is in the future.']);
        }

        if ($date->year < 2000) {
            throw ValidationException::withMessages([$field => 'That date is too far back.']);
        }

        return $date;
    }

    private function text(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : mb_substr($text, 0, self::MAX_TEXT);
    }

    private function nextNumber(int $businessId): string
    {
        // ⚠ Read under the item's lock; the unique index on (business, number)
        // is the backstop for two things at once.
        $last = (int) AssetMaintenanceRecord::query()->withoutGlobalScopes()
            ->where('business_id', $businessId)
            ->selectRaw('max(cast(substr(number, 4) as signed)) as n')
            ->value('n');

        return sprintf('MR-%06d', $last + 1);
    }
}
