import {
    BlockedAction,
    CorrectionReasonDialog,
    type ActionSetProps,
    type Availability,
    type ReasonOption,
} from '@/components/purchasing/correction-actions';
import { RecordHistory } from '@/components/record-history';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/lib/i18n';
import { useState } from 'react';

/**
 * ⭐ Corrections wave 3, HRM (24 Oct 2026): the buttons an HR record draws from
 * its policy (LeaveRequest-, Movement-, Employee- and ShowCauseNoticeActionPolicy)
 * and the one reason dialog for each. Nothing here decides anything: the
 * server asks the policy again, and a blocked button stays visible, greyed,
 * with its sentence (FINAL-PLAN 2, screen rules).
 *
 * ⚠ An action whose approval is `second_person` does not happen on the click:
 * it is sent for approval, so the confirm button says so.
 *
 * ⚠ No <form> and no key handling (Joy, 19 Oct 2026): Enter never submits.
 */

export type HrActions = Omit<ActionSetProps, 'actions'> & { actions: (Availability & { approval?: string })[] };

type Kind = 'leave' | 'movement' | 'employee' | 'notice';

const today = () => {
    const now = new Date();
    const pad = (n: number) => String(n).padStart(2, '0');

    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
};

export function HrCorrectionActions({
    kind,
    id,
    label,
    actions,
    reasons,
    url,
    historyType,
    documentLabel,
}: {
    kind: Kind;
    id: number;
    label: string;
    actions: HrActions;
    reasons: Record<string, ReasonOption[]>;
    /** Where the action posts. */
    url: string;
    /** Registry key for the History tab, when the screen shows it. */
    historyType?: string;
    documentLabel?: string;
}) {
    const { t } = useTranslation();
    const [withdrawnOn, setWithdrawnOn] = useState(today());

    const buttons = actions.actions.map((availability) => {
        const waits = availability.approval === 'second_person';
        const verb =
            {
                cancel: t('corr.hrm.cancel'),
                withdraw: t('corr.hrm.withdraw'),
                delete: t('corr.hrm.delete'),
                reverse: t('corr.hrm.undo_separation'),
            }[availability.action] ?? availability.label;

        if (!availability.allowed) {
            return <BlockedAction key={availability.action} availability={availability} label={verb} />;
        }

        const title = {
            leave: t('corr.hrm.cancel_leave_title', { record: label }),
            movement: t('corr.hrm.cancel_movement_title', { record: label }),
            employee:
                availability.action === 'delete'
                    ? t('corr.hrm.delete_employee_title', { record: label })
                    : t('corr.hrm.undo_title', { record: label }),
            notice: t('corr.hrm.withdraw_notice_title', { record: label }),
        }[kind];

        const description = {
            leave: waits ? t('corr.hrm.cancel_leave_waits') : t('corr.hrm.cancel_leave_text'),
            movement: waits ? t('corr.hrm.cancel_movement_waits') : t('corr.hrm.cancel_movement_text'),
            employee: availability.action === 'delete' ? t('corr.hrm.delete_employee_text') : t('corr.hrm.undo_text'),
            notice: t('corr.hrm.withdraw_notice_text'),
        }[kind];

        return (
            <CorrectionReasonDialog
                key={availability.action}
                trigger={
                    <Button size="sm" variant="outline">
                        {verb}
                    </Button>
                }
                title={title}
                description={description}
                reasons={reasons[availability.reason_context ?? ''] ?? []}
                submitUrl={url}
                confirmLabel={waits ? t('corr.hrm.send_for_approval') : verb}
                destructive={!waits && availability.action !== 'withdraw'}
                data={kind === 'notice' ? () => ({ withdrawn_on: withdrawnOn }) : undefined}
            >
                {kind === 'notice' && (
                    // Date first (Joy, 19 Oct 2026).
                    <div className="grid gap-1">
                        <Label htmlFor={`withdrawn-on-${id}`} required>
                            {t('corr.hrm.withdrawn_on')}
                        </Label>
                        <Input
                            id={`withdrawn-on-${id}`}
                            type="date"
                            className="w-44"
                            value={withdrawnOn}
                            max={today()}
                            onChange={(e) => setWithdrawnOn(e.target.value)}
                        />
                    </div>
                )}
            </CorrectionReasonDialog>
        );
    });

    if (buttons.length === 0 && !historyType) {
        return null;
    }

    return (
        <span className="inline-flex flex-wrap items-start justify-end gap-2">
            {buttons}
            {historyType && <RecordHistory type={historyType} id={id} label={documentLabel ?? label} />}
        </span>
    );
}
