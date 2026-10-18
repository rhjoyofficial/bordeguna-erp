<?php

namespace App\Modules\Foundation\Support\Ai;

use App\Models\User;

/**
 * Which tables the assistant may read FOR THIS PERSON, and how each is scoped.
 *
 * ⚠⚠ THIS CLASS IS THE BARRIER THAT ANSWERS "can access but not over rule".
 * Everything else in the data assistant is plumbing; this is the part that
 * decides whether a warehouse hand can read the payroll. Read it before
 * changing anything here, and change it deliberately.
 *
 * ⚠⚠ A READ-ONLY DATABASE CONNECTION DOES NOT DO THIS JOB. On a read-only
 * user `SELECT * FROM payslips` succeeds perfectly - nothing written, nothing
 * updated, and everybody's pay disclosed. Read-only stops WRITING. This class
 * is what stops READING, and the two problems are unrelated.
 *
 * ⚠⚠ DEFAULT CLOSED, exactly like FileVisibility. A table absent from the map
 * below is readable by NOBODY, including an Owner. When a future module adds a
 * table and nobody remembers this file, its data stays invisible rather than
 * quietly joining the pool the assistant can draw on.
 *
 * ⚠ Keyed on PERMISSIONS, never on role names. A role is a bundle somebody can
 * edit on a settings screen; the permission is what every other screen in the
 * system actually checks, so the assistant answers exactly what the matching
 * report would have answered.
 */
class ReadableData
{
    /**
     * Table => the permission that already guards that data, plus the columns
     * the assistant may see and how the table is scoped.
     *
     * ⚠ `owner` names a column that must equal the ASKER's user id. It is how a
     * personal table stays personal even for somebody who may read the table.
     *
     * ⚠⚠ COLUMNS ARE LISTED, NEVER `*`. A new column added by a later migration
     * must be added here on purpose. That is the whole point: somebody adding
     * `national_id` to a table should have to decide, in this file, that the
     * assistant may read it.
     *
     * ⚠⚠ `shared` MARKS A TABLE THAT IS NOT BUSINESS-SCOPED, and it is opt-in
     * for a reason. The default is scoped, so a table added here without
     * thinking gets the business filter and returns nothing rather than
     * returning another tenant's rows. Only set it where the migration really
     * has no `business_id` column, and say why.
     *
     * ⚠⚠ 14 Oct 2026, MySQL defect: seven columns listed here never existed
     * (products.unit, stock_batches.quantity, sales_invoices.status,
     * sales_orders.order_date and .total, purchase_orders.order_date and
     * .total). SQLite reads an unknown double-quoted name as a string literal,
     * so the suite passed; MySQL refuses the query, so in production every
     * question about products, stock, invoices, sales orders or purchase orders
     * answered "That lookup did not work". Each now names the real column, or
     * is dropped where the table has none. ReadableDataColumnsTest holds it.
     *
     * @var array<string,array{permission:string, columns:list<string>, owner?:string, label:string, shared?:bool}>
     */
    private const TABLES = [
        // ── Inventory ────────────────────────────────────────────────────────
        'products' => [
            'permission' => 'inventory.view',
            'label' => 'Products and SKUs',
            'columns' => ['id', 'sku', 'name', 'type', 'unit_of_measure_id', 'is_active', 'barcode', 'brand_id', 'created_at'],
        ],
        'warehouses' => [
            'permission' => 'inventory.view',
            'label' => 'Warehouses',
            'columns' => ['id', 'code', 'name', 'is_active'],
        ],
        'stock_batches' => [
            'permission' => 'inventory.view',
            'label' => 'Stock held, by batch',
            'columns' => ['id', 'product_id', 'warehouse_id', 'current_quantity', 'expiry_date', 'status', 'created_at'],
        ],
        'stock_movements' => [
            'permission' => 'inventory.view',
            'label' => 'Stock movements',
            'columns' => ['id', 'product_id', 'warehouse_id', 'quantity', 'reason', 'created_at'],
        ],

        // ── Sales ────────────────────────────────────────────────────────────
        'customers' => [
            'permission' => 'sales.view',
            'label' => 'Customers',
            'columns' => ['id', 'code', 'name', 'type', 'phone', 'payment_terms', 'credit_limit', 'is_active'],
        ],
        'sales_invoices' => [
            'permission' => 'sales.view',
            'label' => 'Customer invoices',
            'columns' => ['id', 'invoice_number', 'customer_id', 'invoice_date', 'due_date', 'total'],
        ],
        'sales_orders' => [
            'permission' => 'sales.view',
            'label' => 'Sales orders',
            'columns' => ['id', 'order_number', 'customer_id', 'ordered_at', 'status'],
        ],

        // ── Purchasing ───────────────────────────────────────────────────────
        'suppliers' => [
            'permission' => 'purchasing.view',
            'label' => 'Suppliers',
            'columns' => ['id', 'code', 'name', 'phone', 'is_active'],
        ],
        'purchase_orders' => [
            'permission' => 'purchasing.view',
            'label' => 'Purchase orders',
            'columns' => ['id', 'po_number', 'supplier_id', 'order_date', 'created_at', 'status'],
        ],

        // ── Accounting ───────────────────────────────────────────────────────
        // ⚠ Accounts and journals only. The assistant reads the ledger; it does
        // not get the money accounts' balances by another name.
        // ⚠⚠ SHARED, NOT SCOPED. One chart of accounts serves all four
        // businesses (C-GL-1); the table has no `business_id` column at all.
        // Filtering it by business threw "Unknown column 'business_id'" on
        // EVERY question about accounts - which the assistant then reported to
        // an Owner as "your role does not include accounting". Found 30 Aug
        // 2026 on the first real run, by the suggestion chip the panel itself
        // offers. The chart is configuration and holds no amounts, so there is
        // nothing here that is one tenant's rather than another's.
        'accounts' => [
            'permission' => 'accounting.view',
            'label' => 'Chart of accounts',
            'shared' => true,
            'columns' => ['id', 'code', 'name', 'type', 'is_postable', 'is_active'],
        ],

        // ── The asker's own things ───────────────────────────────────────────
        // ⚠⚠ `owner` makes these personal even though the permission is broad.
        // Everybody may read notes; nobody may read anybody else's.
        'notes' => [
            'permission' => 'notes.view',
            'label' => 'Your own notes',
            'owner' => 'created_by',
            'columns' => ['id', 'title', 'body', 'is_pinned', 'remind_on', 'created_at'],
        ],
    ];

    /**
     * The tables this person may read, with their labels, for telling the model
     * what exists.
     *
     * @return array<string,array<string,mixed>>
     */
    public function forUser(User $user): array
    {
        $allowed = [];

        foreach (self::TABLES as $table => $spec) {
            if ($user->can($spec['permission'])) {
                $allowed[$table] = $spec;
            }
        }

        return $allowed;
    }

    /**
     * The spec for one table, or null if this person may not read it.
     *
     * ⚠⚠ EVERY QUERY GOES THROUGH HERE. A table the model names that this
     * person may not read returns null and the request is refused - which is
     * what makes an invented table name harmless rather than interesting.
     *
     * @return array<string,mixed>|null
     */
    public function spec(User $user, string $table): ?array
    {
        $spec = self::TABLES[$table] ?? null;

        if ($spec === null || ! $user->can($spec['permission'])) {
            return null;
        }

        return $spec;
    }

    /** @return list<string> every table in the map, for the drift test. */
    public static function tables(): array
    {
        return array_keys(self::TABLES);
    }

    /** @return list<string> every permission the map depends on. */
    public static function permissions(): array
    {
        return array_values(array_unique(array_column(self::TABLES, 'permission')));
    }
}
