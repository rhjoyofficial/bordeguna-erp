import { ActionDialog } from '@/components/action-dialog';
import { BlockedAction, type Availability, type ReasonOption } from '@/components/purchasing/correction-actions';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/lib/i18n';
import { Link } from '@inertiajs/react';

/**
 * ⭐ Corrections wave 2, accounts (B7, 24 Oct 2026): Correct and Reverse on a
 * posted accounts record (voucher, transfer, manual journal, adjustment), as
 * its policy drew them. A blocked button stays visible, greyed, with its
 * reason (FINAL-PLAN 2). Reverse opens the one ActionDialog, which previews the
 * date, the effect and what stays; Correct opens the record's own form
 * pre-filled (`correctUrl`), or `onCorrect` for a form in a popup.
 *
 * ⚠ The server decides everything again; this draws, it never decides.
 */

export type AccountsCorrections = {
    type: string;
    actions: Availability[];
    correction_of: { number: string; url: string | null } | null;
    corrected_into: { number: string; url: string | null } | null;
    reasons: { reversal: ReasonOption[]; correction: ReasonOption[]; fix_details: ReasonOption[] };
    allow_today?: boolean;
};

type Props = {
    corrections: AccountsCorrections | null;
    recordId: number;
    recordLabel: string;
    correctUrl?: string;
    onCorrect?: () => void;
    /** Where to land after a Reverse (a path on this site); default: stay. */
    redirectTo?: string;
};

export function CorrectionButtons({ corrections, recordId, recordLabel, correctUrl, onCorrect, redirectTo }: Props) {
    const { t } = useTranslation();

    if (!corrections) {
        return null;
    }

    const find = (action: string) => corrections.actions.find((a) => a.action === action);
    const correct = find('correct');
    const reverse = find('reverse');
    const base = `/records/${corrections.type}/${recordId}`;

    return (
        <div className="flex flex-wrap items-start gap-2">
            {correct &&
                (correct.allowed || correct.requestable ? (
                    onCorrect ? (
                        <Button type="button" size="sm" variant="outline" onClick={onCorrect}>
                            {t('corr.acc.correct')}
                        </Button>
                    ) : correctUrl ? (
                        <Button asChild size="sm" variant="outline">
                            <Link href={correctUrl}>{t('corr.acc.correct')}</Link>
                        </Button>
                    ) : null
                ) : (
                    <BlockedAction availability={correct} label={t('corr.acc.correct')} />
                ))}
            {reverse &&
                (reverse.allowed || reverse.requestable ? (
                    <ActionDialog
                        trigger={
                            <Button type="button" size="sm" variant="outline">
                                {t('corr.acc.reverse')}
                            </Button>
                        }
                        action="reverse"
                        recordLabel={recordLabel}
                        previewUrl={`${base}/reverse/preview`}
                        submitUrl={`${base}/reverse`}
                        reasons={corrections.reasons.reversal}
                        allowToday={corrections.allow_today ?? false}
                        redirectTo={redirectTo}
                    />
                ) : (
                    <BlockedAction availability={reverse} label={t('corr.acc.reverse')} />
                ))}
        </div>
    );
}

/** "Correction of PV-0041" on a successor, "Corrected. See PV-0057." on an original. */
export function CorrectionChainLabel({ corrections }: { corrections: AccountsCorrections | null }) {
    const { t } = useTranslation();

    if (!corrections) {
        return null;
    }

    const link = (text: string, url: string | null) =>
        url ? (
            <a href={url} className="underline">
                {text}
            </a>
        ) : (
            text
        );

    return (
        <>
            {corrections.correction_of && (
                <span className="rounded bg-sky-100 px-2 py-0.5 text-xs text-sky-900 dark:bg-sky-950 dark:text-sky-200">
                    {link(t('corr.acc.correction_of', { number: corrections.correction_of.number }), corrections.correction_of.url)}
                </span>
            )}
            {corrections.corrected_into && (
                <span className="rounded bg-amber-100 px-2 py-0.5 text-xs text-amber-900 dark:bg-amber-950 dark:text-amber-200">
                    {link(t('corr.acc.corrected_into', { number: corrections.corrected_into.number }), corrections.corrected_into.url)}
                </span>
            )}
        </>
    );
}
