<?php

namespace App\Modules\Crm\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Database\Eloquent\Model;

/**
 * How big a send has to be before somebody has to say yes.
 *
 * ⚠⚠ `approvalAbove()` RETURNS 0 WHEN NOTHING IS CONFIGURED, and 0 means every
 * send is above the threshold, so every send needs approval. That is the safe
 * reading and it is the whole reason this is a method and not a column read.
 * Falling back to "no limit" would turn a blank field into a licence to spend.
 */
class MessagingSetting extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'approval_above'];

    protected function casts(): array
    {
        return ['approval_above' => 'integer'];
    }

    /**
     * The threshold for the business in context, creating nothing.
     */
    public static function approvalAbove(): int
    {
        $row = static::query()->first();

        // ⚠ No row at all is a different fact from a cleared field, and both
        // must be safe. A business nobody has configured yet gets the shipped
        // default of 50 rather than 0, because the alternative is that the
        // module appears broken - every single send, including a test to one
        // person, would demand an approval before anybody had made a decision.
        if ($row === null) {
            return 50;
        }

        return $row->approval_above ?? 0;
    }

    public static function forCurrentBusiness(): self
    {
        return static::firstOrCreate(
            ['business_id' => app(BusinessContext::class)->id()],
            ['approval_above' => 50],
        );
    }
}
