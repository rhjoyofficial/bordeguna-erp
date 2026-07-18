<?php

namespace App\Modules\Foundation\Actions;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Generic reject for any model using the Approvable concern — rejecting
 * never has a domain side effect (unlike approving, which for some
 * approvables — e.g. StockRequest — triggers the actual action). Safe to
 * share across every module rather than each re-implementing this guard.
 */
class RejectApprovable
{
    public function handle(Model $approvable, User $reviewedBy, ?string $reviewNote = null): Model
    {
        if (! $approvable->approvalRequest->isPending()) {
            throw new RuntimeException(class_basename($approvable)." #{$approvable->id} has already been reviewed.");
        }

        $approvable->approvalRequest->reject($reviewedBy, $reviewNote);

        return $approvable;
    }
}
