<?php

namespace App\Modules\Foundation\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A business-owned, configurable approval workflow definition. The engine reads
 * its ordered stages to drive an ApprovalRequest from submission to completion.
 * Config, not code: seeded with defaults, editable per business.
 */
class ApprovalWorkflow extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'business_id',
        'key',
        'name',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function stages(): HasMany
    {
        return $this->hasMany(ApprovalWorkflowStage::class)->orderBy('sequence');
    }

    public function stageCount(): int
    {
        return $this->stages()->count();
    }

    public function stageAt(int $sequence): ?ApprovalWorkflowStage
    {
        return $this->stages()->where('sequence', $sequence)->first();
    }
}
