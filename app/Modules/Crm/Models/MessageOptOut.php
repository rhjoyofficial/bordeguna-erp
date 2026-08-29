<?php

namespace App\Modules\Crm\Models;

use App\Modules\Crm\Support\PhoneIdentity;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

/**
 * Somebody who asked not to be contacted.
 *
 * ⚠⚠ KEYED ON THE ADDRESS, NOT ON A CONTACT. If this were a column on
 * `contacts`, deleting the row and re-importing the same spreadsheet would
 * resurrect consent and message the person again - and the person would have no
 * idea why, because from their side nothing changed. The register is deliberately
 * able to outlive the phone book.
 */
class MessageOptOut extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'channel', 'address', 'reason'];

    /**
     * The stored form of an address, so a lookup and a write always agree.
     *
     * ⚠ One function, used by BOTH the writer and the reader. Two places
     * lowercasing an email slightly differently is how a suppression list
     * quietly stops suppressing.
     */
    public static function normalise(string $channel, string $address): ?string
    {
        return $channel === 'sms'
            ? PhoneIdentity::normalize($address)
            : (trim($address) === '' ? null : mb_strtolower(trim($address)));
    }
}
