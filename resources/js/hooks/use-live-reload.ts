import { echo, ensureEcho, watchConnection } from '@/lib/echo';
import { receive, setSocketConnected, type LiveContext, type RecordChangedEvent } from '@/lib/live-reload';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { useEffect, useRef } from 'react';

const EVENT = '.record.changed';

/**
 * The app shell's ear (realtime everywhere, 11 Oct 2026). Listens on the
 * business channel and the person's own channel and hands every
 * `record.changed` to lib/live-reload, which decides whether this page shows
 * that kind and when it is safe to re-read it.
 *
 * ⚠⚠ NEVER `leave()` A CHANNEL HERE. The role dashboards listen on the same
 * business channel; leaving it would silence them. Only this hook's own
 * listener is removed, and the subscription stays for the next page.
 */
export function useLiveReload(): void {
    const { realtime, auth, canReviewApprovals } = usePage<SharedData>().props;
    const userId = (auth?.user?.id as number | undefined) ?? null;

    const context = useRef<LiveContext>({ userId, canReviewApprovals: Boolean(canReviewApprovals) });
    context.current = { userId, canReviewApprovals: Boolean(canReviewApprovals) };

    const enabled = Boolean(realtime?.enabled);
    const businessChannel = realtime?.business_channel ?? null;
    const userChannel = realtime?.user_channel ?? null;

    useEffect(() => {
        if (!enabled || !ensureEcho()) {
            setSocketConnected(false);

            return;
        }

        const handle = (event: RecordChangedEvent) => receive(event, context.current);
        const channels = [businessChannel, userChannel].filter((name): name is string => Boolean(name)).map((name) => echo().private(name));

        channels.forEach((channel) => channel.listen(EVENT, handle));
        const stop = watchConnection((status) => setSocketConnected(status === 'connected'));

        return () => {
            stop();
            channels.forEach((channel) => channel.stopListening(EVENT, handle));
        };
    }, [enabled, businessChannel, userChannel]);
}
