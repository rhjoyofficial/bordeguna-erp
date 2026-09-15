<?php

namespace App\Modules\Crm\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

/**
 * A thana a distributor says it covers.
 *
 * ⚠ A CLAIM, not a territory (Joy, answer 12: territory is not decided). There
 * is no exclusivity and no overlap warning, and two distributors may both claim
 * Mirpur.
 */
class LeadCoverageArea extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'lead_id', 'district', 'thana'];
}
