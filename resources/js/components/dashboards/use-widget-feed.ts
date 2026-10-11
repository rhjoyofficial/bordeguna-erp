import { dashboardSound } from '@/lib/dashboard-sound';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import type { DashboardWidget, WidgetResult } from './types';

export interface FeedEntry {
    result: WidgetResult;
    /** When the browser last got an answer (ok or error). */
    fetchedAt: number;
    loading: boolean;
    /** The last fetch failed: the figure on screen is the previous good one. */
    stale: boolean;
}

/**
 * Keeps every widget on the page fresh, each on its own terms.
 *
 * - While the socket is live, a widget re-reads itself when an event names it
 *   (`refresh(keys)`), and otherwise only on a slow safety interval.
 * - While the socket is down (or the build has none), each widget polls on its
 *   own `refresh_seconds` (ruling 4).
 * - Nothing polls while the tab is hidden; coming back refreshes everything.
 * - A failed fetch keeps the last good figure and marks it stale; it never
 *   shows zero (ruling 9).
 * - Watched counts that rise between two answers trigger the widget's declared
 *   sound and a short toast, unless the person did something on this page in
 *   the last few seconds (their own action never rings).
 */
export function useWidgetFeed({
    widgets,
    dashboard,
    rangeQuery,
    live,
}: {
    widgets: DashboardWidget[];
    dashboard: string;
    rangeQuery: Record<string, string>;
    live: boolean;
}) {
    const initial = () =>
        Object.fromEntries(
            widgets.map((w) => [w.key, { result: w.result, fetchedAt: Date.now(), loading: false, stale: w.result.status === 'error' } as FeedEntry]),
        );

    const [entries, setEntries] = useState<Record<string, FeedEntry>>(initial);
    const watched = useRef<Record<string, Record<string, number>>>({});
    const inFlight = useRef<Set<string>>(new Set());
    const lastOwnAction = useRef(0);
    // Keys refreshed because a socket event named them: that event already
    // decided about sound, so the watch comparison stays quiet for them.
    const silent = useRef<Set<string>>(new Set());
    const widgetsRef = useRef(widgets);
    widgetsRef.current = widgets;

    // A new page load (range change, layout change) brings new server answers.
    useEffect(() => {
        setEntries(initial());
        watched.current = Object.fromEntries(widgets.map((w) => [w.key, w.result.data?.watch ?? {}]));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [widgets]);

    useEffect(() => {
        const mark = () => {
            lastOwnAction.current = Date.now();
        };
        window.addEventListener('submit', mark, true);

        return () => window.removeEventListener('submit', mark, true);
    }, []);

    const compareWatch = useCallback((widget: DashboardWidget, result: WidgetResult) => {
        const now = result.data?.watch ?? {};
        const before = watched.current[widget.key];
        watched.current[widget.key] = now;
        const quiet = silent.current.delete(widget.key);

        if (!before || quiet || result.status !== 'ok' || Date.now() - lastOwnAction.current < 8000) {
            return;
        }

        for (const rule of widget.notifications) {
            const was = before[rule.watch];
            const is = now[rule.watch];

            if (was !== undefined && is !== undefined && is > was) {
                dashboardSound.notify(rule.sound, `${widget.key}:${rule.watch}:${is}`);

                if (dashboardSound.allows(rule.sound)) {
                    toast(rule.message, { description: widget.title });
                }
            }
        }
    }, []);

    const fetchOne = useCallback(
        async (key: string) => {
            const widget = widgetsRef.current.find((w) => w.key === key);

            if (!widget || inFlight.current.has(key)) {
                return;
            }

            inFlight.current.add(key);
            setEntries((current) => (current[key] ? { ...current, [key]: { ...current[key], loading: true } } : current));

            try {
                const params = new URLSearchParams({ dashboard, ...rangeQuery });
                const response = await fetch(`/dashboard/widgets/${encodeURIComponent(key)}?${params.toString()}`, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });

                if (!response.ok) {
                    throw new Error(String(response.status));
                }

                const result = (await response.json()) as WidgetResult;
                compareWatch(widget, result);

                setEntries((current) => ({
                    ...current,
                    [key]:
                        result.status === 'ok'
                            ? { result, fetchedAt: Date.now(), loading: false, stale: false }
                            : // Keep the last good figure; say it is stale.
                              { ...(current[key] ?? { result }), fetchedAt: Date.now(), loading: false, stale: true },
                }));
            } catch {
                setEntries((current) => (current[key] ? { ...current, [key]: { ...current[key], loading: false, stale: true } } : current));
            } finally {
                inFlight.current.delete(key);
            }
        },
        [dashboard, rangeQuery, compareWatch],
    );

    const refresh = useCallback(
        (keys?: string[], options?: { silent?: boolean }) => {
            const targets = keys ?? widgetsRef.current.map((w) => w.key);

            if (options?.silent) {
                targets.forEach((k) => silent.current.add(k));
            }
            targets.filter((k) => widgetsRef.current.some((w) => w.key === k)).forEach((k) => void fetchOne(k));
        },
        [fetchOne],
    );

    // Per-widget intervals. Live: a slow safety net (5x, at least 2 min).
    // Offline: the widget's own interval.
    useEffect(() => {
        const timers = widgets.map((widget) => {
            const seconds = live ? Math.max(widget.refresh_seconds * 5, 120) : widget.refresh_seconds;

            return window.setInterval(() => {
                if (document.visibilityState === 'visible') {
                    void fetchOne(widget.key);
                }
            }, seconds * 1000);
        });

        return () => timers.forEach((t) => window.clearInterval(t));
    }, [widgets, live, fetchOne]);

    useEffect(() => {
        let hiddenAt = 0;
        const onVisibility = () => {
            if (document.visibilityState === 'hidden') {
                hiddenAt = Date.now();
            } else if (hiddenAt && Date.now() - hiddenAt > 10000) {
                refresh();
            }
        };
        document.addEventListener('visibilitychange', onVisibility);

        return () => document.removeEventListener('visibilitychange', onVisibility);
    }, [refresh]);

    return { entries, refresh };
}
