<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\Approvable;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Models\ApprovalWorkflow;
use App\Modules\Foundation\Scopes\BusinessScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A discount agreed at a customer's door that is above the confirming
 * person's cap, waiting for the Owner (courier hub slice 8, ruling 3).
 *
 * ⚠⚠ NOTHING IS POSTED WHILE IT WAITS. The whole courier answer (outcome,
 * date, collected amount, lines, note) is kept here as it was entered, and the
 * parcel stays with the courier in the books. The Owner's yes records it in
 * one transaction with the approval (ApproveCourierDoorDiscount); a no leaves
 * the parcel open for its answer to be recorded another way.
 */
class CourierDoorDiscountRequest extends Model
{
    use Approvable, BelongsToBusiness, LogsActivity;

    public const WORKFLOW = 'courier_door_discount';

    protected $with = ['approvalRequest'];

    protected $fillable = [
        'business_id',
        'consignment_id',
        'payload',
        'amount_due',
        'collected_amount',
        'discount_amount',
        'reason',
        'requested_by',
        'applied_at',
        'applied_by',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'amount_due' => 'decimal:4',
            'collected_amount' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'applied_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['consignment_id', 'amount_due', 'collected_amount', 'discount_amount', 'reason', 'applied_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function approvalWorkflowKey(): string
    {
        return self::WORKFLOW;
    }

    /**
     * The Owner-only workflow, made the first time a business needs it, so a
     * deploy that skips the seeder still routes the request to the Owner.
     */
    public static function ensureWorkflow(int $businessId): void
    {
        $workflow = ApprovalWorkflow::withoutGlobalScope(BusinessScope::class)->firstOrCreate(
            ['business_id' => $businessId, 'key' => self::WORKFLOW],
            ['name' => 'Courier door discount approval', 'is_active' => true],
        );

        if ($workflow->stages()->count() === 0) {
            $workflow->stages()->create([
                'sequence' => 1,
                'name' => 'Owner approval',
                'allowed_roles' => ['Owner'],
                'allowed_permissions' => [],
            ]);
        }
    }

    public function consignment(): BelongsTo
    {
        return $this->belongsTo(Consignment::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function discount(): Money
    {
        return Money::ofTaka((string) $this->discount_amount);
    }

    public function isWaiting(): bool
    {
        return $this->applied_at === null && ($this->approvalRequest?->isPending() ?? false);
    }
}
