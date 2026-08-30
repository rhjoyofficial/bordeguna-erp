<?php

namespace App\Modules\Foundation\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Models\User;
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
 */
class Attachment extends Model
{
    use BelongsToBusiness, HasFactory;

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
        'version',
        'superseded_by_id',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'expires_at' => 'date',
            'document_date' => 'date',
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
}
