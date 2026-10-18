<?php

namespace App\Modules\Purchasing\Models;

use App\Models\User;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Concerns\Approvable;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Purchasing\Enums\LandedCostStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A landed-cost document: costs that arrived beside or after a goods receipt
 * (packaging, freight, unloading labour, bank charges) and what became of each
 * (Phase 1 narrow landed cost, 17 Oct 2026, ChatGPT ruling Q4 to Q6).
 *
 * Linked to ONE goods receipt. Lines decide treatment and allocation each;
 * allocations keep the per-batch evidence of what was capitalised, how much
 * of it went onto stock still on hand and how much to cost of sales.
 *
 * Approvable like a purchase order (workflow key `landed_cost`, stage
 * permission purchasing.landed_cost.post). Posting is a separate, explicit
 * step after approval: money and stock values move only then.
 */
class LandedCost extends Model
{
    use Approvable, BelongsToBusiness, LogsActivity;

    protected $with = ['approvalRequest'];

    protected $fillable = [
        'business_id',
        'number',
        'goods_receipt_id',
        // ⭐ Ruling P5 (19 Oct 2026): the supplier bill the carriage was typed on.
        'supplier_bill_id',
        'status',
        'incurred_on',
        'note',
        'total_paisa',
        'journal_id',
        'posted_at',
        'posted_by',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => LandedCostStatus::class,
            'incurred_on' => 'date:Y-m-d',
            'total_paisa' => 'integer',
            'posted_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'incurred_on', 'total_paisa', 'goods_receipt_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(LandedCostLine::class)->orderBy('sort')->orderBy('id');
    }

    public function allocations(): HasManyThrough
    {
        return $this->hasManyThrough(LandedCostAllocation::class, LandedCostLine::class);
    }

    /**
     * The supplier bills this landed cost raised when it posted, one per
     * supplier it is owed to (18 Oct 2026, finding P3): what the "Pay a
     * supplier" screen settles.
     */
    public function bills(): HasMany
    {
        return $this->hasMany(SupplierBill::class);
    }

    /** ⭐ Ruling P5: the supplier bill whose carriage line raised this landed cost. */
    public function sourceBill(): BelongsTo
    {
        return $this->belongsTo(SupplierBill::class, 'supplier_bill_id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function isDraft(): bool
    {
        return $this->status === LandedCostStatus::Draft;
    }

    public function isPosted(): bool
    {
        return $this->status === LandedCostStatus::Posted;
    }

    /** A draft whose approval request is still waiting: locked against edits. */
    public function isAwaitingApproval(): bool
    {
        return $this->isDraft() && $this->approvalRequest !== null && $this->approvalRequest->isPending();
    }

    public function isEditable(): bool
    {
        return $this->isDraft() && ! $this->isAwaitingApproval();
    }
}
