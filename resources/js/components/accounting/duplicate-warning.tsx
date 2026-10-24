import { Button } from '@/components/ui/button';
import { useTranslation } from '@/lib/i18n';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';

/**
 * ⭐ Corrections wave 2 (24 Oct 2026; FINAL-PLAN 5): "Possible duplicate" on a
 * money form. The server refused the save with `_duplicate` and flashed the
 * candidates with an ack token; "Save anyway" sends the form again with that
 * token, bound to this person and exactly what was typed. Change the form and
 * the server asks again.
 *
 * Draw it where the form's errors are; it shows only after a refused save.
 *
 * ⭐ B13 (24 Oct 2026): a popup that saves with fetch (the product popup) has
 * no flash; it passes the warning from the 422 body as `warning` instead.
 */
export function DuplicateWarning({
    errors,
    busy,
    onSaveAnyway,
    warning,
}: {
    errors: Record<string, string | undefined>;
    busy?: boolean;
    onSaveAnyway: (ack: string) => void;
    warning?: SharedData['duplicateWarning'] | null;
}) {
    const { t } = useTranslation();
    const shared = usePage<SharedData>().props.duplicateWarning;
    const duplicateWarning = warning ?? shared;

    if (!errors._duplicate || !duplicateWarning) {
        return null;
    }

    return (
        <div className="grid gap-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-700 dark:bg-amber-950/40">
            <p className="font-medium">{t('corr.acc.possible_duplicate')}</p>
            {duplicateWarning.candidates.map((candidate, index) =>
                candidate.number === null ? (
                    <p key={`hidden-${index}`}>{t('corr.acc.hidden_duplicate')}</p>
                ) : (
                    <p key={`${candidate.number}-${index}`}>
                        {candidate.summary}
                        {candidate.saved_by ? ` ${t('corr.acc.saved_by', { ago: candidate.saved_ago ?? '', by: candidate.saved_by })}` : ''} (
                        {candidate.number}).{' '}
                        {candidate.url && (
                            <a href={candidate.url} target="_blank" rel="noreferrer" className="underline">
                                {t('corr.acc.open')} {candidate.number}
                            </a>
                        )}
                    </p>
                ),
            )}
            <p>{t('corr.acc.is_different')}</p>
            <div>
                <Button type="button" size="sm" variant="outline" disabled={busy} onClick={() => onSaveAnyway(duplicateWarning.ack_token)}>
                    {t('corr.acc.save_anyway')}
                </Button>
            </div>
        </div>
    );
}
