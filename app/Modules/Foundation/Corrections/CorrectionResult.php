<?php

namespace App\Modules\Foundation\Corrections;

use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Models\CorrectionLink;
use App\Modules\Foundation\Models\CorrectionRequest;
use Illuminate\Database\Eloquent\Model;

final readonly class CorrectionResult
{
    public function __construct(
        public CorrectionOutcome $outcome,
        public ?CorrectionLink $link,
        public ?CorrectionRequest $request,
        public ?Model $successor,
        public ?Journal $reversalJournal,
        public string $message,
    ) {}
}
