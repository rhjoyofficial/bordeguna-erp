import { formatDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { formatMoney, formatQuantity } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * ⭐ What the full purchase order page saved, on the order page (purchase
 * redesign PR1, 18 Oct 2026): the supplier's reference, the buyer, terms and
 * conditions, each item's discount and VAT, the totals as stored, the charges
 * with how much of each has reached a delivery, the payment plan, the
 * advances paid against the total, and the files.
 *
 * ⚠ The server sends every figure (PurchaseOrderController::fullFigures);
 * nothing here adds anything up.
 */
export type FullOrder = {
    supplier_reference: string | null;
    buyer: string | null;
    terms_text: string | null;
    vat_in_cost: boolean;
    discount_type: string | null;
    discount_value: string | null;
    goods: string;
    discount: string;
    tax: string;
    charges_total: string;
    supplier_charges: string;
    rounding: string;
    grand_total: string;
    advance_paid: string;
    balance_due: string;
    lines: {
        id: number;
        description: string | null;
        unit: string | null;
        entered_quantity: string | null;
        entered_rate: string | null;
        gross: string;
        discount_label: string | null;
        discount: string;
        tax_rate: string;
        tax: string;
        net: string;
    }[];
    charges: {
        id: number;
        type: string;
        type_label: string;
        description: string | null;
        amount: string;
        into_cost: boolean;
        basis: string;
        basis_label: string;
        item: string | null;
        payee: string | null;
        paid_from_account: boolean;
        landed: string;
        remaining: string;
    }[];
    schedule: { due_on: string | null; amount: string; note: string | null }[];
    attachments: { id: number; name: string; uploaded_by: string | null; url: string }[];
};

const nonZero = (value: string) => Number(String(value).replace(/,/g, '')) !== 0;

export function PurchaseOrderFull({
    full,
    orderId,
    productNames,
    canAttach,
}: {
    full: FullOrder;
    orderId: number;
    /** Order line id to product name, from the order the page already has. */
    productNames: Record<number, string>;
    canAttach: boolean;
}) {
    const { t, tr } = useTranslation();
    const [uploading, setUploading] = useState(false);
    const anyDiscountOrVat =
        full.lines.some((line) => nonZero(line.discount) || nonZero(line.tax)) || nonZero(full.supplier_charges) || nonZero(full.rounding);

    const upload = (file: File | undefined) => {
        if (!file) return;
        setUploading(true);
        router.post(
            `/purchasing/purchase-orders/${orderId}/attachments`,
            { file },
            { preserveScroll: true, forceFormData: true, onFinish: () => setUploading(false) },
        );
    };

    return (
        <>
            {(full.supplier_reference || full.buyer || full.terms_text) && (
                <section className="bg-card rounded-xl border p-4">
                    <p className="text-[15px] font-semibold">{t('poshow.details')}</p>
                    <dl className="mt-2 grid gap-1.5 text-[13px] sm:grid-cols-2">
                        {full.supplier_reference && (
                            <div>
                                <dt className="text-muted-foreground text-xs">{t('poshow.supplier_reference')}</dt>
                                <dd>{full.supplier_reference}</dd>
                            </div>
                        )}
                        {full.buyer && (
                            <div>
                                <dt className="text-muted-foreground text-xs">{t('poshow.buyer')}</dt>
                                <dd>{full.buyer}</dd>
                            </div>
                        )}
                        {full.terms_text && (
                            <div className="sm:col-span-2">
                                <dt className="text-muted-foreground text-xs">{t('poshow.terms_text')}</dt>
                                <dd className="whitespace-pre-line">{full.terms_text}</dd>
                            </div>
                        )}
                    </dl>
                </section>
            )}

            <section className="bg-card rounded-xl border p-4">
                <p className="text-[15px] font-semibold">{t('poshow.figures')}</p>
                {anyDiscountOrVat && (
                    <div className="mt-2 overflow-x-auto">
                        <table className="w-full text-[13px]">
                            <thead className="text-muted-foreground text-left text-xs">
                                <tr>
                                    <th className="py-1 pr-2">{t('poshow.item')}</th>
                                    <th className="py-1 pr-2 text-right">{t('poshow.typed_as')}</th>
                                    <th className="py-1 pr-2 text-right">{t('poshow.gross')}</th>
                                    <th className="py-1 pr-2 text-right">{t('poshow.discount')}</th>
                                    <th className="py-1 pr-2 text-right">{t('poshow.vat')}</th>
                                    <th className="py-1 text-right">{t('poshow.net')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {full.lines.map((line) => (
                                    <tr key={line.id} className="border-t">
                                        <td className="py-1.5 pr-2">
                                            {productNames[line.id] ?? '-'}
                                            {line.description && <span className="text-muted-foreground block text-xs">{line.description}</span>}
                                        </td>
                                        <td className="py-1.5 pr-2 text-right tabular-nums">
                                            {line.entered_quantity ? formatQuantity(line.entered_quantity) : ''} {line.unit ?? ''}
                                            {line.entered_rate && (
                                                <span className="text-muted-foreground block text-xs">@ {formatMoney(line.entered_rate)}</span>
                                            )}
                                        </td>
                                        <td className="py-1.5 pr-2 text-right tabular-nums">{formatMoney(line.gross)}</td>
                                        <td className="py-1.5 pr-2 text-right tabular-nums">
                                            {nonZero(line.discount) ? `-${formatMoney(line.discount)}` : ''}
                                            {line.discount_label && (
                                                <span className="text-muted-foreground block text-xs">{line.discount_label}</span>
                                            )}
                                        </td>
                                        <td className="py-1.5 pr-2 text-right tabular-nums">
                                            {nonZero(line.tax) ? formatMoney(line.tax) : ''}
                                            {nonZero(line.tax_rate) && <span className="text-muted-foreground block text-xs">{line.tax_rate}%</span>}
                                        </td>
                                        <td className="py-1.5 text-right font-medium tabular-nums">{formatMoney(line.net)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <dl className="mt-3 ml-auto grid max-w-xs gap-1 text-[13px]">
                    <div className="flex justify-between gap-2">
                        <dt className="text-muted-foreground">{t('poshow.subtotal')}</dt>
                        <dd className="tabular-nums">{formatMoney(full.goods)}</dd>
                    </div>
                    {nonZero(full.discount) && (
                        <div className="flex justify-between gap-2">
                            <dt className="text-muted-foreground">
                                {t('poshow.discounts')}
                                {full.discount_type === 'percent' && full.discount_value ? ` (${full.discount_value}%)` : ''}
                            </dt>
                            <dd className="tabular-nums">-{formatMoney(full.discount)}</dd>
                        </div>
                    )}
                    {nonZero(full.tax) && (
                        <div className="flex justify-between gap-2">
                            <dt className="text-muted-foreground">{t('poshow.vat')}</dt>
                            <dd className="tabular-nums">{formatMoney(full.tax)}</dd>
                        </div>
                    )}
                    {nonZero(full.supplier_charges) && (
                        <div className="flex justify-between gap-2">
                            <dt className="text-muted-foreground">{t('poshow.supplier_charges')}</dt>
                            <dd className="tabular-nums">{formatMoney(full.supplier_charges)}</dd>
                        </div>
                    )}
                    {nonZero(full.rounding) && (
                        <div className="flex justify-between gap-2">
                            <dt className="text-muted-foreground">{t('poshow.rounding')}</dt>
                            <dd className="tabular-nums">{formatMoney(full.rounding)}</dd>
                        </div>
                    )}
                    <div className="flex justify-between gap-2 border-t pt-1 font-semibold">
                        <dt>{t('poshow.grand_total')}</dt>
                        <dd className="tabular-nums">{formatMoney(full.grand_total)}</dd>
                    </div>
                    {nonZero(full.advance_paid) && (
                        <>
                            <div className="flex justify-between gap-2">
                                <dt className="text-muted-foreground">{t('poshow.advance_paid')}</dt>
                                <dd className="tabular-nums">-{formatMoney(full.advance_paid)}</dd>
                            </div>
                            <div className="flex justify-between gap-2 font-medium">
                                <dt>{t('poshow.balance_due')}</dt>
                                <dd className="tabular-nums">{formatMoney(full.balance_due)}</dd>
                            </div>
                        </>
                    )}
                </dl>
                {full.vat_in_cost && nonZero(full.tax) && <p className="text-muted-foreground mt-2 text-xs">{t('poshow.vat_in_cost')}</p>}
            </section>

            {full.charges.length > 0 && (
                <section className="bg-card rounded-xl border p-4">
                    <p className="text-[15px] font-semibold">{t('poshow.charges')}</p>
                    <div className="mt-2 overflow-x-auto">
                        <table className="w-full text-[13px]">
                            <thead className="text-muted-foreground text-left text-xs">
                                <tr>
                                    <th className="py-1 pr-2">{t('poshow.charge')}</th>
                                    <th className="py-1 pr-2">{t('poshow.split')}</th>
                                    <th className="py-1 pr-2">{t('poshow.payee')}</th>
                                    <th className="py-1 pr-2 text-right">{t('pof.amount')}</th>
                                    <th className="py-1 pr-2 text-right">{t('poshow.landed')}</th>
                                    <th className="py-1 text-right">{t('poshow.remaining')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {full.charges.map((charge) => (
                                    <tr key={charge.id} className="border-t">
                                        <td className="py-1.5 pr-2">
                                            {tr(`landed.type.${charge.type}`, charge.type_label)}
                                            <span className="text-muted-foreground block text-xs">
                                                {charge.into_cost ? t('poshow.into_cost') : t('poshow.expense')}
                                                {charge.description ? `, ${charge.description}` : ''}
                                            </span>
                                        </td>
                                        <td className="py-1.5 pr-2 text-xs">
                                            {charge.into_cost ? tr(`landed.method.${charge.basis}`, charge.basis_label) : ''}
                                            {charge.item ? `: ${charge.item}` : ''}
                                        </td>
                                        <td className="py-1.5 pr-2 text-xs">{charge.payee}</td>
                                        <td className="py-1.5 pr-2 text-right tabular-nums">{formatMoney(charge.amount)}</td>
                                        <td className="py-1.5 pr-2 text-right tabular-nums">{formatMoney(charge.landed)}</td>
                                        <td className="py-1.5 text-right tabular-nums">{formatMoney(charge.remaining)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            )}

            {full.schedule.length > 0 && (
                <section className="bg-card rounded-xl border p-4">
                    <p className="text-[15px] font-semibold">{t('poshow.schedule')}</p>
                    <ul className="mt-2 grid gap-1 text-[13px]">
                        {full.schedule.map((row, index) => (
                            <li key={index} className="flex justify-between gap-2">
                                <span>
                                    {formatDate(row.due_on)}
                                    {row.note && <span className="text-muted-foreground"> · {row.note}</span>}
                                </span>
                                <span className="tabular-nums">{formatMoney(row.amount)}</span>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            <section className="bg-card rounded-xl border p-4">
                <p className="text-[15px] font-semibold">{t('poshow.attachments')}</p>
                {full.attachments.length === 0 ? (
                    <p className="text-muted-foreground mt-1 text-xs">{t('poshow.no_attachments')}</p>
                ) : (
                    <ul className="mt-2 grid gap-1 text-[13px]">
                        {full.attachments.map((file) => (
                            <li key={file.id} className="flex items-center gap-2">
                                <a href={file.url} target="_blank" rel="noreferrer" className="underline">
                                    {file.name}
                                </a>
                                {file.uploaded_by && <span className="text-muted-foreground text-xs">{file.uploaded_by}</span>}
                                {canAttach && (
                                    <button
                                        type="button"
                                        className="text-muted-foreground hover:text-destructive text-xs"
                                        onClick={() =>
                                            router.delete(`/purchasing/purchase-orders/${orderId}/attachments/${file.id}`, { preserveScroll: true })
                                        }
                                    >
                                        {t('poshow.remove')}
                                    </button>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
                {canAttach && (
                    <label className="text-muted-foreground hover:text-foreground mt-2 block cursor-pointer text-xs underline">
                        {uploading ? t('poshow.uploading') : t('poshow.attach')}
                        <input
                            type="file"
                            className="hidden"
                            accept=".pdf,.jpg,.jpeg,.png,.webp,.csv,.xls,.xlsx,.doc,.docx"
                            onChange={(e) => upload(e.target.files?.[0])}
                        />
                    </label>
                )}
            </section>
        </>
    );
}
