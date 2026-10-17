<?php

namespace App\Modules\Purchasing\Actions;

use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Support\SupplierImportPlan;
use App\Modules\Purchasing\Support\SupplierTypes;

/**
 * Works out what a supplier file would do, without doing any of it.
 *
 * ⚠⚠ READS THE WHOLE EXISTING LIST FIRST, then decides row by row. Deciding
 * per row with a query each time makes two rows sharing a code look like an
 * update of a supplier that does not exist yet.
 */
class PlanSupplierImport
{
    /** Columns a person may set through the file. */
    private const WRITABLE = [
        'name', 'contact_name', 'phone', 'email', 'address', 'payment_terms', 'supplier_type',
        'bin', 'tin', 'bank_name', 'bank_branch', 'bank_account_name',
        'bank_account_number', 'mfs_number', 'notes', 'is_active',
    ];

    /**
     * @param  list<array{line:int, values:array<string,string>}>  $rows
     */
    public function handle(array $rows): SupplierImportPlan
    {
        $plan = new SupplierImportPlan;

        // ⚠ Keyed by code, which is the identity. Codes are compared uppercase
        // because `addeen` and `ADDEEN` are the same supplier and creating both
        // would split one company's purchase history in two.
        $existing = Supplier::query()->get()->keyBy(fn (Supplier $s) => strtoupper($s->code));

        $seen = [];

        foreach ($rows as $row) {
            $values = $row['values'];
            $line = $row['line'];
            $code = strtoupper(trim($values['code'] ?? ''));
            $name = trim($values['name'] ?? '');

            if ($code === '') {
                $plan->addError($line, '(no code)', 'A supplier needs a code. It is the permanent identity and is never generated.');

                continue;
            }

            if (! preg_match('/^[A-Z0-9][A-Z0-9\-]*$/', $code)) {
                $plan->addError($line, $code, 'A code may hold only letters, digits and hyphens, and must start with a letter or digit.');

                continue;
            }

            if (isset($seen[$code])) {
                $plan->addError($line, $code, "The same code appears on line {$seen[$code]} as well. A code identifies exactly one supplier.");

                continue;
            }

            $seen[$code] = $line;

            if ($name === '') {
                $plan->addError($line, $code, 'A supplier needs a name.');

                continue;
            }

            $email = trim($values['email'] ?? '');

            if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $plan->addError($line, $code, "\"{$email}\" is not a valid email address.");

                continue;
            }

            $active = $this->activeFlag($values['is_active'] ?? '');

            if ($active === null) {
                $plan->addError($line, $code, 'is_active must be "active" or "inactive" (or left blank, which means active).');

                continue;
            }

            // Finance decision C4: the key (`goods`) or the name (`Goods
            // supplier`) of one of the business's own types, either case;
            // stored as the key (17 Oct 2026: types are a list he keeps).
            $type = trim($values['supplier_type'] ?? '');

            if ($type !== '') {
                $found = SupplierTypes::find($type);

                if ($found === null) {
                    $plan->addError($line, $code, "\"{$type}\" is not a supplier type. Use one of: ".SupplierTypes::activeKeysSentence().'.');

                    continue;
                }

                // ⚠ A switched-off type is refused for anything new, but an
                // exported file re-imported unchanged must still go through:
                // the supplier that already carries it keeps it.
                $current = $existing->get($code);

                if (! $found->is_active && $current?->supplier_type !== $found->key) {
                    $plan->addError($line, $code, "\"{$found->name}\" is a switched-off supplier type. Use one of: ".SupplierTypes::activeKeysSentence().'.');

                    continue;
                }

                $values['supplier_type'] = $found->key;
            }

            $this->warnAboutPhone($plan, $line, $code, trim($values['phone'] ?? ''));

            $attributes = ['is_active' => $active];

            foreach (self::WRITABLE as $column) {
                if ($column === 'is_active') {
                    continue;
                }

                $given = trim($values[$column] ?? '');

                // ⚠⚠ A blank cell means "nothing to say", NOT "erase what is
                // there". Otherwise exporting, deleting a column's contents by
                // accident, and re-importing would silently wipe every bank
                // account in the business. Clearing a field stays a deliberate
                // act on the screen.
                if ($given === '') {
                    continue;
                }

                $attributes[$column] = $given;
            }

            $current = $existing->get($code);

            if ($current === null) {
                $plan->creates[] = [
                    'line' => $line,
                    'code' => $code,
                    'name' => $name,
                    'attributes' => array_merge($attributes, ['code' => $code, 'name' => $name]),
                ];

                continue;
            }

            $changes = [];

            foreach ($attributes as $column => $value) {
                $was = $current->{$column};

                // is_active is a bool on the model and a bool here; everything
                // else compares as text.
                $same = $column === 'is_active'
                    ? (bool) $was === (bool) $value
                    : (string) ($was ?? '') === (string) $value;

                if (! $same) {
                    $changes[$column] = [$was, $value];
                }
            }

            if ($changes === []) {
                $plan->unchanged[] = ['line' => $line, 'code' => $code, 'name' => $name];

                continue;
            }

            $plan->updates[] = [
                'line' => $line,
                'code' => $code,
                'name' => $name,
                'id' => $current->id,
                'attributes' => $attributes,
                'changes' => $changes,
            ];
        }

        $plan->absentFromFile = $existing->keys()
            ->reject(fn (string $code) => isset($seen[$code]))
            ->sort()
            ->values()
            ->all();

        return $plan;
    }

    /**
     * ⚠⚠ WARNS, NEVER CORRECTS. Joy decided on 9 August that no phone
     * normalisation feature would be built and that the three wrong numbers
     * would be fixed by hand. Silently rewriting what the file says would be
     * exactly that feature, arriving through the back door - and a supplier's
     * phone number is something a person should be able to type wrong on
     * purpose (an office landline, a foreign number) without a tool arguing.
     */
    private function warnAboutPhone(SupplierImportPlan $plan, int $line, string $code, string $phone): void
    {
        if ($phone === '') {
            return;
        }

        $digits = preg_replace('/\D/', '', $phone) ?? '';

        // A Bangladesh mobile is 01XXXXXXXXX - 11 digits starting 01.
        if (preg_match('/^01\d{9}$/', $digits)) {
            return;
        }

        if (preg_match('/^1\d{9}$/', $digits)) {
            // ⚠⚠ ONE ZERO, NOT "01". `$digits` already begins with the 1, so
            // prefixing "01" produced a TWELVE-digit suggestion - the message
            // told the reader to type a number that cannot exist. Shipped on
            // 9 August and caught within the hour by actually reading the output
            // of a review run rather than only the assertion that a warning
            // appeared. The regression test now pins the exact string.
            $plan->addWarning($line, $code, "Phone \"{$phone}\" looks like a Bangladesh mobile missing its leading zero (0{$digits}). Imported exactly as written.");

            return;
        }

        $plan->addWarning($line, $code, "Phone \"{$phone}\" is not an 11-digit Bangladesh mobile. Imported exactly as written.");
    }

    private function activeFlag(string $given): ?bool
    {
        return match (strtolower(trim($given))) {
            '', 'active', '1', 'yes', 'true', 'y' => true,
            'inactive', '0', 'no', 'false', 'n' => false,
            default => null,
        };
    }
}
