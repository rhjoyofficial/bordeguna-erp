import { StockReasonDialog } from '@/components/inventory/stock-reason-dialog';
import { ReportHeading } from '@/components/report/report-shell';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { useTranslation, type TranslationKey } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';

interface Entry {
    type: string;
    id: number;
    label: string;
    deleted_at: string | null;
    deleted_by: string | null;
    reason: string | null;
    days_in_bin: number | null;
}

interface SwitchedOff {
    type: string;
    id: number;
    label: string;
}

type ReasonOption = { id: number; name: string };

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Settings', href: '/settings' },
    { title: 'Recycle bin', href: '/settings/recycle-bin' },
];

const TYPE_LABELS: Record<string, TranslationKey> = {
    asset_item: 'corr.bin.type_asset_item',
    file: 'corr.bin.type_file',
    device_login: 'corr.bin.type_device_login',
    user: 'corr.bin.type_user',
};

/**
 * The Recycle bin (owner's answer 1, 17 Oct 2026). Deleted drafts and
 * never-used records stay here, hidden, for good. Only the Owner sees this
 * page and only the Owner restores. There is no "Delete forever".
 *
 * ⭐ Corrections wave 3, B11 (24 Oct 2026): every type that can be binned
 * (things, files, device logins, logins), a filter by type, how long each has
 * been here, the reason dialog for Restore, and beside it what is SWITCHED
 * OFF rather than deleted, with Reactivate (FINAL-PLAN 2 and 6.3).
 */
export default function RecycleBin({
    entries,
    modules,
    module,
    switched_off,
    reasons,
}: {
    entries: Entry[];
    modules: string[];
    module: string | null;
    switched_off: SwitchedOff[];
    reasons: ReasonOption[];
}) {
    const { t } = useTranslation();
    const typeLabel = (type: string) => (TYPE_LABELS[type] ? t(TYPE_LABELS[type]) : type);
    const filter = (next: string | null) =>
        router.get('/settings/recycle-bin', next ? { module: next } : {}, { preserveScroll: true, replace: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('corr.bin.title')} />

            <div className="flex max-w-3xl flex-col gap-4 p-4">
                <ReportHeading title={t('corr.bin.title')} />
                <p className="text-muted-foreground text-sm">{t('corr.bin.intro')}</p>

                <div className="flex flex-wrap gap-2">
                    <Button type="button" size="sm" variant={module === null ? 'default' : 'outline'} onClick={() => filter(null)}>
                        {t('corr.bin.all')}
                    </Button>
                    {modules.map((type) => (
                        <Button key={type} type="button" size="sm" variant={module === type ? 'default' : 'outline'} onClick={() => filter(type)}>
                            {typeLabel(type)}
                        </Button>
                    ))}
                </div>

                {entries.length === 0 ? (
                    <p className="text-muted-foreground rounded-md border p-6 text-center text-sm">{t('corr.bin.empty')}</p>
                ) : (
                    <div className="grid gap-2">
                        {entries.map((entry) => (
                            <div
                                key={`${entry.type}-${entry.id}`}
                                className="flex flex-wrap items-start justify-between gap-3 rounded-md border p-3 text-sm"
                            >
                                <div className="grid gap-1">
                                    <span className="font-medium">{entry.label}</span>
                                    <span className="text-muted-foreground text-xs">
                                        {typeLabel(entry.type)} ·{' '}
                                        {t('corr.bin.deleted', { when: showDate(entry.deleted_at) || '-', who: entry.deleted_by ?? '-' })}
                                        {entry.reason ? `: ${entry.reason}` : ''}
                                        {entry.days_in_bin !== null && ` · ${t('corr.bin.days', { days: entry.days_in_bin })}`}
                                    </span>
                                </div>
                                <StockReasonDialog
                                    trigger={
                                        <Button size="sm" variant="outline">
                                            {t('corr.assets.restore')}
                                        </Button>
                                    }
                                    title={t('corr.bin.restore_title', { name: entry.label })}
                                    description={t('corr.bin.restore_note')}
                                    confirmLabel={t('corr.assets.restore')}
                                    reasons={reasons}
                                    url={`/settings/recycle-bin/${entry.type}/${entry.id}/restore`}
                                />
                            </div>
                        ))}
                    </div>
                )}

                <section className="grid gap-2 pt-4">
                    <h2 className="font-medium">{t('corr.bin.switched_off')}</h2>
                    <p className="text-muted-foreground text-sm">{t('corr.bin.switched_off_note')}</p>
                    {switched_off.length === 0 ? (
                        <p className="text-muted-foreground text-sm">{t('corr.bin.switched_off_none')}</p>
                    ) : (
                        switched_off.map((row) => (
                            <div
                                key={`${row.type}-${row.id}`}
                                className="flex flex-wrap items-center justify-between gap-3 rounded-md border p-3 text-sm"
                            >
                                <span>{row.label}</span>
                                <StockReasonDialog
                                    trigger={
                                        <Button size="sm" variant="outline">
                                            {t('corr.assets.reactivate')}
                                        </Button>
                                    }
                                    title={t('corr.assets.reactivate_title', { name: row.label })}
                                    confirmLabel={t('corr.assets.reactivate')}
                                    reasons={reasons}
                                    url={`/settings/team/${row.id}/toggle-active`}
                                    method="patch"
                                />
                            </div>
                        ))
                    )}
                </section>
            </div>
        </AppLayout>
    );
}
