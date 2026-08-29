<?php

namespace App\Modules\Crm\Actions;

use App\Modules\Crm\Models\Contact;
use App\Modules\Crm\Support\ContactImportPlan;
use App\Modules\Crm\Support\PhoneIdentity;
use Normalizer;

/**
 * Works out exactly what a phone book file would do, and writes nothing.
 *
 * ⚠⚠ READS THE WHOLE EXISTING BOOK FIRST, then decides row by row. Deciding per
 * row with a query each time makes two rows sharing a number look like an update
 * of a contact that does not exist yet.
 *
 * C-PB-I1  MATCHING IS ON `phone_normalized`, and ONLY when exactly one contact
 *          already holds that number. This file has no code column, because a
 *          phone book is typed by whoever collected the numbers and demanding a
 *          permanent key would block the import that matters most: a
 *          spreadsheet somebody already has.
 * C-PB-I2  ⚠⚠ TWO EXISTING CONTACTS ON ONE NUMBER IS AN ERROR FOR THAT ROW, not
 *          a guess. A shared household or shop number is legitimate and
 *          PhoneIdentity is explicit that a number never decides identity on its
 *          own, so when the book already holds two people on one number the file
 *          cannot say which of them it means. Refusing one row is recoverable;
 *          overwriting the wrong person's name is not.
 * C-PB-I3  A ROW WITH NO PHONE CANNOT BE MATCHED, so it always creates, and it
 *          warns. Importing the same phone-less file twice makes everybody
 *          twice, and the person doing it should be told before, not after.
 * C-PB-I4  THE FILE CONTRADICTING ITSELF IS AN ERROR. Two rows carrying the same
 *          number would have the second silently win, and the reviewer would
 *          approve a plan showing one outcome and get the other.
 * C-PB-I5  A BLANK CELL MEANS "nothing to say", never "erase what is there".
 *          Otherwise exporting, clearing a column by accident and re-importing
 *          would wipe every note and company in the book.
 * C-PB-I6  ⚠ NFC FIRST on every text value. Production stores decomposed Bangla
 *          and a file typed elsewhere stores composed; without normalising, two
 *          identical-looking names compare unequal and every row reports a
 *          change that is not one.
 * C-PB-I7  A CONTACT LINKED TO A LEAD OR CUSTOMER IS NEVER UPDATED BY A FILE.
 *          The master record is the master; a spreadsheet must not rename
 *          somebody's customer record through the back door.
 */
class PlanContactImport
{
    /** Columns a person may set through the file. */
    private const WRITABLE = ['name', 'company', 'phone', 'email', 'address', 'source', 'note', 'is_active'];

    /**
     * @param  list<array{line:int, values:array<string,string>}>  $rows
     */
    public function handle(array $rows): ContactImportPlan
    {
        $plan = new ContactImportPlan;

        $existing = Contact::query()->get();

        // ⚠ Grouped, not keyed. Keying would silently keep one of the two
        // contacts sharing a number and lose the fact that there were two,
        // which is precisely the fact C-PB-I2 needs.
        $byPhone = $existing->filter(fn (Contact $c) => $c->phone_normalized !== null)
            ->groupBy('phone_normalized');

        $touched = [];
        $seenInFile = [];

        foreach ($rows as $row) {
            $line = $row['line'];
            $values = array_map(fn ($v) => $this->nfc($v), $row['values']);
            $name = $values['name'] ?? '';

            if ($name === '') {
                $plan->addError($line, '', 'A contact with no name cannot be filed. Every other column is optional.');

                continue;
            }

            $phoneRaw = $values['phone'] ?? '';
            $normalized = PhoneIdentity::normalize($phoneRaw);
            $email = $values['email'] ?? '';

            if ($phoneRaw !== '' && $normalized === null) {
                $plan->addError($line, $name, "'{$phoneRaw}' has no digits in it, so it is not a phone number.");

                continue;
            }

            if ($normalized === null && $email === '') {
                $plan->addError($line, $name, 'No phone and no email, so there is no way to reach this person and nothing to file them under.');

                continue;
            }

            if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $plan->addError($line, $name, "'{$email}' is not an email address.");

                continue;
            }

            // ⚠ Every column but `name` is optional, so the key may not exist
            // at all. Reading it unguarded made a two-column file - the most
            // ordinary phone book file there is - fatal.
            $isActive = $values['is_active'] ?? '';

            if ($isActive !== '' && ! in_array($isActive, ['active', 'inactive'], true)) {
                $plan->addError($line, $name, "is_active must be 'active' or 'inactive', not '{$isActive}'.");

                continue;
            }

            // C-PB-I4: the file contradicting itself.
            if ($normalized !== null && isset($seenInFile[$normalized])) {
                $plan->addError($line, $name, "Line {$seenInFile[$normalized]} already uses {$phoneRaw}. The file cannot say two different things about one number.");

                continue;
            }

            if ($normalized !== null) {
                $seenInFile[$normalized] = $line;
            }

            $attributes = $this->attributesFrom($values);

            // C-PB-I3: nothing to match on.
            if ($normalized === null) {
                $plan->addWarning($line, $name, 'No phone number, so this row can never be matched to an existing contact. Importing this file again will create this person a second time.');
                $plan->creates[] = ['line' => $line, 'name' => $name, 'phone' => '', 'attributes' => $attributes];

                continue;
            }

            $matches = $byPhone->get($normalized);

            if ($matches === null || $matches->isEmpty()) {
                $plan->creates[] = ['line' => $line, 'name' => $name, 'phone' => $phoneRaw, 'attributes' => $attributes];

                continue;
            }

            // C-PB-I2: ambiguous.
            if ($matches->count() > 1) {
                $who = $matches->pluck('name')->implode(', ');
                $plan->addError($line, $name, "{$phoneRaw} already belongs to {$matches->count()} contacts ({$who}). This file cannot say which one it means; separate them by hand first.");

                continue;
            }

            /** @var Contact $contact */
            $contact = $matches->first();
            $touched[$contact->id] = true;

            // C-PB-I7: a linked contact is the shadow of a master record.
            if ($contact->linked_type !== null) {
                $plan->addWarning($line, $name, "{$phoneRaw} belongs to a contact linked to a ".strtolower((string) $contact->linkedLabel()).' record, so this row was left alone. Change the master record instead.');
                $plan->unchanged[] = ['line' => $line, 'name' => $name, 'phone' => $phoneRaw];

                continue;
            }

            $changes = $this->changesFor($contact, $attributes);

            if ($changes === []) {
                $plan->unchanged[] = ['line' => $line, 'name' => $name, 'phone' => $phoneRaw];

                continue;
            }

            $plan->updates[] = [
                'line' => $line, 'name' => $name, 'phone' => $phoneRaw,
                'id' => $contact->id, 'attributes' => $attributes, 'changes' => $changes,
            ];
        }

        $plan->absentFromFile = $existing->reject(fn (Contact $c) => isset($touched[$c->id]))->count();

        return $plan;
    }

    /**
     * C-PB-I5: only what the file actually says.
     *
     * @param  array<string,string>  $values
     * @return array<string,mixed>
     */
    private function attributesFrom(array $values): array
    {
        $attributes = [];

        foreach (self::WRITABLE as $column) {
            $value = $values[$column] ?? '';

            if ($value === '') {
                continue;
            }

            $attributes[$column] = $column === 'is_active' ? $value === 'active' : $value;
        }

        return $attributes;
    }

    /**
     * @param  array<string,mixed>  $attributes
     * @return array<string,array{0:mixed,1:mixed}>
     */
    private function changesFor(Contact $contact, array $attributes): array
    {
        $changes = [];

        foreach ($attributes as $column => $new) {
            $current = $contact->{$column};

            if ($column === 'is_active') {
                if ((bool) $current !== (bool) $new) {
                    $changes[$column] = [$current ? 'active' : 'inactive', $new ? 'active' : 'inactive'];
                }

                continue;
            }

            // C-PB-I6: compare like with like.
            if ($this->nfc((string) ($current ?? '')) !== $this->nfc((string) $new)) {
                $changes[$column] = [$current, $new];
            }
        }

        return $changes;
    }

    private function nfc(string $value): string
    {
        return Normalizer::normalize($value, Normalizer::FORM_C) ?: $value;
    }
}
