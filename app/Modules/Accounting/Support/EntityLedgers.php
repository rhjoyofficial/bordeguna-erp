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
 * ⚠⚠ THE HEAD IS A NON-POSTABLE DISPLAY GROUPING, NOT A LEDGER (revised 28 Sep
 * 2026). Money for an entity posts to its CONTROL account with the entity as the
 * party, exactly as it did before this feature - that is what the customer
 * statement, receivables/payables ageing, employee statement and the R7
 * subledger reconciliation all read. The named head only makes the chart read
 * grouped ("1140-317 Rahim" under Accounts Receivable); it holds no balance.
 *
 * ⚠⚠ IT IS DELIBERATELY `is_postable = false`. The original design meant it to
 * be pickable, with PostJournal redirecting the pick to control + party the way
 * a money head redirects to its money account - but that redirect was never
 * built, so a posting landed DIRECTLY on the head and vanished from every
 * party-based report and from reconciliation. Non-postable closes that hole with
 * no ambiguity (a heading cannot receive a posting, and the pickers already omit
 * it), and it does so regardless of which control account the head sits under -
 * a mapping the entity/party slugs currently disagree on (heads group under
 * accounts_receivable/accounts_payable, while the party-kept slugs are
 * customer_advances/supplier_advances). Making the head pickable-and-redirected
 * is a separate feature that must first settle that mapping; until then, pick the
 * control account and choose the party, which has always worked.
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
                // ⚠⚠ A DISPLAY HEADING, NOT A LEDGER. Non-postable so nothing can
                // land on it and bypass the party-based subledger; see the class
                // docblock. The per-entity balance comes from the control account
                // filtered by party, not from this head.
                'is_postable' => false,
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
