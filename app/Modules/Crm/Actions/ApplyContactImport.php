<?php

namespace App\Modules\Crm\Actions;

use App\Modules\Crm\Models\Contact;
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
 */
class ApplyContactImport
{
    public function handle(ContactImportPlan $plan): void
    {
        if ($plan->hasErrors()) {
            throw new RuntimeException('This plan has errors and must never be applied.');
        }

        $businessId = app(BusinessContext::class)->id();

        if ($businessId === null) {
            throw new RuntimeException('No business is in context; refusing to import contacts into nothing.');
        }

        DB::transaction(function () use ($plan, $businessId) {
            foreach ($plan->creates as $create) {
                Contact::create(array_merge($create['attributes'], ['business_id' => $businessId]));
            }

            foreach ($plan->updates as $update) {
                $contact = Contact::findOrFail($update['id']);

                // ⚠ `linked_type` and `linked_id` are NOT among the writable
                // columns and cannot be. A file must never be able to claim that
                // one of its rows is somebody's customer record.
                $contact->fill($update['attributes'])->save();
            }
        });
    }
}
