<?php

namespace App\Modules\Foundation\Actions;

use App\Modules\Foundation\Contracts\HandlesChangeRequest;
use App\Modules\Foundation\Models\ChangeRequest;
use RuntimeException;

/**
 * Applies an approved change request by handing its changes to the subject's own
 * pluggable applier. The write path and its validation/audit live entirely with
 * the subject (Employee → UpdateEmployee), so an approved request and a direct
 * edit are indistinguishable after the fact — the whole point of the pattern.
 */
class ApplyChangeRequest
{
    public function handle(ChangeRequest $changeRequest): void
    {
        $subject = $changeRequest->subject;

        if (! $subject instanceof HandlesChangeRequest) {
            throw new RuntimeException(
                'Change request #'.$changeRequest->id.' has a subject ('.$changeRequest->subject_type.') that cannot apply changes.'
            );
        }

        $subject->applyChangeRequest($changeRequest->proposed_changes);
    }
}
