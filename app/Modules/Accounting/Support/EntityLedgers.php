<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Models\Business;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ EVERY CUSTOMER, SUPPLIER AND EMPLOYEE HAS ITS OWN NAMED LEDGER HEAD (Joy,
 * 27 Sep 2026), the same way every bank and wallet already does (MoneyLedgers).
 *
 * The head sits under the entity's control account (customers under 1140
 * Accounts Receivable, suppliers under 2110 Accounts Payable, employees under
 * 1170 Employee Advances) so the chart reads grouped. It is created BY THE
 * SYSTEM when the entity is saved, never hand-picked, so it cannot be named
 * generically or reused.
 *
 * ⚠ Interpretation B (his choice): the head is what a person SEES and PICKS, but
 * the money still posts to the control account with the entity as the party
 * (PostJournal resolves the head back to control + party, exactly as it resolves
 * a money head to its money account). So the head carries no balance of its own
 * and reconciliation is untouched; the per-entity view already comes from the
 * party ledger. This service only ensures the head exists and stays named right.
 *
 * ⚠ Names repeat (two customers "Rahim", the same name in two businesses), so
 * the KEY is the entity's `account_id`, never the name. The code embeds the
 * entity id/code because the chart has thousands of these and the 99-slot money
 * scheme would not hold them.
 */
final class EntityLedgers
{
    /**
     * entity class => [control-account slug it groups under, chart type].
     */
    private const GROUPS = [
        Customer::class => ['accounts_receivable', 'asset'],
        Supplier::class => ['accounts_payable', 'liability'],
        Employee::class => ['employee_advances', 'asset'],
    ];

    /** The entity types that carry a head of their own. */
    public static function handles(Model $entity): bool
    {
        return array_key_exists($entity::class, self::GROUPS);
    }

    /**
     * Give the entity the head it reports under, creating it or renaming it to
     * match. Returns the head, or null for an entity type that has none.
     * Idempotent: safe to call on every save and in a back-fill.
     */
    public static function ensureOwnLedger(Model $entity): ?Account
    {
        if (! self::handles($entity)) {
            return null;
        }

        [$slug, $type] = self::GROUPS[$entity::class];

        return DB::transaction(function () use ($entity, $slug, $type) {
            $group = Account::bySlug($slug);

            if ($group === null) {
                // No chart yet (a fresh install, or a test that never seeded it):
                // there is nowhere to hang the head, so do nothing. The backfill
                // command and the next save will create it once the chart exists.
                return null;
            }

            $name = self::headName($entity);
            $existing = $entity->account_id === null ? null : Account::find($entity->account_id);

            if ($existing !== null) {
                if ($existing->name !== $name) {
                    $existing->update(['name' => $name]);
                }

                return $existing;
            }

            $head = new Account([
                'code' => self::code($group, $entity),
                'name' => $name,
                'type' => $type,
                'parent_id' => $group->id,
                // Pickable on a voucher, like a money head; PostJournal redirects
                // it to the control account + party, so nothing lands on it.
                'is_postable' => true,
            ]);
            $head->is_active = true;
            $head->save();

            // ⚠ Save straight to the column without firing model events again:
            // the observer that called this is inside the entity's own save.
            $entity->forceFill(['account_id' => $head->id])->saveQuietly();

            return $head;
        });
    }

    /**
     * The head's name in the shared chart. Customers and suppliers are per
     * business, so the business is appended (four businesses see one chart and
     * two may both deal with "Rahim"); employees are central and carry their
     * unique code instead.
     */
    public static function headName(Model $entity): string
    {
        if ($entity instanceof Employee) {
            return "{$entity->fullName()} ({$entity->employee_code})";
        }

        $business = Business::withoutGlobalScopes()->find($entity->business_id);
        $name = $entity->name;

        return $business === null ? $name : "{$name} - {$business->name}";
    }

    /**
     * A unique, chart-order-sane code: the control account's code, a dash, and
     * the entity's own unique key. "1140-317" sorts right after 1140 and before
     * 1141, the same idea as the money heads sorting under their group.
     */
    private static function code(Account $group, Model $entity): string
    {
        $key = $entity instanceof Employee ? $entity->employee_code : (string) $entity->getKey();

        return "{$group->code}-{$key}";
    }
}
