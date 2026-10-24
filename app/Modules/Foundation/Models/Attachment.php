<?php

namespace App\Modules\Foundation\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Corrections\Recycle\Recyclable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A generic stored file attached to any model (the Business logo today;
 * employee documents, correction evidence and payroll adjustments later),
 * mirroring the polymorphic ApprovalRequest pattern. Files live on a private
 * disk and are only reachable through the authenticated, business-scoped
 * attachments.show route — never a public URL.
 *
 * ⭐ Corrections wave 3, B11 (24 Oct 2026): a removed file goes to the Recycle
 * bin (FINAL-PLAN 3.5 and 6.3), from the library and from any record alike
 * (DeleteAttachment). The stored file stays on the disk; the download route
 * binds through the soft-delete scope, so a file in the bin is not reachable
 * until the Owner restores it. There is no purge (section 9, answer 1).
 */
class Attachment extends Model
{
    use BelongsToBusiness, HasFactory, Recyclable;

    protected $fillable = [
        'business_id',
        'attachable_type',
        'attachable_id',
        'collection',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size',
        'expires_at',
        'uploaded_by',
        // The archive metadata. See the document-archive migration: a filename
        // is what a scanner called a file, not what somebody will look for.
        'title',
        'description',
        'document_date',
        'reference',
        'document_category_id',
        // Which archive folder this file sits in (library files only; null = the
        // root). Organisation only - never widens who may see the file.
        'folder_id',
        'version',
        'superseded_by_id',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'expires_at' => 'date:Y-m-d',
            'document_date' => 'date:Y-m-d',
            'version' => 'integer',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * ⚠ Expiring, not expired. A trade licence that runs out next month is the
     * one worth showing; one that ran out last year is already a problem
     * somebody has. Both are surfaced, separately.
     */
    public function expiresWithin(int $days = 60): bool
    {
        return $this->expires_at !== null
            && ! $this->expires_at->isPast()
            && $this->expires_at->lessThanOrEqualTo(now()->addDays($days));
    }

    /**
     * What the archive should call this document.
     *
     * ⚠ Falls back to the original filename rather than to "Untitled". A file
     * called scan_0012.pdf is at least findable by somebody who remembers it;
     * "Untitled" is findable by nobody.
     */
    public function displayTitle(): string
    {
        return filled($this->title) ? (string) $this->title : (string) $this->original_name;
    }

    /**
     * ⚠⚠ A superseded document is still a document. It keeps its row and points
     * at what replaced it, so the version somebody signed last year can still
     * be produced. It is hidden from the default list, never deleted.
     */
    public function isSuperseded(): bool
    {
        return $this->superseded_by_id !== null;
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(DocumentCategory::class, 'document_category_id');
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(DocumentFolder::class, 'folder_id');
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function url(): string
    {
        return route('attachments.show', $this);
    }

    /** What the Recycle bin calls it. */
    public function binLabel(): string
    {
        return 'File '.$this->displayTitle();
    }

    /**
     * It comes back only onto a record that is still there. A library file
     * whose folder was deleted meanwhile comes back at the top level (the
     * folder key is null-on-delete).
     */
    public function whyNotRestorable(): ?string
    {
        $owner = $this->attachable_type !== null && class_exists($this->attachable_type)
            ? $this->attachable_type::query()->withoutGlobalScopes()->find($this->attachable_id)
            : null;

        if ($owner === null || (method_exists($owner, 'trashed') && $owner->trashed())) {
            return "The record the file {$this->displayTitle()} belonged to is gone or in the Recycle bin. Restore that record first.";
        }

        return null;
    }
}
