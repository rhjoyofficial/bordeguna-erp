import { type Availability, BlockedAction, CorrectionReasonDialog, type ReasonOption } from '@/components/purchasing/correction-actions';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/lib/i18n';
import { type FormDataConvertible } from '@inertiajs/core';
import { type ReactNode } from 'react';

/**
 * ⭐ Corrections wave 2, B8 (24 Oct 2026): one button for an action a module
 * runs itself (Cancel approval, Reverse payment, Cancel, Dispose), drawn from
 * the record's policy. Allowed: the reason dialog, with the date sentence the
 * server worked out. Refused: the button stays, greyed, with its one-sentence
 * reason (FINAL-PLAN 2, screen rules).
 *
 * ⚠ No <form> and no key handling (Joy, 19 Oct 2026): Enter never submits.
 * The server checks everything again.
 */
export function PlainActionButton({
    availability,
    label,
    title,
    description,
    submitUrl,
    reasons,
    confirmLabel,
    destructive = false,
    children,
    data,
}: {
    availability: Availability | undefined;
    label: string;
    title: string;
    description: string;
    submitUrl: string;
    reasons: ReasonOption[];
    confirmLabel?: string;
    destructive?: boolean;
    /** The action's own fields, drawn first (dates first, Joy 19 Oct). */
    children?: ReactNode;
    data?: () => Record<string, FormDataConvertible>;
}) {
    const { t } = useTranslation();

    if (!availability) {
        return null;
    }

    if (!availability.allowed) {
        return <BlockedAction availability={availability} label={label} />;
    }

    return (
        <CorrectionReasonDialog
            trigger={
                <Button size="sm" variant="outline">
                    {label}
                </Button>
            }
            title={title}
            description={description}
            reasons={reasons}
            submitUrl={submitUrl}
            confirmLabel={confirmLabel ?? label}
            destructive={destructive}
            data={data}
        >
            {children}
            {availability.date?.sentence && (
                <p>
                    <span className="font-medium">{t('corr.people.lands')} </span>
                    {availability.date.sentence}
                </p>
            )}
        </CorrectionReasonDialog>
    );
}
