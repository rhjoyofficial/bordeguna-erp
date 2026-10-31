import { CorrectionReasonDialog, type ReasonOption } from '@/components/purchasing/correction-actions';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/lib/i18n';
import { router } from '@inertiajs/react';
import { useState } from 'react';

/** What the server says one master list row may do (MasterRecords::rows). */
export interface MasterRowState {
    type: string;
    id: number;
    /** null when the list has no on/off switch (categories, units). */
    is_active: boolean | null;
    may: boolean;
    /** Null when never used; otherwise what uses it, in one sentence. */
    delete_blocked: string | null;
    switch_blocked: string | null;
}

/**
 * A blocked button stays visible, greyed (FINAL-PLAN 2). Its reason shows on
 * hover, not as a sentence under every row (Joy, 31 Oct 2026: no clutter).
 */
function Greyed({ label, reason }: { label: string; reason: string }) {
    return (
        <span title={reason} className="inline-block cursor-help">
            <Button size="sm" variant="ghost" disabled>
                {label}
            </Button>
        </span>
    );
}

/**
 * ⭐ B24 (24 Oct 2026, R113; Joy's Q5 = A): the row's buttons by its state.
 *
 *   never used        Delete (to the Recycle bin, with a reason)
 *   used, on          Switch off (with a reason); the sentence says what uses it
 *   used, no switch   Delete greyed with what uses it
 *   switched off      Reactivate (and Delete when never used)
 *
 * Edit stays the list's own popup button, placed before this.
 */
export function MasterRowActions({ state, name, reasons }: { state: MasterRowState | undefined; name: string; reasons: ReasonOption[] }) {
    const { t } = useTranslation();
    const [busy, setBusy] = useState(false);

    if (!state || !state.may) {
        return null;
    }

    const base = `/masters/${state.type}/${state.id}`;
    const used = state.delete_blocked !== null;

    const reactivate = state.is_active === false && (
        <Button
            size="sm"
            variant="ghost"
            disabled={busy}
            onClick={() =>
                router.post(`${base}/reactivate`, {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) })
            }
        >
            {t('master.reactivate')}
        </Button>
    );

    if (!used) {
        return (
            <>
                {reactivate}
                <CorrectionReasonDialog
                    trigger={
                        <Button size="sm" variant="ghost" className="text-destructive">
                            {t('master.delete')}
                        </Button>
                    }
                    title={t('master.delete_title', { name })}
                    description={t('master.delete_help')}
                    reasons={reasons}
                    submitUrl={`${base}/delete`}
                    confirmLabel={t('master.delete')}
                    destructive
                />
            </>
        );
    }

    if (state.is_active === true) {
        return state.switch_blocked ? (
            <Greyed label={t('master.switch_off')} reason={state.switch_blocked} />
        ) : (
            <span title={state.delete_blocked ?? undefined} className="inline-block">
                <CorrectionReasonDialog
                    trigger={
                        <Button size="sm" variant="ghost">
                            {t('master.switch_off')}
                        </Button>
                    }
                    title={t('master.switch_off_title', { name })}
                    description={t('master.switch_off_help')}
                    reasons={reasons}
                    submitUrl={`${base}/switch-off`}
                    confirmLabel={t('master.switch_off')}
                />
            </span>
        );
    }

    return (
        <>
            {reactivate}
            <Greyed label={t('master.delete')} reason={state.delete_blocked ?? ''} />
        </>
    );
}
