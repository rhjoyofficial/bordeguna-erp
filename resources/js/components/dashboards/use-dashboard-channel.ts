import { dashboardSound } from '@/lib/dashboard-sound';
import { echo, ensureEcho, watchConnection } from '@/lib/echo';
import type { ConnectionStatus } from 'laravel-echo';
import { useEffect, useRef, useState } from 'react';
import type { DashboardEvent } from './types';

const EVENT = '.dashboard.event';

/** What each event says out loud (no figures: the payload carries none). */
const MESSAGES: Record<string, string> = {
    'sales.order.created': 'A new sales order came in.',
    'sales.order.approved': 'A sales order was approved.',
    'sales.payment.received': 'A customer payment was recorded.',
    'approval.requested': 'Something was sent for approval.',
    'approval.decided': 'An approval was decided.',
    'stock.low': 'A product reached its low-stock line.',
    'expense_claim.submitted': 'An expense claim was submitted.',
    'leave.decided': 'Your leave request was decided.',
    'payslip.ready': 'Your payslip is ready.',
    'courier.parcel_status_changed': 'A parcel moved.',
    'courier.booking_failed': 'A courier booking failed.',
    'integration.sync_error': 'A sync with an outside service failed.',
    'courier.statement_imported': 'A courier statement was imported.',
    'courier.statement_posted': 'A courier statement was posted.',
    'courier.booking_unknown': 'A courier booking answer was lost. Check the parcel.',
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
                const message = MESSAGES[event.type];
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
