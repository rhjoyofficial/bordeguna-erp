import { ReasonDialog } from '@/components/sales/correction-actions';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { formatQuantity } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

interface Hold {
    id: number;
    kind: string;
    document: string;
    url: string | null;
    customer: string | null;
    quantity: string;
    since: string | null;
    by: string | null;
    releasable: boolean;
}

interface ProductRow {
    key: string;
    product: string | null;
    sku: string | null;
    product_id: number;
    warehouse: string | null;
    on_hand: string;
    reserved: string;
    available: string;
    holds: Hold[];
}

interface Backorder {
    key: string;
    order: string;
    url: string;
    customer: string | null;
    product: string | null;
    warehouse: string | null;
    wanted: string;
    held: string;
    short: string;
    reason: string | null;
    since: string | null;
}

/**
 * ⭐ Reserved stock (Joy, 24 Oct 2026, Q4 = A, R81, B17): per product and
 * warehouse, on hand, reserved and available to sell, and under it which
 * documents hold how much since when. Release by hand for a Manager or the
 * Owner, with a reason. Below: the backorders.
 */
export default function ReservedStock({
    products,
    backorders,
    can_release,
}: {
    products: ProductRow[];
    backorders: Backorder[];
    can_release: boolean;
}) {
    const { t } = useTranslation();
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Sales', href: '/sales/overview' },
        { title: t('rsv.title'), href: '/sales/reserved-stock' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('rsv.title')} />
            <div className="flex flex-col gap-4 p-4">
                <div>
                    <h1 className="text-lg font-semibold">{t('rsv.title')}</h1>
                    <p className="text-muted-foreground max-w-2xl text-sm">{t('rsv.intro')}</p>
                </div>

                {products.length === 0 && <p className="text-muted-foreground text-sm">{t('rsv.none')}</p>}

                {products.map((row) => (
                    <section key={row.key} className="rounded-md border">
                        <div className="flex flex-wrap items-baseline justify-between gap-3 border-b p-3">
                            <div>
                                <h2 className="font-medium">{row.product}</h2>
                                <p className="text-muted-foreground text-xs">
                                    {row.sku} {row.warehouse ? `· ${row.warehouse}` : ''}
                                </p>
                            </div>
                            <dl className="flex flex-wrap gap-4 text-sm">
                                <div>
                                    <dt className="text-muted-foreground text-xs">{t('rsv.on_hand')}</dt>
                                    <dd className="text-right tabular-nums">{formatQuantity(row.on_hand)}</dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground text-xs">{t('rsv.reserved')}</dt>
                                    <dd className="text-right tabular-nums">{formatQuantity(row.reserved)}</dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground text-xs">{t('rsv.available')}</dt>
                                    <dd className="text-right font-semibold tabular-nums">{formatQuantity(row.available)}</dd>
                                </div>
                            </dl>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="text-muted-foreground text-left text-xs">
                                    <tr>
                                        <th className="p-3">{t('rsv.held_by')}</th>
                                        <th className="p-3">{t('rsv.customer')}</th>
                                        <th className="p-3 text-right">{t('rsv.quantity')}</th>
                                        <th className="p-3">{t('rsv.since')}</th>
                                        <th className="p-3">{t('rsv.by')}</th>
                                        <th className="p-3" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {row.holds.map((hold) => (
                                        <tr key={hold.id} className="border-t">
                                            <td className="p-3">
                                                {hold.url ? (
                                                    <Link href={hold.url} className="underline-offset-2 hover:underline">
                                                        {hold.document}
                                                    </Link>
                                                ) : (
                                                    hold.document
                                                )}
                                            </td>
                                            <td className="p-3">{hold.customer ?? ''}</td>
                                            <td className="p-3 text-right tabular-nums">{formatQuantity(hold.quantity)}</td>
                                            <td className="p-3">{showDate(hold.since)}</td>
                                            <td className="p-3">{hold.by ?? ''}</td>
                                            <td className="p-3 text-right">
                                                {hold.releasable ? (
                                                    can_release && (
                                                        <ReasonDialog
                                                            trigger={
                                                                <Button size="sm" variant="ghost">
                                                                    {t('rsv.release')}
                                                                </Button>
                                                            }
                                                            title={t('rsv.release_title', { document: hold.document })}
                                                            description={t('rsv.release_text')}
                                                            url={`/sales/reserved-stock/${hold.id}/release`}
                                                            reasonKey="reason"
                                                            confirmLabel={t('rsv.release')}
                                                        />
                                                    )
                                                ) : (
                                                    <span className="text-muted-foreground text-xs">{t('rsv.request_note')}</span>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                ))}

                {backorders.length > 0 && (
                    <section className="rounded-md border">
                        <div className="border-b p-3">
                            <h2 className="font-medium">{t('rsv.backorders')}</h2>
                            <p className="text-muted-foreground text-xs">{t('rsv.backorders_intro')}</p>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="text-muted-foreground text-left text-xs">
                                    <tr>
                                        <th className="p-3">{t('rsv.order')}</th>
                                        <th className="p-3">{t('rsv.customer')}</th>
                                        <th className="p-3">{t('rsv.product')}</th>
                                        <th className="p-3">{t('rsv.warehouse')}</th>
                                        <th className="p-3 text-right">{t('rsv.wanted')}</th>
                                        <th className="p-3 text-right">{t('rsv.held')}</th>
                                        <th className="p-3 text-right">{t('rsv.short')}</th>
                                        <th className="p-3">{t('rsv.reason')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {backorders.map((row) => (
                                        <tr key={row.key} className="border-t">
                                            <td className="p-3">
                                                <Link href={row.url} className="underline-offset-2 hover:underline">
                                                    {row.order}
                                                </Link>
                                            </td>
                                            <td className="p-3">{row.customer ?? ''}</td>
                                            <td className="p-3">{row.product}</td>
                                            <td className="p-3">{row.warehouse ?? ''}</td>
                                            <td className="p-3 text-right tabular-nums">{formatQuantity(row.wanted)}</td>
                                            <td className="p-3 text-right tabular-nums">{formatQuantity(row.held)}</td>
                                            <td className="text-destructive p-3 text-right tabular-nums">{formatQuantity(row.short)}</td>
                                            <td className="p-3 text-xs">
                                                {row.reason ?? ''} {row.since ? `(${showDate(row.since)})` : ''}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                )}
            </div>
        </AppLayout>
    );
}
