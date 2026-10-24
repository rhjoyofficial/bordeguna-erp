<?php

namespace App\Modules\Crm\Actions;

use App\Models\User;
use App\Modules\Crm\Models\Contact;
use App\Modules\Crm\Models\ContactImportBatch;
use App\Modules\Crm\Models\ContactImportBatchRow;
use App\Modules\Crm\Support\ContactImportPlan;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Writes a phone book plan that has already been reviewed.
 *
 * ⚠⚠ REFUSES A PLAN WITH ERRORS rather than trusting the caller to have checked,
 * and refuses to write into no tenant at all. Both rules belong here and not in
 * the screen, because the screen is not the only caller a system like this ends
 * up with.
 *
 * ⚠ One transaction. A half-imported phone book is worse than none: nobody would
 * know which rows to run again, and the second attempt would duplicate whatever
 * the first one managed.
 *
 * ⭐ Corrections wave 3 (24 Oct 2026): the file is kept as a BATCH with one row
 * per contact it created or changed, the values before and after, so a wrong
 * file can be taken back (ReverseContactImport). Written in the same
 * transaction, so there is never an import without its batch.
 */
class ApplyContactImport
{
    public function handle(ContactImportPlan $plan, ?User $by = null, ?string $fileName = null): ?ContactImportBatch
    {
        if ($plan->hasErrors()) {
            throw new RuntimeException('This plan has errors and must never be applied.');
        }

        $businessId = app(BusinessContext::class)->id();

        if ($businessId === null) {
            throw new RuntimeException('No business is in context; refusing to import contacts into nothing.');
        }

        return DB::transaction(function () use ($plan, $businessId, $by, $fileName) {
            $batch = null;

            if ($by !== null) {
                $batch = new ContactImportBatch;
                $batch->forceFill([
                    'business_id' => $businessId,
                    'file_name' => $fileName === null ? null : mb_substr($fileName, 0, 255),
                    'created_count' => count($plan->creates),
                    'updated_count' => count($plan->updates),
                    'imported_by' => $by->id,
                ])->save();
            }

            foreach ($plan->creates as $create) {
                $contact = Contact::create(array_merge($create['attributes'], ['business_id' => $businessId, 'created_by' => $by?->id]));

                $this->keep($batch, $contact, ContactImportBatchRow::CREATED, null, $create['attributes']);
            }

            foreach ($plan->updates as $update) {
                $contact = Contact::findOrFail($update['id']);
                // Only what the file actually changed is kept, before and after:
                // a column it repeated unchanged is nobody's edit.
                $changed = array_intersect_key($update['attributes'], $update['changes'] ?? $update['attributes']);
                $before = $contact->only(array_keys($changed));

                // ⚠ `linked_type` and `linked_id` are NOT among the writable
                // columns and cannot be. A file must never be able to claim that
                // one of its rows is somebody's customer record.
                $contact->fill($update['attributes'])->save();

                $this->keep($batch, $contact, ContactImportBatchRow::UPDATED, $before, $changed);
            }

            return $batch;
        });
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>  $after
     */
    private function keep(?ContactImportBatch $batch, Contact $contact, string $kind, ?array $before, array $after): void
    {
        if ($batch === null) {
            return;
        }

        $row = new ContactImportBatchRow;
        $row->forceFill([
            'contact_import_batch_id' => $batch->id,
            'contact_id' => $contact->id,
            'kind' => $kind,
            'before' => $before,
            'after' => $after,
        ])->save();
    }
}
