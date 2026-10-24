<?php

namespace App\Modules\Crm\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ Corrections wave 3 (24 Oct 2026): has anything in the business been
 * recorded against this customer (or supplier) yet? Reverse conversion is
 * allowed only while the answer is no (FINAL-PLAN 3.2, "if the new customer
 * is unused").
 *
 * ⚠⚠ Read from the SCHEMA, not from a hand-kept list: every table with a
 * `customer_id` (or `supplier_id`) column is asked. Sales orders, quotations,
 * challans, invoices, receipts, returns, parcels, journal lines, set-offs,
 * prices and whatever is added next year all count without anybody
 * remembering to add them here, so a new document type can never be left
 * pointing at a customer that a reversal switched off. A list would be right
 * the day it was written and wrong the day after.
 *
 * ⚠ The lead table itself is left out (`leads` points at the party through
 * converted_customer_id, which is the very link being undone).
 */
class PartyUsage
{
    /** Tables that are the conversion itself, not a use of the party. */
    private const IGNORED = ['leads'];

    /** @var array<string, list<string>> */
    private static array $tables = [];

    /**
     * The tables (in words) that hold a row for this party, first few only.
     *
     * @param  'customer_id'|'supplier_id'  $column
     * @return list<string>
     */
    public function usedIn(string $column, int $partyId, int $limit = 3): array
    {
        $found = [];

        foreach ($this->tablesWith($column) as $table) {
            if (DB::table($table)->where($column, $partyId)->exists()) {
                $found[] = str_replace('_', ' ', $table);

                if (count($found) >= $limit) {
                    break;
                }
            }
        }

        return $found;
    }

    /** @return list<string> */
    private function tablesWith(string $column): array
    {
        $key = DB::connection()->getName().':'.$column;

        if (! isset(self::$tables[$key])) {
            $tables = [];

            // ⚠ This database only: on MySQL a null schema lists every database
            // on the server, and a test or a second copy could answer for it.
            foreach (Schema::getTableListing(Schema::getCurrentSchemaListing(), false) as $table) {
                if (in_array($table, self::IGNORED, true)) {
                    continue;
                }

                if (Schema::hasColumn($table, $column)) {
                    $tables[] = $table;
                }
            }

            sort($tables);
            self::$tables[$key] = $tables;
        }

        return self::$tables[$key];
    }

    /** Tests create tables; a cached list from an earlier test would miss them. */
    public static function forget(): void
    {
        self::$tables = [];
    }
}
