<?php

namespace App\Modules\Purchasing\Exceptions;

use App\Modules\Purchasing\Support\PurchaseDraft;
use Illuminate\Validation\ValidationException;

/**
 * Raised by a purchasing domain action that would post a document needing a
 * second person (ruling P7). Nothing has been written: the action's
 * transaction rolls back as this passes through it.
 *
 * ⚠ A ValidationException on purpose. A caller that does not know about
 * approvals still cannot post the document: the user reads the reasons as a
 * refusal. A caller that does know catches it OUTSIDE its own transaction and
 * hands it to PurchasingApprovalGate::hold(), which saves it as pending and
 * tells the approvers.
 */
class PurchaseApprovalRequired extends ValidationException
{
    public PurchaseDraft $draft;

    /** @var list<array{code: string, label: string}> */
    public array $reasons = [];

    /**
     * @param  list<array{code: string, label: string}>  $reasons
     */
    public static function for(PurchaseDraft $draft, array $reasons): self
    {
        $labels = implode('; ', array_map(fn (array $reason) => $reason['label'], $reasons));

        /** @var self $exception */
        $exception = static::withMessages([
            'approval' => ucfirst($draft->kindLabel())." needs a second person's approval: {$labels}.",
        ]);
        $exception->draft = $draft;
        $exception->reasons = $reasons;

        return $exception;
    }
}
