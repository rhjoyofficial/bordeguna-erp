<?php

namespace App\Modules\Foundation\Corrections;

use App\Modules\Foundation\Models\CorrectionLink;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Walks a correction chain: original -> successor -> its successor
 * (PHASE-0-CONTRACT 1.7). Read-only.
 */
final class CorrectionChain
{
    private const MAX_STEPS = 50;

    /** The first document of the chain. */
    public static function root(Model $document): Model
    {
        $current = $document;

        for ($i = 0; $i < self::MAX_STEPS; $i++) {
            $link = self::linkAsSuccessor($current);

            if ($link === null || ($original = self::resolve($link->original_type, (int) $link->original_id)) === null) {
                return $current;
            }

            $current = $original;
        }

        return $current;
    }

    /** The live end of the chain. */
    public static function latest(Model $document): Model
    {
        $current = $document;

        for ($i = 0; $i < self::MAX_STEPS; $i++) {
            $link = self::linkAsOriginal($current);

            if ($link === null || $link->successor_id === null || ($next = self::resolve((string) $link->successor_type, (int) $link->successor_id)) === null) {
                return $current;
            }

            $current = $next;
        }

        return $current;
    }

    /** @return list<CorrectionLink> oldest first */
    public static function links(Model $document): array
    {
        $links = [];
        $current = self::root($document);

        for ($i = 0; $i < self::MAX_STEPS; $i++) {
            $link = self::linkAsOriginal($current);

            if ($link === null) {
                break;
            }

            $links[] = $link;

            if ($link->successor_id === null || ($next = self::resolve((string) $link->successor_type, (int) $link->successor_id)) === null) {
                break;
            }

            $current = $next;
        }

        return $links;
    }

    /** "type:id" of every document in the chain, for duplicate exclusion. @return list<string> */
    public static function keys(Model $document): array
    {
        $keys = [CorrectionLink::liveKeyFor($document)];

        foreach (self::links($document) as $link) {
            $keys[] = $link->original_type.':'.$link->original_id;

            if ($link->successor_id !== null) {
                $keys[] = $link->successor_type.':'.$link->successor_id;
            }
        }

        return array_values(array_unique($keys));
    }

    /** "Correction of INC-2026-27-0007", or null for a document that corrects nothing. */
    public static function correctionOfLabel(Model $successor): ?string
    {
        $link = self::linkAsSuccessor($successor);

        return $link === null ? null : "Correction of {$link->original_number}";
    }

    public static function linkAsOriginal(Model $document): ?CorrectionLink
    {
        return CorrectionLink::query()
            ->where('original_type', $document->getMorphClass())
            ->where('original_id', $document->getKey())
            ->first();
    }

    public static function linkAsSuccessor(Model $document): ?CorrectionLink
    {
        return CorrectionLink::query()
            ->where('successor_type', $document->getMorphClass())
            ->where('successor_id', $document->getKey())
            ->first();
    }

    private static function resolve(string $type, int $id): ?Model
    {
        $class = Relation::getMorphedModel($type) ?? $type;

        return class_exists($class) ? $class::query()->find($id) : null;
    }
}
