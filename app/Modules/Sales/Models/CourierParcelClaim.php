<?php

namespace App\Modules\Sales\Models;

use App\Modules\Sales\Support\CourierStatements\CourierStatementFormats;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A parcel paid out, claimed by the business that settled it.
 *
 * ⚠⚠ NOT BUSINESS-SCOPED, ON PURPOSE. Two brands share one courier account, and
 * this table is the one place both can see, so a parcel cannot be settled - or a
 * hand-booked parcel's fee expensed - by both.
 */
class CourierParcelClaim extends Model
{
    protected $fillable = ['claim_key', 'business_id', 'courier_statement_line_id'];

    public static function keyFor(string $provider, string $consignmentNumber): string
    {
        // ⚠ Normalised, so "Pathao Courier" and "pathao" can never hold two
        // claims on one parcel (courier statements v2).
        return CourierStatementFormats::normalise($provider).'|'.strtoupper(trim($consignmentNumber));
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(CourierStatementLine::class, 'courier_statement_line_id')->withoutGlobalScopes();
    }
}
