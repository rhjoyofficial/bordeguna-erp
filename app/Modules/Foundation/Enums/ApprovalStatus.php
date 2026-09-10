<?php

namespace App\Modules\Foundation\Enums;

/**
 * ⚠⚠ `Returned` IS NOT A THIRD WAY OF SAYING NO. Rejected is final: the request
 * is over, nothing moves, and the record stands as proof somebody asked and was
 * turned down. Returned hands the document BACK to the person who raised it, so
 * they can fix the wrong figure or the missing receipt and send the same
 * request again. Both stop it being pending; only one of them can come back.
 *
 * ⚠ The engine is shared by twelve document types, so a returned request is now
 * *possible* everywhere. It only appears where a resubmission path exists -
 * expense claims today - because a document with no way back would be stranded
 * in a status nobody can clear.
 */
enum ApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Returned = 'returned';
}
