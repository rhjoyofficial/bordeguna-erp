<?php

namespace App\Modules\Crm\Actions;

use App\Models\User;
use App\Modules\Crm\Corrections\ContactActionPolicy;
use App\Modules\Crm\Models\Contact;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\DocumentAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ A phone book contact made by mistake goes to the Recycle bin (corrections
 * wave 3, 24 Oct 2026; FINAL-PLAN 6.3). Only one nobody ever sent a message
 * to (ContactActionPolicy, Contact::whyNotRemovable); the Owner restores it.
 *
 * ⚠ Asked again under the lock: a double click finds it gone and is refused.
 */
class DeleteContact
{
    public function __construct(private readonly ContactActionPolicy $policy) {}

    /**
     * @throws ValidationException
     */
    public function handle(Contact $contact, User $by, ActionReason $reason): void
    {
        DB::transaction(function () use ($contact, $by, $reason) {
            $current = Contact::query()->visibleLeadsOnly()->whereKey($contact->id)->lockForUpdate()->first()
                ?? throw ValidationException::withMessages(['contact' => 'This contact is no longer in the phone book.']);

            $availability = $this->policy->availability($current, $by, DocumentAction::Delete);

            if (! $availability->allowed) {
                throw ValidationException::withMessages(['contact' => $availability->blockers[0]->message]);
            }

            $current->recycle($by, $reason);
        });
    }
}
