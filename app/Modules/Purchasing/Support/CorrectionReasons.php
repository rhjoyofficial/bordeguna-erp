<?php

namespace App\Modules\Purchasing\Support;

use App\Modules\Foundation\Models\Reason;

/**
 * ⭐ Corrections wave 1 (19 Oct 2026): the reason pick lists a purchase
 * document's action dialogs offer (Settings > Reasons), by context.
 */
final class CorrectionReasons
{
    /**
     * @param  list<string>  $contexts
     * @return array<string, list<array{id: int, name: string}>>
     */
    public static function for(array $contexts): array
    {
        $lists = [];

        foreach ($contexts as $context) {
            $lists[$context] = Reason::query()->active()->forContext($context)->orderBy('id')->get(['id', 'name'])
                ->map(fn (Reason $reason) => ['id' => (int) $reason->id, 'name' => (string) $reason->name])
                ->all();
        }

        return $lists;
    }
}
