<?php

namespace App\Modules\Accounting\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

/**
 * Hands out the next journal number for a business and fiscal year.
 *
 * ⚠⚠ A ROW TO LOCK. The obvious implementation is `MAX(journal_number) + 1`,
 * which has no row to lock and therefore hands the same number to two concurrent
 * posts. This gives the transaction something to hold.
 *
 * ⚠ The lock is NOT what makes it correct - the re-read inside the transaction
 * is, and the unique index on (business_id, journal_number) is what makes it
 * true regardless. `lockForUpdate` is a NO-OP on SQLite, so a lock-only fix
 * would be green in the whole suite and broken in production. That is the I3
 * lesson, and this is the third place it has been applied.
 */
class JournalSequence extends Model
{
    use BelongsToBusiness;

    /** @var list<string> */
    protected $fillable = ['business_id', 'fiscal_year', 'next_number'];
}
