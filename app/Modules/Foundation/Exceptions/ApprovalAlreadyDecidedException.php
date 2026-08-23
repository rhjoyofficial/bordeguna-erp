<?php

namespace App\Modules\Foundation\Exceptions;

use App\Modules\Foundation\Models\ApprovalRequest;
use RuntimeException;

/**
 * Somebody decided this request while you were looking at it.
 *
 * ⚠⚠ THE BUG THIS EXISTS FOR WAS SILENT, NOT NOISY. Two approvers can both see
 * the same pending request. Before this, one approving and the other rejecting
 * produced no error at all: the second decision overwrote `status`, wrote a
 * second row into `approval_decisions`, and left the underlying thing in
 * whatever state the FIRST decision had already put it in. A leave already
 * applied to the ledger would sit under a request that reads Rejected, and
 * nothing anywhere would say so. Joy reported it as "an error will happen"; the
 * truth was worse, because no error happened.
 */
class ApprovalAlreadyDecidedException extends RuntimeException
{
    public function __construct(
        public readonly ApprovalRequest $request,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function for(ApprovalRequest $request): self
    {
        $who = $request->reviewedBy?->name;
        $verb = $request->status->value;

        return new self($request, $who !== null
            ? sprintf('This request was already %s by %s. Refresh to see where it stands.', $verb, $who)
            : sprintf('This request was already %s by somebody else. Refresh to see where it stands.', $verb));
    }
}
