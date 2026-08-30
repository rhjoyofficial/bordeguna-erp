<?php

namespace App\Modules\Foundation\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One question put to the assistant.
 *
 * ⚠⚠ THE QUESTION IS KEPT, THE ANSWER IS NOT. An answer can contain rows the
 * asker was entitled to see at that moment and may not be entitled to later.
 * Storing it would build a second copy of the data sitting outside every
 * permission check that guarded the first.
 */
class AssistantMessage extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'user_id', 'question', 'language', 'tools_used', 'answered'];

    protected function casts(): array
    {
        return ['tools_used' => 'array', 'answered' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * How many questions this business has asked this calendar month.
     *
     * ⚠ Calendar month, not a rolling window. It is the unit the provider bills
     * in and the unit somebody checking a bill will be thinking in, and a
     * rolling window makes "why did it stop today" much harder to answer.
     */
    public static function usedThisMonth(int $businessId): int
    {
        return static::withoutGlobalScopes()
            ->where('business_id', $businessId)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();
    }
}
