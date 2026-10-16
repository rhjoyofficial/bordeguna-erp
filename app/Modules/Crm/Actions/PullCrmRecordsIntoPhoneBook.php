<?php

namespace App\Modules\Crm\Actions;

use App\Modules\Crm\Models\Contact;
use App\Modules\Crm\Models\Lead;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Sales\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Brings leads and customers into the phone book, so a group can contain them.
 *
 * ⚠⚠ THE MASTER RECORD STAYS THE MASTER. This creates a SHADOW row in
 * `contacts` that points back through `linked_type` / `linked_id`. The lead or
 * customer is not touched, not copied away, and not made to depend on the phone
 * book existing. Deleting the whole phone book would lose no CRM data.
 *
 * ⚠ Idempotent, and guaranteed so by the database rather than by this code: the
 * unique index on (business_id, linked_type, linked_id) means pulling the same
 * customer in twice cannot make two rows, whatever a future caller does.
 *
 * ⚠⚠ NAME AND PHONE ARE REFRESHED ON EVERY PULL, and nothing else is. If the
 * customer's number changed, the shadow must follow or the phone book quietly
 * messages a number the business no longer believes in. But `source`, `note` and
 * group membership were added by a person on the phone book side, and a refresh
 * that erased them would punish the only people using the feature.
 *
 * ⚠ A record with NEITHER a phone nor an email is skipped rather than filed. A
 * contact with no way to reach it is a row that can only ever be a nuisance in
 * a group, and it would be counted in every audience total.
 */
class PullCrmRecordsIntoPhoneBook
{
    /**
     * @param  'lead'|'customer'  $type
     * @param  list<int>  $ids  Empty means every record of that type.
     * @return array{created:int, refreshed:int, skipped:int}
     */
    public function handle(string $type, array $ids = []): array
    {
        $businessId = app(BusinessContext::class)->id();

        if ($businessId === null) {
            throw new RuntimeException('No business is in context; refusing to build a phone book for nothing.');
        }

        $records = match ($type) {
            'lead' => Lead::query()->when($ids !== [], fn ($q) => $q->whereIn('id', $ids))->get(),
            'customer' => Customer::query()->when($ids !== [], fn ($q) => $q->whereIn('id', $ids))->get(),
            default => throw new RuntimeException("A phone book can be built from leads or customers, not from '{$type}'."),
        };

        $class = $type === 'lead' ? Lead::class : Customer::class;
        $created = 0;
        $refreshed = 0;
        $skipped = 0;

        DB::transaction(function () use ($records, $class, $businessId, $type, &$created, &$refreshed, &$skipped) {
            foreach ($records as $record) {
                $phone = (string) ($record->phone ?? '');
                $email = (string) ($record->email ?? '');

                if ($phone === '' && $email === '') {
                    $skipped++;

                    continue;
                }

                // ⚠ Bug hunt 16 Oct 2026: a lead's name is optional but a contact's
                // is not, so one nameless lead failed the whole pull (a 500).
                // The number or address stands in for it. A lead's address is
                // free text; a contact keeps 255 characters of it.
                $name = trim((string) ($record->name ?? '')) !== '' ? (string) $record->name : ($phone !== '' ? $phone : $email);
                $address = $record->address === null ? null : Str::limit((string) $record->address, 255, '');

                $contact = Contact::query()
                    ->where('linked_type', $class)
                    ->where('linked_id', $record->id)
                    ->first();

                if ($contact === null) {
                    Contact::create([
                        'business_id' => $businessId,
                        'name' => $name,
                        // ⚠ A customer may have a contact_name (the person to
                        // speak to at a firm); a lead is always a person.
                        'company' => $type === 'customer' && $record->contact_name ? $record->name : null,
                        'phone' => $phone !== '' ? $phone : null,
                        'email' => $email !== '' ? $email : null,
                        'address' => $address,
                        'source' => $type === 'lead' ? 'CRM lead' : 'Customer record',
                        'linked_type' => $class,
                        'linked_id' => $record->id,
                    ]);

                    $created++;

                    continue;
                }

                $contact->fill([
                    'name' => $name,
                    'phone' => $phone !== '' ? $phone : null,
                    'email' => $email !== '' ? $email : null,
                ]);

                if ($contact->isDirty()) {
                    $contact->save();
                    $refreshed++;
                }
            }
        });

        return ['created' => $created, 'refreshed' => $refreshed, 'skipped' => $skipped];
    }
}
