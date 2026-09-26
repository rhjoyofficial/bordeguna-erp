<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\Approvable;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Enums\ApprovalStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A batch of credit limits and credit days waiting for the authority's yes.
 *
 * ⭐ Joy, 26 Sep 2026 (Q16): terms come from whoever brought the customer, as a
 * file, and nothing takes effect without approval. The request is the file as
 * a document; the approval request attached to it is the ordinary engine
 * (workflow key `credit_terms_request`, permission `sales.credit.approve`).
 * Approving applies every line in one transaction (ApproveCreditTermsRequest);
 * a rejection leaves every customer exactly as they were.
 */
class CreditTermsRequest extends Model
{
    use Approvable, BelongsToBusiness, LogsActivity;

    protected $with = ['approvalRequest'];

    protected $fillable = [
        'business_id',
        'request_number',
        'requested_by',
        'note',
        'source_file',
        'line_count',
        'applied_at',
        'applied_by',
    ];

    protected function casts(): array
    {
        return [
            'line_count' => 'integer',
            'applied_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['request_number', 'line_count', 'applied_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CreditTermsRequestLine::class)->orderBy('id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    public function status(): ApprovalStatus
    {
        return $this->approvalRequest?->status ?? ApprovalStatus::Pending;
    }

    public function isApplied(): bool
    {
        return $this->applied_at !== null;
    }

    public function statusLabel(): string
    {
        if ($this->isApplied()) {
            return 'Applied';
        }

        return match ($this->status()) {
            ApprovalStatus::Pending => 'Waiting for approval',
            ApprovalStatus::Approved => 'Approved',
            ApprovalStatus::Rejected => 'Rejected',
            ApprovalStatus::Returned => 'Sent back',
        };
    }
}
