<?php

namespace App\Modules\Accounting\Observers;

use App\Modules\Accounting\Support\EntityLedgers;
use App\Modules\Hrm\Models\Employee;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ 27 Sep 2026 (Joy): a new customer, supplier or employee gets its own ledger
 * head the moment it is created, whichever way in (a controller, an import, a
 * website order, a lead conversion). One observer covers every path, so no
 * create site can be forgotten.
 *
 * The head is renamed if the entity is renamed. EntityLedgers no-ops when the
 * chart is not seeded, so this never breaks entity creation in a context that
 * has no chart (a fresh install, or a test that does not seed accounting).
 */
class EntityLedgerObserver
{
    public function created(Model $entity): void
    {
        EntityLedgers::ensureOwnLedger($entity);
    }

    public function updated(Model $entity): void
    {
        if ($this->nameChanged($entity)) {
            EntityLedgers::ensureOwnLedger($entity);
        }
    }

    private function nameChanged(Model $entity): bool
    {
        return $entity instanceof Employee
            ? $entity->wasChanged(['first_name', 'last_name'])
            : $entity->wasChanged('name');
    }
}
