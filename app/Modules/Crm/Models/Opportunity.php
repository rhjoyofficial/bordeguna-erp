<?php

namespace App\Modules\Crm\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

/**
 * A deal on the pipeline board (crm_opportunities).
 *
 * ⭐ Corrections wave 3 (24 Oct 2026). The board itself still reads and writes
 * the table directly (OpportunityController); this model exists so a reopened
 * deal has a subject for its History line (ReopenOpportunity, CorrectionLog)
 * and so the reopen is read through the business scope. Nothing is fillable:
 * the board's own writes remain the only ones.
 */
class Opportunity extends Model
{
    use BelongsToBusiness;

    protected $table = 'crm_opportunities';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'won_at' => 'datetime',
            'lost_at' => 'datetime',
            'value' => 'decimal:2',
        ];
    }
}
