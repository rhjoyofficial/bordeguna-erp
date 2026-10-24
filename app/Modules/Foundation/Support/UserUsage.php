<?php

namespace App\Modules\Foundation\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Does anything point at this login?" for Delete login (corrections wave 3,
 * B11, 24 Oct 2026; FINAL-PLAN 6.4: used = anything points at it).
 *
 * ⚠⚠ READ FROM THE DATABASE'S OWN FOREIGN KEYS, not from a hand-kept list:
 * 255 models and growing, and a list goes stale the day a module adds a
 * `created_by`. Every column with a foreign key to `users.id` is asked once.
 * A column without a key is not seen, which is why Delete login is offered
 * only for a login that never signed in (it cannot have made anything) and
 * this is the second net, not the first.
 *
 * Membership and access rows are not "use": they go with the login.
 */
final class UserUsage
{
    /** Rows that belong to the login itself, not records it touched. */
    private const OWN_TABLES = [
        'users', 'sessions', 'password_reset_tokens', 'business_user', 'model_has_roles',
        'model_has_permissions', 'personal_access_tokens', 'notifications',
    ];

    /** @var list<array{0: string, 1: string}>|null */
    private static ?array $columns = null;

    /** The first table that points at the login, in words, or null. */
    public function firstUse(User $user): ?string
    {
        foreach ($this->columns() as [$table, $column]) {
            if (DB::table($table)->where($column, $user->id)->exists()) {
                return str_replace('_', ' ', $table);
            }
        }

        return null;
    }

    /** @return list<array{0: string, 1: string}> every (table, column) with a key to users.id */
    private function columns(): array
    {
        if (self::$columns !== null) {
            return self::$columns;
        }

        $found = [];

        if (DB::connection()->getDriverName() === 'mysql') {
            $rows = DB::select(
                'select TABLE_NAME as t, COLUMN_NAME as c from information_schema.KEY_COLUMN_USAGE where TABLE_SCHEMA = database() and REFERENCED_TABLE_NAME = ? and REFERENCED_COLUMN_NAME = ?',
                ['users', 'id'],
            );

            foreach ($rows as $row) {
                $found[] = [(string) $row->t, (string) $row->c];
            }
        } else {
            foreach (Schema::getTables() as $table) {
                foreach (Schema::getForeignKeys($table['name']) as $key) {
                    if ($key['foreign_table'] === 'users' && ($key['foreign_columns'] ?? []) === ['id'] && count($key['columns']) === 1) {
                        $found[] = [$table['name'], $key['columns'][0]];
                    }
                }
            }
        }

        return self::$columns = array_values(array_filter($found, fn (array $pair) => ! in_array($pair[0], self::OWN_TABLES, true)));
    }
}
