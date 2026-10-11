import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { ChevronRight, Circle, CircleCheck, ListChecks, X } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { sendJson } from './dashboard-toolbar';

export interface GettingStartedStep {
    key: string;
    done: boolean;
    count: number | null;
    of: number | null;
    url: string;
}

export interface GettingStartedData {
    steps: GettingStartedStep[];
    done: number;
    total: number;
}

/**
 * "Getting started" (Joy, 11 Oct 2026): while the opening figures are missing
 * every money card reads Tk 0, which looks like a broken dashboard. This says
 * what is still to do, one line each, with the screen to do it on.
 *
 * Computed on the server per business (GettingStarted); hidden by the server
 * once everything is done, and by the person whenever they choose (their own
 * preference, nobody else's). Calm on purpose: a plain card like the widgets,
 * the brand green only on the progress bar and the ticks.
 */
export function GettingStartedCard({ data }: { data: GettingStartedData }) {
    const { t, tr } = useTranslation();
    const [hidden, setHidden] = useState(false);

    if (hidden) {
        return null;
    }

    const dismiss = async () => {
        setHidden(true);
        const response = await sendJson('PUT', '/dashboard/getting-started/dismiss', {}).catch(() => null);

        if (!response?.ok) {
            setHidden(false);
            toast.error(t('dash.start.not_saved'));
        }
    };

    const detail = (step: GettingStartedStep): string | null => {
        if (step.key === 'product_costs' && step.of !== null && step.of > 0) {
            return t('dash.start.product_costs.count', { count: step.count ?? 0, of: step.of });
        }

        if (step.key === 'courier' && step.of !== null && step.of > 0) {
            return t('dash.start.courier.count', { count: step.count ?? 0, of: step.of });
        }

        if ((step.key === 'customers' || step.key === 'suppliers') && step.count) {
            return t('dash.start.count', { count: step.count });
        }

        return null;
    };

    // The Tk 0 explanation is only true while the opening balances are missing.
    const openingDone = data.steps.some((step) => step.key === 'opening_balances' && step.done);
    const percent = data.total === 0 ? 0 : Math.round((data.done / data.total) * 100);

    return (
        <section
            aria-labelledby="getting-started-title"
            className="bg-card text-card-foreground rounded-2xl border p-4 shadow-[0_1px_2px_rgba(20,40,25,0.04)] sm:p-5 dark:shadow-none"
        >
            <header className="flex items-start gap-3">
                <span className="bg-muted text-foreground/70 ring-border/60 flex size-9 shrink-0 items-center justify-center rounded-xl ring-1 ring-inset">
                    <ListChecks className="size-[18px]" aria-hidden />
                </span>
                <div className="min-w-0 flex-1">
                    <h2 id="getting-started-title" className="text-sm font-semibold">
                        {t('dash.start.title')}
                    </h2>
                    <p className="text-muted-foreground mt-0.5 text-xs">{openingDone ? t('dash.start.why_rest') : t('dash.start.why')}</p>
                </div>
                <button
                    type="button"
                    onClick={dismiss}
                    className="text-muted-foreground hover:text-foreground hover:bg-accent flex shrink-0 items-center gap-1 rounded-md px-1.5 py-1 text-xs"
                    aria-label={t('dash.start.dismiss_label')}
                >
                    <X className="size-3.5" aria-hidden />
                    <span className="hidden sm:inline">{t('dash.start.dismiss')}</span>
                </button>
            </header>

            <div className="mt-4 flex items-center gap-3">
                <div
                    className="h-1.5 flex-1 overflow-hidden rounded-full bg-[var(--dash-track)]"
                    role="progressbar"
                    aria-valuemin={0}
                    aria-valuemax={data.total}
                    aria-valuenow={data.done}
                    aria-label={t('dash.start.progress', { done: data.done, total: data.total })}
                >
                    <div className="h-full rounded-full bg-[var(--dash-good)] transition-[width] duration-500" style={{ width: `${percent}%` }} />
                </div>
                <span className="text-muted-foreground shrink-0 text-xs tabular-nums">
                    {t('dash.start.progress', { done: data.done, total: data.total })}
                </span>
            </div>

            <ul className="mt-3 grid grid-cols-1 gap-1 md:grid-cols-2 xl:grid-cols-3">
                {data.steps.map((step) => {
                    const extra = detail(step);

                    return (
                        <li key={step.key}>
                            <Link
                                href={step.url}
                                className="group hover:bg-muted/60 flex items-start gap-2.5 rounded-lg px-2 py-2 transition-colors dark:hover:bg-white/[0.04]"
                            >
                                {step.done ? (
                                    <CircleCheck className="mt-0.5 size-4 shrink-0 text-[var(--dash-good)]" aria-hidden />
                                ) : (
                                    <Circle className="text-muted-foreground/70 mt-0.5 size-4 shrink-0" aria-hidden />
                                )}
                                <span className="min-w-0 flex-1">
                                    <span className={cn('block text-sm font-medium', step.done && 'text-muted-foreground')}>
                                        {tr(`dash.start.${step.key}.title`, step.key)}
                                        <span className="sr-only">: {step.done ? t('dash.start.done') : t('dash.start.to_do')}</span>
                                    </span>
                                    <span className="text-muted-foreground block text-xs leading-snug">
                                        {tr(`dash.start.${step.key}.why`, '')}
                                        {extra && <span className="text-foreground/80 ml-1 font-medium tabular-nums">{extra}</span>}
                                    </span>
                                </span>
                                <ChevronRight
                                    className="text-muted-foreground/60 group-hover:text-foreground mt-0.5 size-4 shrink-0 transition-colors"
                                    aria-hidden
                                />
                            </Link>
                        </li>
                    );
                })}
            </ul>
        </section>
    );
}
