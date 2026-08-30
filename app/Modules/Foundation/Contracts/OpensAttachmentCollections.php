<?php

namespace App\Modules\Foundation\Contracts;

/**
 * Lets a model that gates its attachments (ControlsAttachmentAccess) name the
 * collections that are NOT gated - open to anyone in the business.
 *
 * ⚠ Why this exists (30 Aug 2026): an employee's photo sat behind the same
 * rule as their NID and bank documents, so everyone but HR got a 403 on a
 * colleague's face, the browser gave up on the <img>, and every avatar in the
 * app fell back to initials. A face is not a certificate; the rest of the
 * employee's files stay exactly as gated as before.
 */
interface OpensAttachmentCollections
{
    /** @return list<string> collection names anyone in the business may read */
    public function openAttachmentCollections(): array;
}
