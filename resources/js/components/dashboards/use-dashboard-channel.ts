import { dashboardSound } from '@/lib/dashboard-sound';
import { echo, ensureEcho, watchConnection } from '@/lib/echo';
import { useTranslation, type TranslationKey } from '@/lib/i18n';
import type { ConnectionStatus } from 'laravel-echo';
import { useEffect, useRef, useState } from 'react';
import type { DashboardEvent } from './types';

const EVENT = '.dashboard.event';

/** What each event says out loud (no figures: the payload carries none), in the reader's language. */
const MESSAGES: Record<string, TranslationKey> = {
    'sales.order.created': 'dash.event.sales_order_created',
    'sales.order.approved': 'dash.event.sales_order_approved',
    'sales.payment.received': 'dash.event.sales_payment_received',
    'approval.requested': 'dash.event.approval_requested',
    'approval.decided': 'dash.event.approval_decided',
    'stock.low': 'dash.event.stock_low',
    'expense_claim.submitted': 'dash.event.expense_claim_submitted',
    'leave.decided': 'dash.event.leave_decided',
    'payslip.ready': 'dash.event.payslip_ready',
    'courier.parcel_status_changed': 'dash.event.courier_parcel_status_changed',
    'courier.booking_failed': 'dash.event.courier_booking_failed',
    'integration.sync_error': 'dash.event.integration_sync_error',
    'courier.statement_imported': 'dash.event.courier_statement_imported',
    'courier.statement_posted': 'dash.event.courier_statement_posted',
    'courier.booking_unknown': 'dash.event.courier_booking_unknown',
};

/**
 * Listens on the business channel and the person's own channel, refreshes the
 * widgets an event names at once, and plays its sound.
 *
 * ⚠ Sound only when somebody ELSE did it (actor_id), only for an event that
 * names a widget on this page or is addressed to this person, and through the
 * shared engine (settings, quiet hours, dedupe, one per 3 s, bursts bundled).
 * The widgets themselves re-fetch through their authorised endpoint: the event
 * never carries a figure.
 */
export function useDashboardChannel({
    enabled,
    businessChannel,
    userChannel,
    userId,
    widgetKeys,
    onRefresh,
    onMessage,
}: {
    enabled: boolean;
    businessChannel: string;
    userChannel: string;
    userId: number;
    widgetKeys: string[];
    onRefresh: (keys: string[], options: { silent: boolean }) => void;
    onMessage?: (message: string) => void;
}): ConnectionStatus | 'off' {
    const { t } = useTranslation();
    const translate = useRef(t);
    translate.current = t;
    const [status, setStatus] = useState<ConnectionStatus | 'off'>(enabled ? 'connecting' : 'off');
    const keysRef = useRef(widgetKeys);
    keysRef.current = widgetKeys;
    const refreshRef = useRef(onRefresh);
    refreshRef.current = onRefresh;
    const messageRef = useRef(onMessage);
    messageRef.current = onMessage;

    useEffect(() => {
        if (!enabled || !ensureEcho()) {
            setStatus('off');

            return;
        }

        const handle = (event: DashboardEvent) => {
            const keys = event.widgets.filter((k) => keysRef.current.includes(k));

            if (keys.length > 0) {
                refreshRef.current(keys, { silent: true });
            }

            const mine = event.actor_id !== null && event.actor_id === userId;

            // 'none' = a routine move (a parcel picked up): refresh, stay quiet.
            if (!mine && event.sound !== 'none' && (keys.length > 0 || event.personal)) {
                const message = MESSAGES[event.type] ? translate.current(MESSAGES[event.type]) : undefined;
                dashboardSound.notify(event.sound, `${event.type}:${JSON.stringify(event.ids)}`);

                if (message && dashboardSound.allows(event.sound)) {
                    messageRef.current?.(message);
                }
            }
        };

        const business = echo().private(businessChannel.replace(/^private-/, ''));
        const personal = echo().private(userChannel.replace(/^private-/, ''));
        business.listen(EVENT, handle);
        personal.listen(EVENT, handle);

        const stop = watchConnection(setStatus);

        // ⚠ Remove only this listener and never leave(): the app shell's live
        // refresh (useLiveReload) listens on the same two channels.
        return () => {
            stop();
            business.stopListening(EVENT, handle);
            personal.stopListening(EVENT, handle);
        };
    }, [enabled, businessChannel, userChannel, userId]);

    return status;
}
