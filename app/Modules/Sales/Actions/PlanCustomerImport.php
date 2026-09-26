<?php

namespace App\Modules\Sales\Actions;

use App\Modules\Crm\Support\PhoneIdentity;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\PriceGroup;
use App\Modules\Sales\Support\CustomerImportPlan;
use Illuminate\Support\Collection;

/**
 * Works out exactly what a customer file would do, and writes nothing.
 *
 * ⚠⚠ READS THE WHOLE EXISTING LIST FIRST, then decides row by row. Deciding per
 * row with a query each time makes two rows sharing a code look like an update
 * of a customer that does not exist yet.
 *
 * C-CUS-1  MATCHING IS ON `code`, uppercased. Not on name: the same Bangla name
 *          in composed and decomposed form never compares equal, so a
 *          name-keyed importer creates a second row for a customer it already
 *          has. Not on phone either: PhoneIdentity is explicit that a number is
 *          NOT unique (a household, a shop and its owner share one), so phone
 *          may warn and must never decide identity.
 * C-CUS-2  THE WALK-IN ROW IS UNTOUCHABLE. `Customer::walkIn()` resolves the
 *          counter customer by its code, and every counter sale in the POS
 *          depends on that row keeping its identity and type. A spreadsheet is
 *          not allowed anywhere near it.
 * C-CUS-3  `type` blank means retail, the same default the table carries.
 *          `walk_in` is refused: that type belongs to the system row alone.
 * C-CUS-4  `price_group` is a group CODE resolved to a real row, and an unknown
 *          code is an ERROR rather than a silent fall back to retail. A dealer
 *          quietly billed at retail prices is money lost on every line, and it
 *          is the one failure this file exists to prevent.
 * C-CUS-5  A BLANK CELL MEANS "nothing to say", never "erase what is there".
 *          Otherwise exporting, clearing a column by accident and re-importing
 *          would wipe every credit limit in the business.
 * C-CUS-6  A dealer, distributor or corporate customer with NO price group is a
 *          warning, not an error: they will bill at retail, which is exactly
 *          what the cutover preflight flags as F2.
 */
class PlanCustomerImport
{
    /** Columns a person may set through the file. */
    private const WRITABLE = [
        'name', 'type', 'contact_name', 'phone', 'email', 'address',
        'price_group_id', 'payment_terms', 'credit_days', 'credit_limit', 'bin', 'tin',
        'note', 'is_active',
    ];

    /**
     * @param  list<array{line:int, values:array<string,string>}>  $rows
     */
    public function handle(array $rows): CustomerImportPlan
    {
        $plan = new CustomerImportPlan;

        // ⚠ Uppercased, because `cus-0001` and `CUS-0001` are the same customer
        // and creating both would split one buyer's history in two.
        $existing = Customer::query()->get()->keyBy(fn (Customer $c) => strtoupper((string) $c->code));
        $groups = PriceGroup::query()->get()->keyBy(fn (PriceGroup $g) => strtoupper($g->code));
        // For the review text: a change reading "3 -> 9" tells the reader
        // nothing, and price groups are the one field on this screen that
        // decides money.
        $groupCodeById = $groups->mapWithKeys(fn (PriceGroup $g) => [$g->id => $g->code]);

        // Phone owners already on file, for the duplicate warning only.
        $phoneOwners = $existing->filter(fn (Customer $c) => $c->phone_normalized !== null)
            ->mapWithKeys(fn (Customer $c) => [$c->phone_normalized => $c]);

        $seen = [];
        $seenPhones = [];

        foreach ($rows as $row) {
            $values = $row['values'];
            $line = $row['line'];
            $code = strtoupper(trim($values['code'] ?? ''));
            $name = trim($values['name'] ?? '');

            if ($code === '') {
                $plan->addError($line, '(no code)', 'A customer needs a code in this file. It is how a second import knows this is the same person and not a new one.');

                continue;
            }

            if (! preg_match('/^[A-Z0-9][A-Z0-9\-]*$/', $code)) {
                $plan->addError($line, $code, 'A code may hold only letters, digits and hyphens, and must start with a letter or digit.');

                continue;
            }

            if (isset($seen[$code])) {
                $plan->addError($line, $code, "The same code appears on line {$seen[$code]} as well. A code identifies exactly one customer.");

                continue;
            }

            $seen[$code] = $line;

            if ($name === '') {
                $plan->addError($line, $code, 'A customer needs a name.');

                continue;
            }

            $current = $existing->get($code);

            // C-CUS-2.
            if ($current !== null && $current->is_system) {
                $plan->addError($line, $code, 'That is the walk-in counter customer, which the system owns. Every counter sale resolves through it, so it is not editable from a file.');

                continue;
            }

            $type = $this->customerType($values['type'] ?? '');

            if ($type === null) {
                $plan->addError($line, $code, 'type must be retail, dealer, distributor or corporate (or left blank, which means retail).');

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

            $creditLimit = $this->creditLimit($values['credit_limit'] ?? '');

            if ($creditLimit === false) {
                $plan->addError($line, $code, 'credit_limit must be an amount of zero or more, digits only. Leave it blank when no limit is agreed.');

                continue;
            }

            $creditDays = trim($values['credit_days'] ?? '');

            if ($creditDays !== '' && (! ctype_digit($creditDays) || (int) $creditDays > 365)) {
                $plan->addError($line, $code, 'credit_days must be a whole number of days from 0 to 365. Leave it blank for the default, 10.');

                continue;
            }

            $groupCode = strtoupper(trim($values['price_group'] ?? ''));
            $groupId = null;

            if ($groupCode !== '') {
                $group = $groups->get($groupCode);

                if ($group === null) {
                    // C-CUS-4.
                    $plan->addError($line, $code, "There is no price group with the code \"{$groupCode}\". Create the group first, or correct the spelling.");

                    continue;
                }

                $groupId = $group->id;
            }

            // C-CUS-6. Warned once per row, and only where it costs money.
            if ($groupCode === '' && $type !== CustomerType::Retail) {
                $plan->addWarning($line, $code, "A {$type->label()} with no price group is priced in {$type->defaultPriceGroupCode()}; a product with no {$type->defaultPriceGroupCode()} price cannot be sold to them.");
            }

            $this->warnAboutPhone($plan, $line, $code, trim($values['phone'] ?? ''), $phoneOwners, $seenPhones);

            $attributes = [
                'is_active' => $active,
                'type' => $type,
                'name' => $name,
            ];

            if ($groupCode !== '') {
                $attributes['price_group_id'] = $groupId;
            }

            if ($creditLimit !== null) {
                $attributes['credit_limit'] = $creditLimit;
            }

            foreach (self::WRITABLE as $column) {
                // Already decided above, each with its own rules.
                if (in_array($column, ['is_active', 'type', 'name', 'price_group_id', 'credit_limit'], true)) {
                    continue;
                }

                $given = trim($values[$column] ?? '');

                // C-CUS-5.
                if ($given === '') {
                    continue;
                }

                $attributes[$column] = $given;
            }

            if ($current === null) {
                $plan->creates[] = [
                    'line' => $line,
                    'code' => $code,
                    'name' => $name,
                    'attributes' => array_merge($attributes, ['code' => $code]),
                ];

                continue;
            }

            $changes = [];

            foreach ($attributes as $column => $value) {
                if ($this->same($current, $column, $value)) {
                    continue;
                }

                $changes[$column] = $column === 'price_group_id'
                    ? [
                        $groupCodeById[$current->price_group_id] ?? null,
                        $groupCodeById[$value] ?? null,
                    ]
                    : [$current->{$column}, $value];
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
            ->reject(fn (string $code) => isset($seen[$code]) || $code === '')
            ->sort()
            ->values()
            ->all();

        return $plan;
    }

    /**
     * ⚠⚠ WARNS, NEVER MERGES and never corrects. PhoneIdentity is explicit that
     * the normalised number is not unique: a household, a shop and its owner
     * legitimately share one. So a repeat is worth a person's eye and must
     * never join two customers or block the import.
     *
     * @param  Collection<string, Customer>  $phoneOwners
     * @param  array<string, array{line:int, code:string}>  $seenPhones
     */
    private function warnAboutPhone(
        CustomerImportPlan $plan,
        int $line,
        string $code,
        string $phone,
        $phoneOwners,
        array &$seenPhones,
    ): void {
        if ($phone === '') {
            return;
        }

        $normalized = PhoneIdentity::normalize($phone);

        if ($normalized === null) {
            return;
        }

        if (isset($seenPhones[$normalized])) {
            $other = $seenPhones[$normalized];
            $plan->addWarning($line, $code, "Phone {$phone} is also on line {$other['line']} for {$other['code']}. Imported as written; check they are not the same person twice.");
        } else {
            $seenPhones[$normalized] = ['line' => $line, 'code' => $code];
        }

        $owner = $phoneOwners->get($normalized);

        if ($owner !== null && strtoupper((string) $owner->code) !== $code) {
            $plan->addWarning($line, $code, "Phone {$phone} already belongs to {$owner->name} ({$owner->code}). Imported as written; check they are not the same person twice.");
        }
    }

    private function customerType(string $given): ?CustomerType
    {
        $given = strtolower(trim($given));

        if ($given === '') {
            return CustomerType::Retail;
        }

        // C-CUS-3. The walk-in type belongs to the system row alone.
        if ($given === CustomerType::WalkIn->value) {
            return null;
        }

        return CustomerType::tryFrom($given);
    }

    /**
     * The amount, null when the cell is blank, or false when it is not a number.
     */
    private function creditLimit(string $given): float|null|false
    {
        $given = trim($given);

        if ($given === '') {
            return null;
        }

        // A spreadsheet writes thousands separators without being asked.
        $cleaned = str_replace([',', ' '], '', $given);

        if (! is_numeric($cleaned) || (float) $cleaned < 0) {
            return false;
        }

        return (float) $cleaned;
    }

    private function activeFlag(string $given): ?bool
    {
        return match (strtolower(trim($given))) {
            '', 'active', '1', 'yes', 'true', 'y' => true,
            'inactive', '0', 'no', 'false', 'n' => false,
            default => null,
        };
    }

    private function same(Customer $current, string $column, mixed $value): bool
    {
        return match ($column) {
            'is_active' => (bool) $current->is_active === (bool) $value,
            'type' => $current->type === $value,
            'price_group_id' => (int) $current->price_group_id === (int) $value,
            // ⚠ Compared as numbers. MySQL hands back "5000.0000" for a decimal
            // column, which never equals the "5000" in the file as text, and
            // every row would report a change that is not one.
            'credit_limit' => $current->credit_limit !== null && abs((float) $current->credit_limit - (float) $value) < 0.00005,
            default => (string) ($current->{$column} ?? '') === (string) $value,
        };
    }
}
