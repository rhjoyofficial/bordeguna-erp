<?php

namespace App\Modules\Foundation\Contracts;

/**
 * The pluggable applier a model implements to become a valid ChangeRequest
 * subject. This is the seam that keeps ChangeRequest generic: the Foundation
 * pattern never knows about employees or suppliers — it just asks the subject to
 * apply an approved set of changes and to describe itself. A model's
 * implementation should delegate to its own existing write path (Employee →
 * UpdateEmployee) so a direct edit and an approved request share one validated
 * path and one audit trail.
 */
interface HandlesChangeRequest
{
    /**
     * Apply an approved set of proposed changes to this record.
     *
     * @param  array<string, mixed>  $changes  field => new value
     */
    public function applyChangeRequest(array $changes): void;

    /**
     * A short human label for this subject (e.g. "BDG-000001 — Karim Ahmed"),
     * shown in the approval inbox.
     */
    public function changeRequestSubjectLabel(): string;

    /**
     * Human-readable rendering of a proposed change set: field-label => value,
     * so the inbox and the requester's own view read naturally regardless of the
     * subject type.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, string>
     */
    public function describeChangeRequest(array $changes): array;
}
