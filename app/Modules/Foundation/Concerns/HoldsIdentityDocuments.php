<?php

namespace App\Modules\Foundation\Concerns;

use App\Models\User;
use App\Modules\Foundation\Enums\IdentityDocument;

/**
 * A record that keeps a trade licence and an NID scan (plan D11): a customer or
 * a supplier. Pair it with ControlsAttachmentAccess and OpensAttachmentCollections
 * on the model.
 *
 * ⚠⚠ THE GATE IS THE STREAM'S, NOT THE SCREEN'S. `attachments.show` asks the
 * owner model; a screen that hides the NID link while the URL still streams would
 * be a guard nothing reaches. The trade licence is the one OPEN collection;
 * everything else this record ever carries is gated, so a collection added later
 * fails closed.
 *
 * ⚠ Records carrying these files stay OUT of FileVisibility on purpose. The file
 * manager maps permissions by record TYPE, so mapping customers would list every
 * NID scan's name to anyone who can read customers. Unmapped, only the
 * organisation's managers see them there; everyone else reaches the files from
 * the record's own screen.
 */
trait HoldsIdentityDocuments
{
    use HasAttachments;

    /** The permission that opens this record's restricted scans. */
    abstract public function identityPermission(): string;

    /** The permission that manages this record (and so may add its documents). */
    abstract public function documentManagePermission(): string;

    public function canAccessAttachments(User $user): bool
    {
        return $user->can($this->identityPermission());
    }

    public function openAttachmentCollections(): array
    {
        return [IdentityDocument::TradeLicence->value];
    }
}
