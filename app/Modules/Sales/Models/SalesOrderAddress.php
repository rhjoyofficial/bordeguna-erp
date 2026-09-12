<?php

namespace App\Modules\Sales\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where the goods go.
 *
 * ⚠⚠ IT BELONGS TO THE ORDER, NOT TO THE WEBSITE. A phone order typed into the
 * ERP has a destination for exactly the same reason a web order does, and
 * LaLaDia's admin order path carried no district or thana ANYWHERE - so a parcel
 * booked from a telephone call reached the courier with nothing to route on
 * while the web checkout next door had required both for a week. One table, every
 * channel.
 *
 * ⚠⚠ THE POSTCODE IS DERIVED, NEVER POSTED - and it is derived in exactly one
 * place, `SalesOrderDestination`, which is what validates the district and thana
 * it comes from. This model deliberately has no helper of its own: two places
 * working out a postcode is two answers waiting to disagree, and the one that
 * has not checked the pair would be the wrong one.
 */
class SalesOrderAddress extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'sales_order_id',
        'recipient_name',
        'recipient_phone',
        'district',
        'thana',
        'area',
        'postcode',
        'address_line',
        'landmark',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    /**
     * The destination as one line, for a document or a courier form.
     *
     * ⚠ Composed rather than stored. A stored one drifts from its parts the
     * first time somebody corrects a thana, and then two fields on the same
     * screen disagree about where a parcel is going.
     */
    public function oneLine(): string
    {
        return collect([
            $this->address_line,
            $this->landmark,
            $this->area,
            $this->thana,
            $this->district,
            $this->postcode,
        ])->filter(fn (?string $part) => $part !== null && trim($part) !== '')->implode(', ');
    }
}
