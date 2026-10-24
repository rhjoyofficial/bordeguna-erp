import { EngineActions, type EngineAction } from '@/components/sales/correction-actions';
import { SellerDialog, type SellerForms } from '@/components/sales/seller-dialogs';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { formatDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { type SellerTotals } from './index';

/**
 * ⭐ Initial plan B21 (R109, 24 Oct 2026): one retail seller's statement for a
 * period: taken, sold, returned, holding (with how long each batch has been
 * with him), the money, and every hand-over, return and reported sale with
 * its correction (Reverse a sheet; Void sale on a report, through the
 * invoice's own engine). Owner and Manager get the three popups; the seller
 * himself sees his own page read only.
 */
type Statement = SellerTotals & {
    products: { product_id: number; name: string; taken: string; sold: string; returned: string; holding: string }[];
    ageing: {
        product: string;
        batch: string;
        quantity: string;
        since: string | null;
        days: number | null;
        expiry: string | null;
        cost: string;
        trade: string | null;
    }[];
    sheets: {
        id: number;
        number: string;
        kind: 'handover' | 'return';
        date: string;
        warehouse: string | null;
        items: string;
        trade: string;
        cost: string;
        reason: string | null;
        reversed: { on: string | null; reason: string | null } | null;
        actions?: EngineAction[];
    }[];
    sales: {
        id: number;
        number: string;
        date: string;
        sold_to: string | null;
        customer: string | null;
        payment: 'collected' | 'seller_owes' | 'on_account';
        account: string | null;
        total: string;
        invoice_id: number;
        invoice_number: string | null;
        voided: boolean;
        outstanding: string | null;
        actions?: EngineAction[];
    }[];
};

function Tile({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="rounded-lg border p-3">
            <div className="text-muted-foreground text-xs">{label}</div>
            <div className="mt-1 text-sm tabular-nums">{children}</div>
        </div>
    );
}

export default function SellerShow({
    filters,
    statement,
    canManage,
    forms,
    submission,
}: {
    filters: { from: string; to: string };
    statement: Statement;
    canManage: boolean;
    forms: SellerForms | null;
    submission: string;
}) {
    const { t } = useTranslation();
    const [period, setPeriod] = useState(filters);
    const [mode, setMode] = useState<'handover' | 'return' | 'sales' | null>(null);
    const s = statement;
    const here = `/sales/sellers/${s.employee_id}`;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Sales', href: '/sales/overview' },
        { title: t('seller.title'), href: '/sales/sellers' },
        { title: s.name, href: here },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={s.name} />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">{s.name}</h1>
                        <p className="text-muted-foreground text-sm">{canManage ? s.stock?.name : t('seller.self_hint')}</p>
                    </div>
                    <div className="flex flex-wrap items-end gap-3">
                        <div className="grid gap-1">
                            <Label className="text-xs">{t('seller.from')}</Label>
                            <Input
                                type="date"
                                className="w-fit"
                                value={period.from}
                                onChange={(e) => setPeriod({ ...period, from: e.target.value })}
                            />
                        </div>
                        <div className="grid gap-1">
                            <Label className="text-xs">{t('seller.to')}</Label>
                            <Input type="date" className="w-fit" value={period.to} onChange={(e) => setPeriod({ ...period, to: e.target.value })} />
                        </div>
                        <Button variant="outline" onClick={() => router.get(here, period, { preserveState: true })}>
                            {t('seller.show')}
                        </Button>
                        {canManage && forms && (
                            <>
                                <Button onClick={() => setMode('handover')}>{t('seller.handover')}</Button>
                                <Button variant="outline" onClick={() => setMode('sales')}>
                                    {t('seller.report')}
                                </Button>
                                <Button variant="outline" onClick={() => setMode('return')}>
                                    {t('seller.return')}
                                </Button>
                            </>
                        )}
                    </div>
                </div>

                <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                    <Tile label={t('seller.taken')}>
                        {s.taken.quantity} · Tk {s.taken.trade} {t('seller.at_tp')}
                        <div className="text-muted-foreground text-xs">
                            Tk {s.taken.cost} {t('seller.at_cost')}
                        </div>
                    </Tile>
                    <Tile label={t('seller.sold')}>
                        {s.sold.quantity} · Tk {s.sold.value}
                        <div className="text-muted-foreground text-xs">
                            {t('seller.margin')} Tk {s.sold.margin}
                        </div>
                    </Tile>
                    <Tile label={t('seller.returned')}>
                        {s.returned.quantity} · Tk {s.returned.cost} {t('seller.at_cost')}
                    </Tile>
                    <Tile label={t('seller.holding')}>
                        {s.holding.quantity} · Tk {s.holding.trade} {t('seller.at_tp')}
                        <div className="text-muted-foreground text-xs">
                            Tk {s.holding.cost} {t('seller.at_cost')}
                        </div>
                    </Tile>
                    <Tile label={t('seller.collected')}>Tk {s.money.collected}</Tile>
                    <Tile label={t('seller.handed_in')}>Tk {s.money.handed_in}</Tile>
                    <Tile label={t('seller.cash_held')}>Tk {s.money.cash_held}</Tile>
                    <Tile label={t('seller.seller_owes')}>
                        Tk {s.money.seller_owes}
                        <div className="text-muted-foreground text-xs">
                            {t('seller.customers_owe')} Tk {s.money.customers_owe}
                        </div>
                    </Tile>
                </div>

                <section className="space-y-2">
                    <h2 className="font-semibold">{t('seller.products')}</h2>
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3">{t('seller.item')}</th>
                                    <th className="p-3 text-right">{t('seller.taken')}</th>
                                    <th className="p-3 text-right">{t('seller.sold')}</th>
                                    <th className="p-3 text-right">{t('seller.returned')}</th>
                                    <th className="p-3 text-right">{t('seller.holding')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {s.products.length === 0 && (
                                    <tr>
                                        <td colSpan={5} className="text-muted-foreground p-4 text-center">
                                            {t('seller.nothing')}
                                        </td>
                                    </tr>
                                )}
                                {s.products.map((p) => (
                                    <tr key={p.product_id} className="border-t">
                                        <td className="p-3">{p.name}</td>
                                        <td className="p-3 text-right tabular-nums">{p.taken}</td>
                                        <td className="p-3 text-right tabular-nums">{p.sold}</td>
                                        <td className="p-3 text-right tabular-nums">{p.returned}</td>
                                        <td className="p-3 text-right tabular-nums">{p.holding}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>

                <section className="space-y-2">
                    <h2 className="font-semibold">{t('seller.ageing')}</h2>
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3">{t('seller.item')}</th>
                                    <th className="p-3">{t('seller.batch')}</th>
                                    <th className="p-3 text-right">{t('seller.qty')}</th>
                                    <th className="p-3">{t('seller.since')}</th>
                                    <th className="p-3 text-right">{t('seller.held_for')}</th>
                                    <th className="p-3">{t('seller.expiry')}</th>
                                    <th className="p-3 text-right">{t('seller.at_tp')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {s.ageing.length === 0 && (
                                    <tr>
                                        <td colSpan={7} className="text-muted-foreground p-4 text-center">
                                            {t('seller.nothing')}
                                        </td>
                                    </tr>
                                )}
                                {s.ageing.map((a, index) => (
                                    <tr key={index} className="border-t">
                                        <td className="p-3">{a.product}</td>
                                        <td className="p-3">{a.batch}</td>
                                        <td className="p-3 text-right tabular-nums">{a.quantity}</td>
                                        <td className="p-3">{formatDate(a.since)}</td>
                                        <td className="p-3 text-right tabular-nums">{a.days === null ? '-' : t('seller.days', { days: a.days })}</td>
                                        <td className="p-3">{formatDate(a.expiry)}</td>
                                        <td className="p-3 text-right tabular-nums">{a.trade === null ? '-' : `Tk ${a.trade}`}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>

                <section className="space-y-2">
                    <h2 className="font-semibold">{t('seller.sheets')}</h2>
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3">{t('seller.number')}</th>
                                    <th className="p-3">{t('seller.date')}</th>
                                    <th className="p-3">{t('seller.items')}</th>
                                    <th className="p-3 text-right">{t('seller.at_tp')}</th>
                                    <th className="p-3"></th>
                                </tr>
                            </thead>
                            <tbody>
                                {s.sheets.length === 0 && (
                                    <tr>
                                        <td colSpan={5} className="text-muted-foreground p-4 text-center">
                                            {t('seller.nothing')}
                                        </td>
                                    </tr>
                                )}
                                {s.sheets.map((sheet) => (
                                    <tr key={sheet.id} className="border-t align-top">
                                        <td className="p-3">
                                            <div className="font-medium">{sheet.number}</div>
                                            <div className="text-muted-foreground text-xs">
                                                {sheet.kind === 'handover' ? t('seller.kind_handover') : t('seller.kind_return')} · {sheet.warehouse}
                                            </div>
                                            {sheet.reversed && <div className="text-destructive text-xs">{t('seller.reversed')}</div>}
                                        </td>
                                        <td className="p-3">{formatDate(sheet.date)}</td>
                                        <td className="p-3">
                                            {sheet.items}
                                            {sheet.reason && <div className="text-muted-foreground text-xs">{sheet.reason}</div>}
                                        </td>
                                        <td className="p-3 text-right tabular-nums">{sheet.kind === 'handover' ? `Tk ${sheet.trade}` : '-'}</td>
                                        <td className="p-3">
                                            <div className="flex flex-wrap items-start justify-end gap-2">
                                                <Button asChild size="sm" variant="outline">
                                                    <a href={`/sales/sellers/sheets/${sheet.id}`} target="_blank" rel="noreferrer">
                                                        {t('seller.print')}
                                                    </a>
                                                </Button>
                                                {sheet.actions && forms && (
                                                    <EngineActions
                                                        type="sales.seller_transfer"
                                                        id={sheet.id}
                                                        recordLabel={sheet.number}
                                                        actions={sheet.actions}
                                                        reasons={forms.reversal_reasons}
                                                        labels={{ reverse: t('seller.reverse') }}
                                                        redirectTo={here}
                                                    />
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>

                <section className="space-y-2">
                    <h2 className="font-semibold">{t('seller.sales')}</h2>
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3">{t('seller.number')}</th>
                                    <th className="p-3">{t('seller.date')}</th>
                                    <th className="p-3">{t('seller.sold_to')}</th>
                                    <th className="p-3">{t('seller.money')}</th>
                                    <th className="p-3 text-right">{t('seller.total')}</th>
                                    <th className="p-3"></th>
                                </tr>
                            </thead>
                            <tbody>
                                {s.sales.length === 0 && (
                                    <tr>
                                        <td colSpan={6} className="text-muted-foreground p-4 text-center">
                                            {t('seller.nothing')}
                                        </td>
                                    </tr>
                                )}
                                {s.sales.map((sale) => (
                                    <tr key={sale.id} className="border-t align-top">
                                        <td className="p-3">
                                            <div className="font-medium">{sale.number}</div>
                                            {canManage && sale.invoice_number && (
                                                <Link
                                                    href={`/sales/invoices/${sale.invoice_id}`}
                                                    className="text-muted-foreground text-xs hover:underline"
                                                >
                                                    {t('seller.invoice')} {sale.invoice_number}
                                                </Link>
                                            )}
                                            {sale.voided && <div className="text-destructive text-xs">{t('seller.voided')}</div>}
                                        </td>
                                        <td className="p-3">{formatDate(sale.date)}</td>
                                        <td className="p-3">{sale.sold_to ?? sale.customer ?? t('seller.walk_in')}</td>
                                        <td className="p-3">
                                            {t(`seller.pay_${sale.payment}`)}
                                            {sale.account && <div className="text-muted-foreground text-xs">{sale.account}</div>}
                                            {sale.outstanding && sale.outstanding !== '0.00' && (
                                                <div className="text-muted-foreground text-xs">
                                                    {t('seller.open_amount')} Tk {sale.outstanding}
                                                </div>
                                            )}
                                        </td>
                                        <td className="p-3 text-right tabular-nums">Tk {sale.total}</td>
                                        <td className="p-3">
                                            {sale.actions && forms && (
                                                <EngineActions
                                                    type="sales.invoice"
                                                    id={sale.invoice_id}
                                                    recordLabel={sale.invoice_number ?? sale.number}
                                                    actions={sale.actions.filter((a) => a.action === 'reverse')}
                                                    reasons={forms.reversal_reasons}
                                                    labels={{ reverse: t('seller.void_sale') }}
                                                    redirectTo={here}
                                                />
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            {canManage && forms && mode && (
                <SellerDialog
                    mode={mode}
                    open={mode !== null}
                    onOpenChange={(open) => !open && setMode(null)}
                    employeeId={s.employee_id}
                    sellerName={s.name}
                    forms={forms}
                    submission={submission}
                />
            )}
        </AppLayout>
    );
}
