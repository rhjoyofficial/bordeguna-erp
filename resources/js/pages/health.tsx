import AppLayout from '@/layouts/app-layout';
import { useTranslation } from '@/lib/i18n';
import { cn, formatMoney } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import {
    AlarmClock,
    ArrowRight,
    CheckCircle2,
    ClipboardCheck,
    PackageMinus,
    PackageOpen,
    Receipt,
    UserRoundX,
    Wallet,
    type LucideIcon,
} from 'lucide-react';

/**
 * Business health (rebuilt 24 Oct 2026, Joy: "only the necessary part, well
 * designed"): ONE page of today's action signals. Each card is a count, an
 * amount where money is involved, and a link to the list where the work is
 * done. Nothing else lives here: the server sends only the signals this person
 * may open (BusinessHealthSignals).
 */
interface Signal {
    key: string;
    label: string;
    count: number;
    /** Taka as a decimal string, or null when the signal is not about money. */
    amount: string | null;
    url: string;
    hint: string;
    params: Record<string, string | number>;
}

const ICONS: Record<string, LucideIcon> = {
    customers_overdue: UserRoundX,
    bills_due: Receipt,
    low_stock: PackageMinus,
    batches_expiring: AlarmClock,
    money_low: Wallet,
    approvals_waiting: ClipboardCheck,
    stock_reserved: PackageOpen,
};

function SignalCard({ signal }: { signal: Signal }) {
    const { t, tr } = useTranslation();
    const Icon = ICONS[signal.key] ?? ClipboardCheck;
    const waiting = signal.count > 0;
    const amount = signal.amount !== null && Number(signal.amount) !== 0 ? signal.amount : null;

    return (
        <Link
            href={signal.url}
            className={cn(
                'group bg-card flex flex-col gap-3 rounded-lg border p-4 transition-colors',
                waiting ? 'border-amber-500/50 hover:border-amber-500' : 'hover:border-foreground/30',
            )}
        >
            <div className="flex items-start justify-between gap-3">
                <p className="text-sm font-medium">{tr(`health.signal.${signal.key}`, signal.label)}</p>
                <Icon className={cn('size-5 shrink-0', waiting ? 'text-amber-600' : 'text-muted-foreground')} aria-hidden />
            </div>

            <div>
                <p className={cn('text-3xl font-semibold tabular-nums', !waiting && 'text-muted-foreground')}>{signal.count}</p>
                {amount !== null && <p className="text-sm tabular-nums">{t('health.amount', { amount: formatMoney(amount) })}</p>}
            </div>

            <p className="text-muted-foreground text-sm">
                {waiting ? tr(`health.hint.${signal.key}`, signal.hint, signal.params) : t('health.clear')}
            </p>

            <span className="text-primary mt-auto inline-flex w-fit items-center gap-1 text-sm group-hover:underline">
                {t('health.open_list')}
                <ArrowRight className="size-3.5" aria-hidden />
            </span>
        </Link>
    );
}

export default function Health({ signals }: { signals: Signal[] }) {
    const { t } = useTranslation();
    const breadcrumbs: BreadcrumbItem[] = [{ title: t('ws.business_health'), href: '/health' }];
    const allClear = signals.every((signal) => signal.count === 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('ws.business_health')} />
            <div className="space-y-4 p-4">
                <div>
                    <h1 className="text-xl font-semibold">{t('ws.business_health')}</h1>
                    <p className="text-muted-foreground text-sm">{t('health.subtitle')}</p>
                </div>

                {allClear && (
                    <div className="inline-flex w-fit items-center gap-2 rounded-lg border border-emerald-500/40 bg-emerald-500/5 px-3 py-2 text-sm">
                        <CheckCircle2 className="size-4 text-emerald-600" aria-hidden />
                        <span className="font-medium">{t('health.all_clear')}</span>
                        <span className="text-muted-foreground">{t('health.all_clear_hint')}</span>
                    </div>
                )}

                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    {signals.map((signal) => (
                        <SignalCard key={signal.key} signal={signal} />
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}
