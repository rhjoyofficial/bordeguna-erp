import { ActionDialog } from '@/components/action-dialog';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/lib/i18n';
import { Link } from '@inertiajs/react';

/**
 * Corrections wave 1, B2 (20 Oct 2026): the Correct and Reverse buttons of a
 * supplier bill, payment, credit note or purchase return, and its place in a
 * correction chain. What the server's corrections policy said, drawn as is:
 * a blocked button stays visible, greyed, with its reason underneath.
 *
 * ⚠ Nothing here decides: every preview and every submit is checked again.
 * ⚠ No keyboard shortcuts (Joy, 19 Oct): everything happens by clicking.
 */

export type CorrectionAvailability = {
    action: string;
    allowed: boolean;
    requestable: boolean;
    blockers: { code: string; message: string; url: string | null }[];
    primary: boolean;
    date: { rule: string; sentence?: string } | null;
};

export type CorrectionChainLink = { number: string; url: string | null } | null;

export type CorrectionsProps = {
    type: string;
    actions: CorrectionAvailability[];
    correction_of: CorrectionChainLink;
    corrected_into: CorrectionChainLink;
    reasons: Record<'reversal' | 'correction' | 'fix_details', { id: number; name: string }[]>;
};

export function CorrectionChainNote({ corrections }: { corrections?: CorrectionsProps | null }) {
    const { t } = useTranslation();

    if (!corrections || (!corrections.correction_of && !corrections.corrected_into)) {
        return null;
    }

    const link = (to: NonNullable<CorrectionChainLink>, text: string) =>
        to.url ? (
            <Link href={to.url} className="underline">
                {text}
            </Link>
        ) : (
            <span>{text}</span>
        );

    return (
        <div className="flex flex-wrap gap-2 text-xs">
            {corrections.correction_of && (
                <span className="rounded border px-2 py-0.5">
                    {link(corrections.correction_of, t('corr.sup.correction_of', { number: corrections.correction_of.number }))}
                </span>
            )}
            {corrections.corrected_into && (
                <span className="border-destructive/40 text-destructive rounded border px-2 py-0.5">
                    {link(corrections.corrected_into, t('corr.sup.corrected_into', { number: corrections.corrected_into.number }))}
                </span>
            )}
        </div>
    );
}

export function CorrectionButtons({
    corrections,
    documentId,
    number,
    correctUrl,
    redirectTo,
}: {
    corrections?: CorrectionsProps | null;
    documentId: number;
    number: string;
    /** The Correct form; omitted where Correct is not offered. */
    correctUrl?: string;
    redirectTo?: string;
}) {
    const { t } = useTranslation();

    if (!corrections) {
        return null;
    }

    const correct = corrections.actions.find((a) => a.action === 'correct');
    const reverse = corrections.actions.find((a) => a.action === 'reverse');
    const usable = (a?: CorrectionAvailability) => !!a && (a.allowed || a.requestable);
    const base = `/records/${corrections.type}/${documentId}`;

    if (!correct && !reverse) {
        return null;
    }

    // One sentence per reason, even when both buttons share it.
    const reasons = [
        ...new Set(
            [correctUrl ? correct : undefined, reverse].filter((a) => a && !usable(a) && a.blockers.length > 0).map((a) => a!.blockers[0].message),
        ),
    ];

    return (
        <div className="flex flex-col items-end gap-1">
            <div className="flex flex-wrap justify-end gap-2">
                {correct &&
                    correctUrl &&
                    (usable(correct) ? (
                        <Button asChild size="sm">
                            <Link href={correctUrl}>{t('corr.sup.correct')}</Link>
                        </Button>
                    ) : (
                        <Button size="sm" disabled title={correct.blockers[0]?.message}>
                            {t('corr.sup.correct')}
                        </Button>
                    ))}
                {reverse &&
                    (usable(reverse) ? (
                        <ActionDialog
                            action="reverse"
                            recordLabel={number}
                            previewUrl={`${base}/reverse/preview`}
                            submitUrl={`${base}/reverse`}
                            reasons={corrections.reasons.reversal}
                            allowToday={reverse.allowed && reverse.date?.rule === 'original'}
                            redirectTo={redirectTo}
                            trigger={
                                <Button type="button" size="sm" variant="outline">
                                    {t('corr.sup.reverse')}
                                </Button>
                            }
                        />
                    ) : (
                        <Button type="button" size="sm" variant="outline" disabled title={reverse.blockers[0]?.message}>
                            {t('corr.sup.reverse')}
                        </Button>
                    ))}
            </div>
            {reasons.map((message) => (
                <p key={message} className="text-muted-foreground max-w-72 text-right text-xs">
                    {message}
                </p>
            ))}
        </div>
    );
}
