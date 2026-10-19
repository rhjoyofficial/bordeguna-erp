<?php

namespace App\Modules\Purchasing\Support;

use App\Models\User;
use App\Modules\Foundation\Corrections\Contracts\DocumentActionPolicy;
use App\Modules\Foundation\Corrections\CorrectionChain;
use App\Modules\Foundation\Models\Reason;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ Corrections wave 1 (20 Oct 2026): what a supplier money document's page
 * needs to draw its correction buttons and its place in a correction chain.
 *
 *   type            the registry key the /records/{type}/{id} routes take
 *   actions         every action with its blockers (a blocked one stays, greyed)
 *   correction_of   "Correction of Bill INV-7", with a link, on a successor
 *   corrected_into  the corrected record, with a link, on a corrected original
 *   reasons         the reason lists of the dialogs
 *
 * ⚠ The page decides nothing: every endpoint asks the policy again.
 */
final class CorrectionScreen
{
    /** @return array<string, mixed> */
    public static function for(Model $document, DocumentActionPolicy $policy, User $user): array
    {
        $asSuccessor = CorrectionChain::linkAsSuccessor($document);
        $asOriginal = CorrectionChain::linkAsOriginal($document);
        $link = fn (?Model $record, ?string $number) => $record === null || $number === null ? null : ['number' => $number, 'url' => $policy->urlOf($record)];

        return [
            'type' => $policy->type(),
            'actions' => $policy->actionsFor($document, $user)->toArray()['actions'],
            'correction_of' => $asSuccessor === null ? null : $link($asSuccessor->original, $asSuccessor->original_number),
            'corrected_into' => $asOriginal?->successor_id === null ? null : $link($asOriginal->successor, $asOriginal->successor_number),
            'reasons' => [
                'reversal' => self::reasons('reversal'),
                'correction' => self::reasons('correction'),
                'fix_details' => self::reasons('fix_details'),
            ],
        ];
    }

    /** @return list<array{id: int, name: string}> */
    public static function reasons(string $context): array
    {
        return Reason::query()->active()->forContext($context)->orderBy('id')->get(['id', 'name'])
            ->map(fn (Reason $r) => ['id' => (int) $r->id, 'name' => $r->name])->values()->all();
    }
}
