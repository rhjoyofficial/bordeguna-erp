<?php

namespace App\Modules\Purchasing\Support;

use App\Modules\Purchasing\Models\SupplierType;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The current business's supplier types: what a picker offers, what a form and
 * the supplier file accept, and the seven every business starts with.
 *
 * ⚠⚠ ONE place for the rule, used by the supplier form, the supplier import,
 * the TDS/VDS rate form and the Supplier types screen. A switched-off type is
 * not offered for anything new, but a supplier or rate that already carries it
 * keeps it: saving that record unchanged must never fail.
 */
final class SupplierTypes
{
    /**
     * The seven types every business starts with (finance decision C4). These
     * keys are what suppliers and rates stored before types became a list, so
     * they must never change.
     */
    public const DEFAULTS = [
        'goods' => 'Goods supplier',
        'service' => 'Service provider',
        'contractor' => 'Contractor',
        'transport' => 'Transport / courier',
        'landlord' => 'Landlord (rent)',
        'professional' => 'Professional / consultant',
        'other' => 'Other',
    ];

    /** Idempotent: unique on (business_id, key), so a renamed or switched-off type stays as it was left. */
    public static function seedFor(int $businessId): void
    {
        $now = now();
        $rows = [];
        $sort = 0;

        foreach (self::DEFAULTS as $key => $name) {
            $rows[] = ['business_id' => $businessId, 'key' => $key, 'name' => $name, 'is_active' => true, 'sort' => $sort++, 'created_at' => $now, 'updated_at' => $now];
        }

        DB::table('supplier_types')->insertOrIgnore($rows);
    }

    /**
     * Every type of the current business, switched off ones included, in list
     * order.
     *
     * ⚠ A business with no rows at all (created before anything seeded it)
     * reads the seven defaults rather than an empty list, so the forms keep
     * working; the Supplier types screen writes them in the first time it opens.
     *
     * @return Collection<int, SupplierType>
     */
    public static function all(): Collection
    {
        $types = SupplierType::query()->ordered()->get();

        if ($types->isNotEmpty()) {
            return $types;
        }

        $sort = 0;

        return collect(self::DEFAULTS)->map(fn (string $name, string $key) => new SupplierType(['key' => $key, 'name' => $name, 'is_active' => true, 'sort' => $sort++]))->values();
    }

    /**
     * What the pages send to a picker: every type with its state, so a record
     * on a switched-off type still shows its name. The picker offers the active
     * ones plus whatever the record already holds.
     *
     * @return list<array{key: string, name: string, active: bool}>
     */
    public static function options(): array
    {
        return self::all()->map(fn (SupplierType $t) => ['key' => $t->key, 'name' => $t->name, 'active' => (bool) $t->is_active])->values()->all();
    }

    /**
     * The keys a form may save: the active types, plus the one the record
     * already carries (a switched-off type stays on the record that has it).
     *
     * @return list<string>
     */
    public static function allowedKeys(?string $keep = null): array
    {
        return self::all()
            ->filter(fn (SupplierType $t) => $t->is_active || ($keep !== null && $t->key === $keep))
            ->pluck('key')->values()->all();
    }

    /** A validation rule for a `supplier_type` field; null and blank pass (the field is optional). */
    public static function rule(?string $keep = null): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($keep) {
            if ($value === null || $value === '') {
                return;
            }

            if (! in_array((string) $value, self::allowedKeys($keep), true)) {
                $fail('Choose one of your supplier types. Add new ones under Purchases > Suppliers > Supplier types.');
            }
        };
    }

    /** The type a file cell names, by key or by name, any case; null when there is none. */
    public static function find(string $given): ?SupplierType
    {
        $given = trim($given);

        return self::all()->first(fn (SupplierType $t) => strcasecmp($given, $t->key) === 0 || strcasecmp($given, $t->name) === 0);
    }

    /** The active keys, for a sentence that tells somebody what they may use. */
    public static function activeKeysSentence(): string
    {
        return implode(', ', self::all()->where('is_active', true)->pluck('key')->all());
    }

    /**
     * A key for a new type, made from its name: lower case, words joined by
     * underscores, at most 32 characters, unique in the business.
     */
    public static function keyFromName(string $name): string
    {
        // ⚠ The same steps as keyFrom() in supplier-type-dialog.tsx, so the
        // key the popup shows is the key that gets saved.
        $base = substr(trim((string) preg_replace('/[^a-z0-9]+/', '_', Str::lower(Str::ascii($name))), '_'), 0, 28) ?: 'type';
        $taken = SupplierType::query()->pluck('key')->all();
        $key = $base;
        $n = 2;

        while (in_array($key, $taken, true)) {
            $key = "{$base}_{$n}";
            $n++;
        }

        return $key;
    }
}
