<?php

namespace App\Modules\Hrm\Corrections;

use App\Modules\Foundation\Models\Reason;

/**
 * ⭐ Corrections wave 3 (24 Oct 2026): the reason pick lists the HR action
 * dialogs offer (Settings > Reasons), by context.
 */
final class HrReasons
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
