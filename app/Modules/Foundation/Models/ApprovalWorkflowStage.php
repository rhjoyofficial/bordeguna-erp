<?php

namespace App\Modules\Foundation\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One ordered stage of a workflow. Authorises by role AND/OR permission: a user
 * may act on this stage if they hold any of its allowed roles or any of its
 * allowed permissions. Existing permission-gated flows migrate by listing their
 * approve-permission here; role-named flows list roles.
 */
class ApprovalWorkflowStage extends Model
{
    use HasFactory;

    protected $fillable = [
        'approval_workflow_id',
        'sequence',
        'name',
        'allowed_roles',
        'allowed_permissions',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'allowed_roles' => 'array',
            'allowed_permissions' => 'array',
        ];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(ApprovalWorkflow::class, 'approval_workflow_id');
    }

    /**
     * Whether the user is allowed to act on this stage. Super admins bypass
     * (consistent with the god-mode Gate::before). Otherwise: any allowed role
     * OR any allowed permission passes. Role/permission checks are evaluated in
     * the caller's current business-team context.
     */
    public function allows(User $user): bool
    {
        if ($user->is_super_admin) {
            return true;
        }

        foreach ($this->allowed_roles ?? [] as $role) {
            if ($user->hasRole($role)) {
                return true;
            }
        }

        foreach ($this->allowed_permissions ?? [] as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }
}
