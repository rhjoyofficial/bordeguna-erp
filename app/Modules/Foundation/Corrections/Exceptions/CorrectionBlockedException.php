<?php

namespace App\Modules\Foundation\Corrections\Exceptions;

use App\Modules\Foundation\Corrections\Blocker;
use RuntimeException;

/** The action is not allowed; the blockers say why in plain sentences. Nothing was written. */
class CorrectionBlockedException extends RuntimeException
{
    /** @param list<Blocker> $blockers */
    public function __construct(public readonly array $blockers)
    {
        parent::__construct(implode(' ', array_map(fn (Blocker $b) => $b->message, $blockers)));
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_map(fn (Blocker $b) => $b->code, $this->blockers);
    }
}
