import { DocumentLetterhead, type Issuer } from '@/components/document-letterhead';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

/**
 * ⭐ Initial plan B21 (R109): the paper a retail seller signs and keeps ("company
 * gave them a invoice also keep to company with price Trade Price"). It is not
 * an invoice: the goods stay the company's until sold. Printed from the
 * browser on the letterhead; two copies, one for him and one for the office.
 */
type Sheet = {
    number: string;
    kind: 'handover' | 'return';
    date: string;
    seller: string | null;
    seller_code: string | null;
    warehouse: string | null;
    reason: string | null;
    note: string | null;
    by: string | null;
    reversed: boolean;
    lines: { product: string | null; sku: string | null; quantity: string; trade_price: string; amount: string }[];
    total: string;
};

export default function SellerSheet({ issuer, sheet }: { issuer: Issuer; sheet: Sheet }) {
    const { t } = useTranslation();
    const handover = sheet.kind === 'handover';
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Sales', href: '/sales/overview' },
        { title: t('seller.title'), href: '/sales/sellers' },
        { title: sheet.number, href: '#' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={sheet.number} />
            <div className="space-y-4 p-4">
                <div className="flex justify-end print:hidden">
                    <Button size="sm" onClick={() => window.print()}>
                        {t('seller.print')}
                    </Button>
                </div>
                <div className="official-document mx-auto grid w-full max-w-4xl gap-6 rounded-xl border bg-white p-8 text-black print:max-w-none print:rounded-none print:border-0 print:p-0">
                    <DocumentLetterhead
                        issuer={issuer}
                        title={handover ? t('seller.sheet_title_handover') : t('seller.sheet_title_return')}
                        subtitle={sheet.number}
                    />

                    {sheet.reversed && <p className="font-semibold text-red-700">{t('seller.sheet_reversed')}</p>}

                    <div className="grid grid-cols-2 gap-2 text-sm">
                        <div>
                            {t('seller.date')}: <strong>{formatDate(sheet.date)}</strong>
                        </div>
                        <div>
                            {t('seller.name')}: <strong>{sheet.seller}</strong> {sheet.seller_code && `(${sheet.seller_code})`}
                        </div>
                        <div>
                            {handover ? t('seller.warehouse_from') : t('seller.warehouse_to')}: <strong>{sheet.warehouse}</strong>
                        </div>
                        {sheet.reason && <div>{sheet.reason}</div>}
                    </div>

                    <table className="w-full text-sm">
                        <thead className="border-b text-left">
                            <tr>
                                <th className="py-2">#</th>
                                <th className="py-2">{t('seller.item')}</th>
                                <th className="py-2 text-right">{t('seller.qty')}</th>
                                {handover && <th className="py-2 text-right">{t('seller.price_trade')}</th>}
                                {handover && <th className="py-2 text-right">{t('seller.amount')}</th>}
                            </tr>
                        </thead>
                        <tbody>
                            {sheet.lines.map((line, index) => (
                                <tr key={index} className="border-b">
                                    <td className="py-2">{index + 1}</td>
                                    <td className="py-2">
                                        {line.product} {line.sku && <span className="text-xs text-gray-600">{line.sku}</span>}
                                    </td>
                                    <td className="py-2 text-right tabular-nums">{line.quantity}</td>
                                    {handover && <td className="py-2 text-right tabular-nums">{line.trade_price}</td>}
                                    {handover && <td className="py-2 text-right tabular-nums">{line.amount}</td>}
                                </tr>
                            ))}
                        </tbody>
                        {handover && (
                            <tfoot>
                                <tr>
                                    <td colSpan={4} className="py-2 text-right font-semibold">
                                        {t('seller.total')}
                                    </td>
                                    <td className="py-2 text-right font-semibold tabular-nums">Tk {sheet.total}</td>
                                </tr>
                            </tfoot>
                        )}
                    </table>

                    {sheet.note && <p className="text-sm">{sheet.note}</p>}
                    <p className="text-xs text-gray-700">{t('seller.sheet_consignment')}</p>

                    <div className="mt-12 grid grid-cols-2 gap-12 text-sm">
                        <div className="border-t pt-2">{t('seller.sign_seller')}</div>
                        <div className="border-t pt-2">
                            {t('seller.sign_company')}
                            {sheet.by && <div className="text-xs text-gray-600">{sheet.by}</div>}
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
