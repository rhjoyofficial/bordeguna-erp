<?php

namespace App\Modules\Assets\Actions;

use App\Models\User;
use App\Modules\Assets\Enums\AssetCondition;
use App\Modules\Assets\Enums\AssetMovementKind;
use App\Modules\Assets\Enums\AssetMovementStatus;
use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Assets\Models\AssetHolding;
use App\Modules\Assets\Models\AssetItem;
use App\Modules\Assets\Models\AssetMovement;
use App\Modules\Assets\Models\AssetParty;
use App\Modules\Assets\Notifications\AssetHandedOverNotification;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Office;
use App\Modules\Hrm\Enums\EmployeeStatus;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Hand things over, and record it (asset management slice A2, plan §4c).
 *
 *   issue          place  -> employee     custody set, status In use
 *   return         employee -> its place  custody cleared, status In store
 *   send_out       place  -> party        custody = party, Under repair / With an outside party
 *                  employee -> party      (13 Oct 2026) the holder hands it straight to the party
 *   receive_back   party  -> its place    custody cleared, status In store
 *   transfer       place A -> place B     two steps: dispatched (at neither place), received
 *   reassign       employee A -> B        (13 Oct 2026) custody moves, status stays In use, B acknowledges again
 *
 * ⚠⚠ EVERY RULE IS HERE. A thing moves only from where the register says it is:
 * an employee returns or passes on what they hold, a party gives back what it
 * was sent, a place issues what sits in it and is nobody's. A disposed or missing thing does
 * not move. A thing on its way moves nowhere until it arrives. A counted group
 * moves as many pieces as the holder has.
 *
 * ⚠ The movement, the item's custody and the holdings are written in ONE
 * transaction, under the items' locks.
 */
final class MoveAssets
{
    /**
     * @param  array<int, array{item: AssetItem, quantity?: int, condition?: string|null}>  $lines
     * @param  array{employee_id?: int|null, party_id?: int|null, office_id?: int|null, warehouse_id?: int|null}  $to
     *
     * @throws ValidationException
     */
    public function handle(AssetMovementKind $kind, array $lines, array $to, User $by, mixed $movedOn = null, ?string $note = null, ?string $expectedBackOn = null): AssetMovement
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'Choose at least one thing to move.']);
        }

        $movedOn = ($movedOn === null ? Carbon::now() : Carbon::parse($movedOn))->startOfDay();

        if ($movedOn->isFuture()) {
            throw ValidationException::withMessages(['moved_on' => 'A hand-over cannot be dated in the future.']);
        }

        // ⚠ "Expected back" belongs to a send-out and nothing else (13 Oct 2026).
        $expectedBack = $kind === AssetMovementKind::SendOut && $expectedBackOn !== null ? Carbon::parse($expectedBackOn)->startOfDay() : null;

        if ($expectedBack !== null && $expectedBack->lt($movedOn)) {
            throw ValidationException::withMessages(['expected_back_on' => 'Expected back cannot be before the day it was sent out.']);
        }

        $ids = array_map(fn (array $line) => $line['item']->id, $lines);

        $movement = DB::transaction(function () use ($kind, $lines, $to, $by, $movedOn, $expectedBack, $note, $ids) {
            /** @var Collection<int, AssetItem> $items */
            $items = AssetItem::query()->withoutGlobalScopes()->whereIn('id', $ids)->lockForUpdate()->get()->keyBy('id');

            if ($items->count() !== count(array_unique($ids))) {
                throw ValidationException::withMessages(['lines' => 'One of the things no longer exists.']);
            }

            $businessId = (int) $items->first()->business_id;

            if ($items->pluck('business_id')->unique()->count() !== 1) {
                throw ValidationException::withMessages(['lines' => 'Things of two businesses cannot move on one document.']);
            }

            $business = Business::query()->findOrFail($businessId);
            $destination = $this->destination($kind, $to, $business);
            $origin = null;

            foreach ($lines as $line) {
                /** @var AssetItem $item */
                $item = $items[$line['item']->id];
                $quantity = $item->isIndividual() ? 1 : max(0, (int) ($line['quantity'] ?? 0));

                $this->refuseIfStuck($item);

                $from = $this->origin($kind, $item, $destination);

                // ⚠ Every line of one document leaves the same holder: two
                // employees returning on one sheet is two hand-overs.
                if ($origin === null) {
                    $origin = $from;
                } elseif ($origin !== $from) {
                    throw ValidationException::withMessages(['lines' => "{$item->tag} is not with the same holder as the others. Move it on its own."]);
                }

                // ⚠ A transfer to the place it is leaving from moves nothing.
                if ($kind === AssetMovementKind::Transfer && $from['office_id'] === $destination['office_id'] && $from['warehouse_id'] === $destination['warehouse_id']) {
                    throw ValidationException::withMessages(['lines' => "{$item->tag} is already at that place."]);
                }

                if (! $item->isIndividual()) {
                    $held = $this->holdingsOf($item)[AssetHolding::keyFor($from['office_id'], $from['warehouse_id'], $from['employee_id'], $from['party_id'])] ?? 0;

                    if ($quantity < 1) {
                        throw ValidationException::withMessages(['lines' => "Say how many of {$item->tag} move."]);
                    }

                    if ($quantity > $held) {
                        throw ValidationException::withMessages(['lines' => "{$item->tag} has only {$held} there, not {$quantity}."]);
                    }
                }
            }

            $movement = AssetMovement::create([
                'business_id' => $businessId,
                'number' => $this->nextNumber($businessId),
                'kind' => $kind,
                'status' => $kind->isTwoStep() ? AssetMovementStatus::Dispatched : AssetMovementStatus::Completed,
                'from_office_id' => $origin['office_id'],
                'from_warehouse_id' => $origin['warehouse_id'],
                'from_employee_id' => $origin['employee_id'],
                'from_party_id' => $origin['party_id'],
                'to_office_id' => $destination['office_id'],
                'to_warehouse_id' => $destination['warehouse_id'],
                'to_employee_id' => $destination['employee_id'],
                'to_party_id' => $destination['party_id'],
                'moved_on' => $movedOn,
                'expected_back_on' => $expectedBack,
                'note' => $note,
                // ⚠ The ACTOR, kept apart from the from and to custodians: who
                // recorded the hand-over is a third fact, never inferred from
                // either side of it (review 13 Oct 2026, ruling 9).
                'created_by' => $by->id,
            ]);

            foreach ($lines as $line) {
                /** @var AssetItem $item */
                $item = $items[$line['item']->id];
                $quantity = $item->isIndividual() ? 1 : (int) $line['quantity'];
                $condition = AssetCondition::tryFrom((string) ($line['condition'] ?? ''));

                $movement->lines()->create([
                    'asset_item_id' => $item->id,
                    'quantity' => $quantity,
                    'condition' => $condition,
                ]);

                $this->apply($movement, $item, $quantity, $condition, $movedOn);
            }

            return $movement->fresh('lines');
        });

        $this->tellTheReceiver($movement);

        return $movement;
    }

    /**
     * The employee a thing was handed or passed to hears about it (ruling 9):
     * the movement is the keeper's record, the acknowledgement in My assets is
     * the receiver's, and the bell is what connects the two. One message per
     * document, after it is committed; an employee with no login hears nothing.
     */
    private function tellTheReceiver(AssetMovement $movement): void
    {
        if (! in_array($movement->kind, [AssetMovementKind::Issue, AssetMovementKind::Reassign], true)) {
            return;
        }

        $user = $movement->toEmployee?->user;

        if ($user === null) {
            return;
        }

        $lines = $movement->lines->load('item:id,tag,name');
        $first = $lines->first()?->item;
        $things = trim(($first?->tag ?? '').' '.($first?->name ?? ''));

        if ($lines->count() > 1) {
            $things .= ' and '.($lines->count() - 1).' more';
        }

        $user->notify(new AssetHandedOverNotification($things, $movement->number));
    }

    /**
     * The other place received a transfer: the things are there now.
     *
     * @throws ValidationException
     */
    public function receive(AssetMovement $movement, User $by): AssetMovement
    {
        return DB::transaction(function () use ($movement, $by) {
            /** @var AssetMovement $locked */
            $locked = AssetMovement::query()->withoutGlobalScopes()->whereKey($movement->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== AssetMovementStatus::Dispatched) {
                throw ValidationException::withMessages(['movement' => "{$locked->number} has already been received."]);
            }

            foreach ($locked->lines()->with('item')->get() as $line) {
                /** @var AssetItem $item */
                $item = AssetItem::query()->withoutGlobalScopes()->whereKey($line->asset_item_id)->lockForUpdate()->firstOrFail();

                // ⚠ A tagged thing, or a whole counted group, now lives at the
                // other place. Part of a group arriving leaves the bulk's row alone.
                if ($item->isIndividual() || $item->transit_movement_id === $locked->id) {
                    $item->forceFill([
                        'office_id' => $locked->to_office_id,
                        'warehouse_id' => $locked->to_warehouse_id,
                        'area' => null,
                        'transit_movement_id' => null,
                    ])->save();
                }

                if (! $item->isIndividual()) {
                    $this->addHolding($item, $locked->to_office_id, $locked->to_warehouse_id, null, null, $line->quantity);
                }

                activity('assets.items')->performedOn($item)->causedBy($by)->log("Received at {$locked->toLabel()} ({$locked->number})");
            }

            $locked->forceFill(['status' => AssetMovementStatus::Completed, 'received_by' => $by->id, 'received_at' => Carbon::now()])->save();

            return $locked->fresh('lines');
        });
    }

    /**
     * Where one line goes: custody, place, status and holdings.
     */
    private function apply(AssetMovement $movement, AssetItem $item, int $quantity, ?AssetCondition $condition, Carbon $movedOn): void
    {
        $kind = $movement->kind;
        $values = [];

        if ($condition !== null) {
            $values['condition'] = $condition;
        }

        if ($item->isIndividual()) {
            $values += match ($kind) {
                AssetMovementKind::Issue => [
                    'custodian_employee_id' => $movement->to_employee_id, 'custodian_party_id' => null,
                    'custody_since' => $movedOn, 'acknowledged_at' => null, 'status' => AssetStatus::InUse,
                ],
                AssetMovementKind::Return => [
                    'custodian_employee_id' => null, 'custodian_party_id' => null, 'custody_since' => null, 'acknowledged_at' => null,
                    'office_id' => $movement->to_office_id, 'warehouse_id' => $movement->to_warehouse_id,
                    'status' => AssetStatus::InStore,
                ],
                AssetMovementKind::SendOut => [
                    'custodian_employee_id' => null, 'custodian_party_id' => $movement->to_party_id,
                    'custody_since' => $movedOn, 'acknowledged_at' => null,
                    'status' => $movement->toParty->kind->statusWhileHolding(),
                ],
                AssetMovementKind::ReceiveBack => [
                    'custodian_employee_id' => null, 'custodian_party_id' => null, 'custody_since' => null, 'acknowledged_at' => null,
                    'office_id' => $movement->to_office_id, 'warehouse_id' => $movement->to_warehouse_id,
                    'status' => AssetStatus::InStore,
                ],
                AssetMovementKind::Transfer => [
                    'transit_movement_id' => $movement->id, 'status' => AssetStatus::InStore,
                ],
                // ⚠ The new holder has to say "yes, I have it" again.
                AssetMovementKind::Reassign => [
                    'custodian_employee_id' => $movement->to_employee_id, 'custodian_party_id' => null,
                    'custody_since' => $movedOn, 'acknowledged_at' => null, 'status' => AssetStatus::InUse,
                ],
            };
        } else {
            // A counted group: pieces leave one holder and reach another.
            $atPlace = $this->holdingsOf($item)[AssetHolding::keyFor($item->office_id, $item->warehouse_id, null, null)] ?? 0;

            $this->addHolding($item, $movement->from_office_id, $movement->from_warehouse_id, $movement->from_employee_id, $movement->from_party_id, -$quantity);

            if (! $kind->isTwoStep()) {
                $this->addHolding($item, $movement->to_office_id, $movement->to_warehouse_id, $movement->to_employee_id, $movement->to_party_id, $quantity);
            }

            // ⚠ The group's own row says where its bulk sits. A transfer of every
            // piece AT THE PLACE moves it (pieces with people stay theirs); a part
            // leaves it.
            if ($kind === AssetMovementKind::Transfer && $quantity === $atPlace) {
                $values['transit_movement_id'] = $movement->id;
            }
        }

        $item->forceFill($values)->save();

        activity('assets.items')->performedOn($item)->causedBy($movement->createdBy)
            ->log($kind->label().': '.$movement->fromLabel().' to '.$movement->toLabel().' ('.$movement->number.')');
    }

    /**
     * Where a thing is coming from, by what the register says, or a refusal.
     *
     * @param  array{office_id: int|null, warehouse_id: int|null, employee_id: int|null, party_id: int|null}  $destination
     * @return array{office_id: int|null, warehouse_id: int|null, employee_id: int|null, party_id: int|null}
     */
    private function origin(AssetMovementKind $kind, AssetItem $item, array $destination): array
    {
        $place = ['office_id' => $item->office_id, 'warehouse_id' => $item->warehouse_id, 'employee_id' => null, 'party_id' => null];

        if ($item->isIndividual()) {
            $from = match ($kind) {
                AssetMovementKind::Issue, AssetMovementKind::Transfer => $this->mustBeNobodys($item, $place),
                AssetMovementKind::SendOut => $this->leavingForOutside($item, $destination, $place),
                AssetMovementKind::Return, AssetMovementKind::Reassign => $this->mustBeHeldBy($item, $destination, 'employee'),
                AssetMovementKind::ReceiveBack => $this->mustBeHeldBy($item, $destination, 'party'),
            };
        } else {
            // A counted group: the pieces come from the holder named (return or
            // reassign: that employee; receive back: that party; send out: that
            // employee when one is named; otherwise the place named, or the
            // group's own place when none is).
            $from = match ($kind) {
                AssetMovementKind::Return, AssetMovementKind::Reassign => $this->holderOf($item, ['employee_id' => $this->returningEmployee($destination), 'party_id' => null]),
                AssetMovementKind::ReceiveBack => $this->holderOf($item, ['employee_id' => null, 'party_id' => $this->returningParty($destination)]),
                AssetMovementKind::SendOut => filled($destination['from_employee_id'] ?? null)
                    ? $this->holderOf($item, ['employee_id' => (int) $destination['from_employee_id'], 'party_id' => null])
                    : $this->placeFrom($item, $destination, $place),
                default => $this->placeFrom($item, $destination, $place),
            };
        }

        // ⚠ Handing a thing to the person who already has it moves nothing.
        if ($kind === AssetMovementKind::Reassign && $from['employee_id'] === $destination['employee_id']) {
            throw ValidationException::withMessages(['lines' => "{$item->tag} is already with that person. Choose a different one."]);
        }

        return $from;
    }

    /**
     * Where a tagged thing leaves from on its way to an outside party: the
     * employee holding it, or its place when nobody does. Never a party: a
     * repair shop does not pass a thing on to a freelancer on our books.
     *
     * @param  array{office_id: int|null, warehouse_id: int|null, employee_id: int|null, party_id: int|null, from_employee_id?: int}  $destination
     * @param  array{office_id: int|null, warehouse_id: int|null, employee_id: int|null, party_id: int|null}  $place
     * @return array{office_id: int|null, warehouse_id: int|null, employee_id: int|null, party_id: int|null}
     */
    private function leavingForOutside(AssetItem $item, array $destination, array $place): array
    {
        if ($item->custodian_party_id !== null) {
            throw ValidationException::withMessages(['lines' => "{$item->tag} is with {$item->custodyLabel()}. Receive it back first."]);
        }

        if ($item->custodian_employee_id === null) {
            return $place;
        }

        $named = (int) ($destination['from_employee_id'] ?? 0);

        if ($named > 0 && $named !== $item->custodian_employee_id) {
            throw ValidationException::withMessages(['lines' => "{$item->tag} is with {$item->custodyLabel()}, not with the employee chosen."]);
        }

        return ['office_id' => null, 'warehouse_id' => null, 'employee_id' => $item->custodian_employee_id, 'party_id' => null];
    }

    /**
     * @param  array{office_id: int|null, warehouse_id: int|null, employee_id: int|null, party_id: int|null}  $place
     * @return array{office_id: int|null, warehouse_id: int|null, employee_id: int|null, party_id: int|null}
     */
    private function mustBeNobodys(AssetItem $item, array $place): array
    {
        if ($item->custodian_employee_id !== null || $item->custodian_party_id !== null) {
            throw ValidationException::withMessages(['lines' => "{$item->tag} is with {$item->custodyLabel()}. Take it back first."]);
        }

        return $place;
    }

    /**
     * @param  array{office_id: int|null, warehouse_id: int|null, employee_id: int|null, party_id: int|null}  $destination
     * @return array{office_id: int|null, warehouse_id: int|null, employee_id: int|null, party_id: int|null}
     */
    private function mustBeHeldBy(AssetItem $item, array $destination, string $who): array
    {
        if ($who === 'employee') {
            if ($item->custodian_employee_id === null) {
                throw ValidationException::withMessages(['lines' => "{$item->tag} is not with an employee ({$item->custodyLabel()})."]);
            }

            // ⚠⚠ A STALE SCREEN. The form says who it is taking the thing from;
            // if the register says someone else holds it now (returned and
            // re-issued since the page was opened), the hand-over is refused
            // rather than quietly taken from the new holder (ruling 9).
            $named = (int) ($destination['from_employee_id'] ?? 0);

            if ($named > 0 && $named !== $item->custodian_employee_id) {
                throw ValidationException::withMessages(['lines' => "{$item->tag} is with {$item->custodyLabel()}, not with the employee chosen. Reload and look again."]);
            }

            return ['office_id' => null, 'warehouse_id' => null, 'employee_id' => $item->custodian_employee_id, 'party_id' => null];
        }

        if ($item->custodian_party_id === null) {
            throw ValidationException::withMessages(['lines' => "{$item->tag} is not with an outside party ({$item->custodyLabel()})."]);
        }

        return ['office_id' => null, 'warehouse_id' => null, 'employee_id' => null, 'party_id' => $item->custodian_party_id];
    }

    /**
     * @param  array{employee_id: int|null, party_id: int|null}  $holder
     * @return array{office_id: int|null, warehouse_id: int|null, employee_id: int|null, party_id: int|null}
     */
    private function holderOf(AssetItem $item, array $holder): array
    {
        $from = ['office_id' => null, 'warehouse_id' => null] + $holder;
        $key = AssetHolding::keyFor(null, null, $holder['employee_id'], $holder['party_id']);

        if (($this->holdingsOf($item)[$key] ?? 0) < 1) {
            throw ValidationException::withMessages(['lines' => "{$item->tag}: that holder has none of it."]);
        }

        return $from;
    }

    /**
     * On a return of a counted group the form names the employee giving it back
     * as `from_employee_id`, carried inside `$to` for the one call. Same for a party.
     *
     * @param  array<string, mixed>  $destination
     */
    private function returningEmployee(array $destination): int
    {
        $id = (int) ($destination['from_employee_id'] ?? 0);

        if ($id < 1) {
            throw ValidationException::withMessages(['from_employee_id' => 'Say which employee is returning it.']);
        }

        return $id;
    }

    /**
     * @param  array<string, mixed>  $destination
     */
    private function returningParty(array $destination): int
    {
        $id = (int) ($destination['from_party_id'] ?? 0);

        if ($id < 1) {
            throw ValidationException::withMessages(['from_party_id' => 'Say which outside party is giving it back.']);
        }

        return $id;
    }

    /**
     * Where a counted group's pieces leave from: the place the form named
     * (pieces may sit at a second place after a part-transfer), else its own.
     *
     * @param  array<string, mixed>  $destination
     * @param  array{office_id: int|null, warehouse_id: int|null, employee_id: int|null, party_id: int|null}  $home
     * @return array{office_id: int|null, warehouse_id: int|null, employee_id: int|null, party_id: int|null}
     */
    private function placeFrom(AssetItem $item, array $destination, array $home): array
    {
        $officeId = filled($destination['from_office_id'] ?? null) ? (int) $destination['from_office_id'] : null;
        $warehouseId = filled($destination['from_warehouse_id'] ?? null) ? (int) $destination['from_warehouse_id'] : null;

        if ($officeId === null && $warehouseId === null) {
            return $home;
        }

        $from = ['office_id' => $officeId, 'warehouse_id' => $warehouseId, 'employee_id' => null, 'party_id' => null];

        if (($this->holdingsOf($item)[AssetHolding::keyFor($officeId, $warehouseId, null, null)] ?? 0) < 1) {
            throw ValidationException::withMessages(['lines' => "{$item->tag}: none of it is at that place."]);
        }

        return $from;
    }

    private function refuseIfStuck(AssetItem $item): void
    {
        if ($item->transit_movement_id !== null) {
            throw ValidationException::withMessages(['lines' => "{$item->tag} is on its way to another place. Receive it there first."]);
        }

        if (in_array($item->status, [AssetStatus::Disposed, AssetStatus::Missing], true)) {
            throw ValidationException::withMessages(['lines' => "{$item->tag} is {$item->status->label()} and cannot be moved."]);
        }
    }

    /**
     * Who or where the things go, checked against the business.
     *
     * @param  array<string, mixed>  $to
     * @return array{office_id: int|null, warehouse_id: int|null, employee_id: int|null, party_id: int|null, from_employee_id?: int, from_party_id?: int}
     */
    private function destination(AssetMovementKind $kind, array $to, Business $business): array
    {
        $employeeId = filled($to['employee_id'] ?? null) ? (int) $to['employee_id'] : null;
        $partyId = filled($to['party_id'] ?? null) ? (int) $to['party_id'] : null;
        $officeId = filled($to['office_id'] ?? null) ? (int) $to['office_id'] : null;
        $warehouseId = filled($to['warehouse_id'] ?? null) ? (int) $to['warehouse_id'] : null;

        $needsEmployee = in_array($kind, [AssetMovementKind::Issue, AssetMovementKind::Reassign], true);
        $needsParty = $kind === AssetMovementKind::SendOut;
        $needsPlace = in_array($kind, [AssetMovementKind::Return, AssetMovementKind::ReceiveBack, AssetMovementKind::Transfer], true);

        if ($needsEmployee) {
            if ($employeeId === null || ! $this->employeeWorksIn($employeeId, $business)) {
                throw ValidationException::withMessages(['employee_id' => 'Choose an employee of this business.']);
            }

            return $this->withFrom(['office_id' => null, 'warehouse_id' => null, 'employee_id' => $employeeId, 'party_id' => null], $to);
        }

        if ($needsParty) {
            if ($partyId === null || ! AssetParty::query()->withoutGlobalScopes()->whereKey($partyId)->where('business_id', $business->id)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['party_id' => 'Choose an outside party of this business.']);
            }

            return $this->withFrom(['office_id' => null, 'warehouse_id' => null, 'employee_id' => null, 'party_id' => $partyId], $to);
        }

        if ($needsPlace) {
            if (($officeId === null) === ($warehouseId === null)) {
                throw ValidationException::withMessages(['location' => 'Choose where it goes: one office or one warehouse.']);
            }

            $exists = $officeId !== null
                ? Office::query()->withoutGlobalScopes()->whereKey($officeId)->where('business_id', $business->id)->where('is_active', true)->exists()
                : Warehouse::query()->withoutGlobalScopes()->whereKey($warehouseId)->where('business_id', $business->id)->where('is_active', true)->exists();

            if (! $exists) {
                throw ValidationException::withMessages(['location' => 'That place is not an active office or warehouse of this business.']);
            }

            return $this->withFrom(['office_id' => $officeId, 'warehouse_id' => $warehouseId, 'employee_id' => null, 'party_id' => null], $to);
        }

        throw ValidationException::withMessages(['kind' => 'Unknown movement.']);
    }

    /**
     * Carry who or where the things leave from, when the form names it: the
     * employee returning or passing on a counted group's pieces, the party
     * giving them back, or a second place where some of them sit.
     *
     * @param  array<string, mixed>  $destination
     * @param  array<string, mixed>  $to
     * @return array<string, mixed>
     */
    private function withFrom(array $destination, array $to): array
    {
        foreach (['from_office_id', 'from_warehouse_id', 'from_employee_id', 'from_party_id'] as $key) {
            if (filled($to[$key] ?? null)) {
                $destination[$key] = (int) $to[$key];
            }
        }

        return $destination;
    }

    /**
     * Who may receive a thing: an ACTIVE employee assigned to this business
     * (review 13 Oct 2026, ruling 9). Not a draft never confirmed, not someone
     * suspended, not a leaver: none of them can sign for it. The screens list
     * the same people, through `receivers()`, so the form never offers someone
     * the rule then refuses.
     */
    private function employeeWorksIn(int $employeeId, Business $business): bool
    {
        return self::receivers($business->id)->whereKey($employeeId)->exists();
    }

    /**
     * @return Builder<Employee>
     */
    public static function receivers(int $businessId): Builder
    {
        return Employee::query()->withoutGlobalScopes()
            ->where('status', EmployeeStatus::Active->value)
            ->whereHas('assignments', fn ($query) => $query->withoutGlobalScopes()->where('business_id', $businessId));
    }

    /**
     * @return array<string, int>
     */
    private function holdingsOf(AssetItem $item): array
    {
        return AssetHolding::query()->withoutGlobalScopes()->where('asset_item_id', $item->id)->pluck('quantity', 'holder_key')->all();
    }

    private function addHolding(AssetItem $item, ?int $officeId, ?int $warehouseId, ?int $employeeId, ?int $partyId, int $delta): void
    {
        $key = AssetHolding::keyFor($officeId, $warehouseId, $employeeId, $partyId);

        /** @var AssetHolding $holding */
        $holding = AssetHolding::query()->withoutGlobalScopes()->firstOrCreate(
            ['asset_item_id' => $item->id, 'holder_key' => $key],
            ['business_id' => $item->business_id, 'office_id' => $officeId, 'warehouse_id' => $warehouseId, 'employee_id' => $employeeId, 'party_id' => $partyId, 'quantity' => 0],
        );

        $next = $holding->quantity + $delta;

        if ($next < 0) {
            throw ValidationException::withMessages(['lines' => "{$item->tag}: not that many there."]);
        }

        if ($next === 0) {
            $holding->delete();
        } else {
            $holding->forceFill(['quantity' => $next])->save();
        }
    }

    private function nextNumber(int $businessId): string
    {
        // ⚠ One more than the highest number of this business, read under the
        // items' locks; the unique index on (business, number) is the backstop.
        $last = (int) AssetMovement::query()->withoutGlobalScopes()
            ->where('business_id', $businessId)
            ->selectRaw('max(cast(substr(number, 4) as signed)) as n')
            ->value('n');

        return sprintf('AM-%06d', $last + 1);
    }
}
