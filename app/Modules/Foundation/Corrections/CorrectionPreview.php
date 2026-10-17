<?php

namespace App\Modules\Foundation\Corrections;

/**
 * What the dialog shows before the confirm button is enabled (PHASE-0-CONTRACT
 * 1.3): the date, the effect, what stays. Writes nothing.
 */
final readonly class CorrectionPreview
{
    /**
     * @param  list<EffectLine>  $effects
     * @param  list<string>  $stays
     * @param  list<Blocker>  $blockers
     * @param  list<string>  $warnings
     */
    public function __construct(
        public ?string $previewToken,
        public string $documentFingerprint,
        public CorrectionDates $dates,
        public array $effects,
        public array $stays,
        public array $blockers,
        public ApprovalRoute $approval,
        public int $amountBasisMinor,
        public ?DuplicateWarning $duplicate,
        public array $warnings,
        public string $title,
        public string $confirmLabel,
    ) {}

    public function toArray(): array
    {
        return [
            'preview_token' => $this->previewToken,
            'title' => $this->title,
            'confirm_label' => $this->confirmLabel,
            'date' => $this->dates->sentence,
            'effects' => array_map(fn (EffectLine $line) => $line->sentence, $this->effects),
            'stays' => $this->stays,
            'blockers' => array_map(fn (Blocker $b) => $b->toArray(), $this->blockers),
            'approval' => $this->approval->value,
            'amount_basis' => Tk::of($this->amountBasisMinor),
            'duplicate' => $this->duplicate?->toArray(),
            'warnings' => $this->warnings,
        ];
    }
}
