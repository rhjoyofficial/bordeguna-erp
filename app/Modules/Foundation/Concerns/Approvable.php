<?php

namespace App\Modules\Foundation\Concerns;

use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Foundation\Models\ApprovalRequest;
use App\Modules\Foundation\Models\ApprovalWorkflow;
use App\Modules\Foundation\Scopes\BusinessScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Str;

/**
 * For models that need a pending → approved/rejected review before taking
 * effect (see StockRequest). Attaches one ApprovalRequest per model instead
 * of each module reinventing its own status/requested_by/reviewed_by columns.
 *
 * The ApprovalRequest is a multi-stage workflow instance: on submission we
 * resolve the business's configured workflow for this model's key and attach
 * it. If none is configured the request simply has no workflow and behaves as a
 * single stage — so this trait's contract is unchanged for existing consumers.
 */
trait Approvable
{
    public function approvalRequest(): MorphOne
    {
        return $this->morphOne(ApprovalRequest::class, 'approvable');
    }

    public function submitForApproval(User $requestedBy): ApprovalRequest
    {
        $workflow = ApprovalWorkflow::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business_id)
            ->where('key', $this->approvalWorkflowKey())
            ->where('is_active', true)
            ->first();

        return $this->approvalRequest()->create([
            'business_id' => $this->business_id,
            'workflow_id' => $workflow?->id,
            'current_stage' => 1,
            'status' => ApprovalStatus::Pending,
            'requested_by' => $requestedBy->id,
        ]);
    }

    /**
     * The workflow definition key this model's approvals map to. Defaults to the
     * snake-cased class name (StockRequest → stock_request, PurchaseOrder →
     * purchase_order); override to point at a differently-named workflow.
     */
    public function approvalWorkflowKey(): string
    {
        return Str::snake(class_basename($this));
    }
}
