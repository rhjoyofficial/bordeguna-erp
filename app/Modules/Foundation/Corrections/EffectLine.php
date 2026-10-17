<?php

namespace App\Modules\Foundation\Corrections;

/** One sentence of "what this does", e.g. "Tk 5,000 comes out of BRAC Bank." */
final readonly class EffectLine
{
    public function __construct(
        public string $sentence,
        public ?string $account = null,
        public ?int $deltaMinor = null,
        public ?string $kind = null,
    ) {}

    public function toArray(): array
    {
        return ['sentence' => $this->sentence, 'account' => $this->account, 'delta_minor' => $this->deltaMinor, 'kind' => $this->kind];
    }
}
