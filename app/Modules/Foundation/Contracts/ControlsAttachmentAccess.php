<?php

namespace App\Modules\Foundation\Contracts;

use App\Models\User;

/**
 * Lets an attachable model impose access rules stricter than the default
 * business scope. The attachments.show route already confines files to the
 * current business; a model that also carries sensitive files (an employee's
 * NID or bank document, say) implements this so the route additionally checks
 * who may read them. Models that don't implement it keep the plain
 * business-scoped behaviour.
 */
interface ControlsAttachmentAccess
{
    public function canAccessAttachments(User $user): bool;
}
