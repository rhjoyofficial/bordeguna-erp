<?php

namespace App\Modules\Foundation\Corrections;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * The activity-log lines of the corrections module (log name `corrections`),
 * read by the Corrections report and the History tab.
 *
 * ⚠ A blocked attempt is logged AFTER the rollback (v2, C16), so the line
 * survives the refused transaction and no "done" row does. Only a real submit
 * is logged; a preview that shows a grey button is not.
 */
final class CorrectionLog
{
    public const NAME = 'corrections';

    /** @param list<string> $codes */
    public static function blocked(User $user, ?Model $document, string $type, int $documentId, ?string $number, DocumentAction $action, array $codes, string $message): void
    {
        $log = activity(self::NAME)
            ->causedBy($user)
            ->event('blocked_attempt')
            ->withProperties([
                'type' => $type,
                'document_id' => $documentId,
                'number' => $number,
                'action' => $action->value,
                'codes' => $codes,
                'message' => $message,
            ]);

        if ($document !== null) {
            $log->performedOn($document);
        }

        $log->log('blocked_attempt');
    }

    public static function event(string $event, User $user, Model $subject, array $properties): void
    {
        activity(self::NAME)
            ->causedBy($user)
            ->performedOn($subject)
            ->event($event)
            ->withProperties($properties)
            ->log($event);
    }
}
