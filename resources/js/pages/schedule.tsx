import { Badge } from '@/components/ui/badge';
import AppLayout from '@/layouts/app-layout';
import { useTranslation, type TranslationKey } from '@/lib/i18n';
import { KIND_STYLE, dhakaTime, formatClock, secondsOf, slotsOn, syncClock } from '@/lib/schedule-engine';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type ScheduleSlot } from '@/types';
import { Head } from '@inertiajs/react';
import { Sparkles } from 'lucide-react';
import { motion, useReducedMotion } from 'motion/react';
import { useEffect, useState } from 'react';

/** How often the "Now" marker moves along. */
const TICK_MS = 30 * 1000;

/**
 * Today's rhythm (14 Oct 2026): Joy's "Deep Focus Time Policy" as he drew it,
 * a vertical timeline of today's slots with the time on the left and a
 * coloured card per slot, the one under way ringed and marked "Now", the
 * ones already over dimmed. Read-only, for everybody; the managers change it
 * in Settings > Work schedule.
 *
 * "Today" and "now" are Dhaka time from the server's clock, never this
 * machine's own zone (lib/schedule-engine).
 */
export default function Schedule({ slots, server_now }: { slots: ScheduleSlot[]; server_now: string }) {
    const { t } = useTranslation();
    const reduceMotion = useReducedMotion();
    const breadcrumbs: BreadcrumbItem[] = [{ title: t('schedule.nav'), href: '/schedule' }];

    const [now, setNow] = useState(() => {
        syncClock(server_now);

        return dhakaTime();
    });

    useEffect(() => {
        syncClock(server_now);
        setNow(dhakaTime());

        const timer = window.setInterval(() => setNow(dhakaTime()), TICK_MS);

        return () => window.clearInterval(timer);
    }, [server_now]);

    const today = slotsOn(slots, now.weekday);
    const dayName = t(`schedule.day.${now.weekday}` as TranslationKey);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('schedule.nav')} />
            <div className="mx-auto w-full max-w-3xl space-y-8 p-4 sm:py-8">
                <header className="space-y-2 text-center">
                    <p className="text-muted-foreground inline-flex items-center gap-1.5 text-xs font-medium tracking-widest uppercase">
                        <Sparkles className="size-3.5" aria-hidden />
                        {t('schedule.today', { day: dayName })}
                    </p>
                    <h1 className="text-2xl font-bold tracking-tight text-balance sm:text-3xl">{t('schedule.title')}</h1>
                    <p className="text-muted-foreground text-sm text-balance">{t('schedule.tagline')}</p>
                </header>

                {today.length === 0 ? (
                    <p className="text-muted-foreground rounded-2xl border border-dashed p-8 text-center text-sm">
                        {slots.length === 0 ? t('schedule.empty') : t('schedule.off_day')}
                    </p>
                ) : (
                    <ol className="relative space-y-3">
                        {/* The spine of the timeline. */}
                        <span aria-hidden className="bg-border absolute top-2 bottom-2 left-[6.375rem] w-px sm:left-[8.875rem]" />
                        {today.map((slot, index) => {
                            const style = KIND_STYLE[slot.kind] ?? KIND_STYLE.focus;
                            const Icon = style.icon;
                            const start = secondsOf(slot.starts_at);
                            const end = secondsOf(slot.ends_at);
                            const isNow = now.seconds >= start && now.seconds < end;
                            const isPast = now.seconds >= end;

                            return (
                                <motion.li
                                    key={slot.id}
                                    className={cn('relative flex items-center gap-4 sm:gap-6', isPast && 'opacity-50')}
                                    initial={reduceMotion ? false : { opacity: 0, y: 8 }}
                                    animate={{ opacity: isPast ? 0.5 : 1, y: 0 }}
                                    transition={{ duration: 0.35, delay: reduceMotion ? 0 : index * 0.04 }}
                                    aria-current={isNow ? 'time' : undefined}
                                >
                                    <div className="w-20 shrink-0 text-right text-xs leading-tight tabular-nums sm:w-28 sm:text-sm">
                                        <p className="font-semibold">{formatClock(slot.starts_at)}</p>
                                        <p className="text-muted-foreground">{formatClock(slot.ends_at)}</p>
                                    </div>
                                    <span
                                        aria-hidden
                                        className={cn(
                                            'ring-background relative z-10 size-3 shrink-0 rounded-full ring-4',
                                            style.dot,
                                            isNow && !reduceMotion && 'animate-pulse',
                                        )}
                                    />
                                    <div
                                        className={cn(
                                            'flex min-w-0 flex-1 items-center gap-3 rounded-2xl px-4 py-3 ring-1 transition-shadow',
                                            style.card,
                                            // Staggered, as on Joy's sheet.
                                            index % 2 === 1 && 'sm:ml-8',
                                            isNow && 'ring-foreground/60 shadow-lg ring-2',
                                        )}
                                    >
                                        <span className={cn('grid size-10 shrink-0 place-items-center rounded-full', style.chip)}>
                                            <Icon className="size-5" aria-hidden />
                                        </span>
                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <p className="font-semibold">{slot.title}</p>
                                                {isNow && <Badge className="bg-foreground text-background">{t('schedule.now')}</Badge>}
                                            </div>
                                            {slot.subtitle && <p className="text-sm opacity-80">{slot.subtitle}</p>}
                                            <p className="mt-0.5 text-xs opacity-60">{t(`schedule.kind.${slot.kind}`)}</p>
                                        </div>
                                    </div>
                                </motion.li>
                            );
                        })}
                    </ol>
                )}
            </div>
        </AppLayout>
    );
}
