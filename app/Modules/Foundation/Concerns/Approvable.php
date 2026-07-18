<?php

namespace App\Modules\Foundation\Concerns;

use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Foundation\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * For models that need a pending → approved/rejected review before taking
 * effect (see StockRequest). Attaches one ApprovalRequest per model instead
 * of each module reinventing its own status/requested_by/reviewed_by columns.
 */
trait Approvable
{
    public function approvalRequest(): MorphOne
    {
        return $this->morphOne(ApprovalRequest::class, 'approvable');
    }

    public function submitForApproval(User $requestedBy): ApprovalRequest
    {
        return $this->approvalRequest()->create([
            'business_id' => $this->business_id,
            'status' => ApprovalStatus::Pending,
            'requested_by' => $requestedBy->id,
        ]);
    }
}
