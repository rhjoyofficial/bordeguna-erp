import { ActionDialog } from '@/components/action-dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { useTranslation } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { useMemo, useState } from 'react';

// A type, not an interface: it travels in an Inertia payload (index signature).
type FormLine = {
    id: number;
    quantity: string;
    batch_number: string;
    manufactured_date: string;
    expiry_date: string;
    different_lot: boolean;
};

interface Props {
    receipt: {
        id: number;
        received_on: string | null;
        supplier_challan_number: string;
        note: string;
        order: { id: number | null; po_number: string | null };
        supplier: string | null;
        lines: {
            id: number;
            product: string | null;
            sku: string | null;
            unit: string | null;
            quantity: string;
            has_batch: boolean;
            tracks_expiry: boolean;
            batch_number: string;
            manufactured_date: string;
            expiry_date: string;
        }[];
    };
    availability: {
        allowed: boolean;
        requestable: boolean;
        blockers: { code: string; message: string; url: string | null }[];
        date: { rule: string; sentence: string } | null;
    };
    correctionReasons: { id: number; name: string }[];
    type: string;
    today: string;
}

/**
 * ⭐ Correct a goods receipt (corrections wave 1, 19 Oct 2026; FINAL-PLAN 3.3).
 *
 * The receipt as it was entered, pre-filled, the date box first (Joy, 19 Oct).
 * Nothing is saved from this page: "Preview and correct" opens the action
 * dialog, which shows the dates, the stock and money effect and what stays,
 * then does it in one go. Two kinds, decided by the server from what changed:
 * the date alone moves the SAME batch (billed, paid or partly sold is fine);
 * a quantity or "Different lot" takes the goods back out and receives them
 * again (only while untouched and unbilled). The original stays, marked.
 *
 * ⚠ No <form> element and no key handling: only the button acts.
 */
export default function CorrectReceipt({ receipt, availability, correctionReasons, type, today }: Props) {
    const { t } = useTranslation();
    const label = t('receipts.receipt_no', { id: receipt.id });
    const [receivedOn, setReceivedOn] = useState(receipt.received_on ?? today);
    const [challan, setChallan] = useState(receipt.supplier_challan_number);
    const [note, setNote] = useState(receipt.note);
    const [lines, setLines] = useState<FormLine[]>(() =>
        receipt.lines.map((line) => ({
            id: line.id,
            quantity: line.quantity,
            batch_number: line.batch_number,
            manufactured_date: line.manufactured_date,
            expiry_date: line.expiry_date,
            different_lot: false,
        })),
    );

    const setLine = (index: number, patch: Partial<FormLine>) =>
        setLines((all) => all.map((line, i) => (i === index ? { ...line, ...patch } : line)));

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Purchases', href: '/purchasing/overview' },
        { title: t('receipts.title'), href: '/purchasing/receipts' },
        { title: label, href: `/purchasing/receipts/${receipt.id}` },
        { title: t('corr.grn.correct'), href: '#' },
    ];

    const usable = availability.allowed || availability.requestable;

    // Memoised: the dialog previews again whenever this changes, and only then.
    const replacement = useMemo(
        () => ({
            received_on: receivedOn,
            supplier_challan_number: challan,
            note,
            lines: lines.map((line) => ({
                ...line,
                manufactured_date: line.manufactured_date === '' ? null : line.manufactured_date,
                expiry_date: line.expiry_date === '' ? null : line.expiry_date,
            })),
        }),
        [receivedOn, challan, note, lines],
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('corr.grn.correct_title', { label })} />

            <div className="flex max-w-4xl flex-col gap-4 p-4">
                <h1 className="text-xl font-semibold">{t('corr.grn.correct_title', { label })}</h1>
                <p className="text-muted-foreground text-sm">
                    {receipt.supplier}
                    {receipt.order.po_number && (
                        <>
                            {' · '}
                            <Link href={`/purchasing/purchase-orders/${receipt.order.id}`} className="hover:underline">
                                {receipt.order.po_number}
                            </Link>
                        </>
                    )}
                </p>

                <div className="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-700 dark:bg-amber-950/40">
                    {t('corr.grn.correct_banner', { label })}
                    {availability.date?.sentence ? ` ${availability.date.sentence}` : ''}
                </div>

                {!usable &&
                    availability.blockers.map((blocker) => (
                        <p key={blocker.code} className="text-destructive text-sm">
                            {blocker.message}
                        </p>
                    ))}

                <div className="flex flex-wrap gap-4">
                    <div className="grid gap-1.5">
                        <Label htmlFor="received_on" required>
                            {t('corr.grn.received_on')}
                        </Label>
                        <Input
                            id="received_on"
                            type="date"
                            max={today}
                            className="w-44"
                            value={receivedOn}
                            onChange={(e) => setReceivedOn(e.target.value)}
                        />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="challan">{t('corr.grn.challan')}</Label>
                        <Input id="challan" className="w-48" value={challan} onChange={(e) => setChallan(e.target.value)} />
                    </div>
                    <div className="grid flex-1 gap-1.5">
                        <Label htmlFor="note">{t('corr.grn.note')}</Label>
                        <Input id="note" value={note} onChange={(e) => setNote(e.target.value)} />
                    </div>
                </div>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full min-w-[52rem] text-sm [&_td]:border-t [&_td]:px-3 [&_td]:py-2 [&_th]:px-3 [&_th]:py-2 [&_th]:text-left [&_th]:text-xs [&_th]:font-medium">
                        <thead className="text-muted-foreground">
                            <tr>
                                <th>{t('receipts.col_product')}</th>
                                <th>{t('corr.grn.mfg')}</th>
                                <th>{t('corr.grn.expiry')}</th>
                                <th>{t('corr.grn.quantity')}</th>
                                <th>{t('corr.grn.batch_number')}</th>
                                <th>{t('corr.grn.different_lot')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {receipt.lines.map((line, index) => (
                                <tr key={line.id} className="align-top">
                                    <td>
                                        <div className="font-medium">{line.product}</div>
                                        <div className="text-muted-foreground text-xs">{line.sku}</div>
                                    </td>
                                    <td>
                                        {line.has_batch && (
                                            <Input
                                                type="date"
                                                aria-label={t('corr.grn.mfg')}
                                                className="w-40"
                                                value={lines[index].manufactured_date}
                                                onChange={(e) => setLine(index, { manufactured_date: e.target.value })}
                                            />
                                        )}
                                    </td>
                                    <td>
                                        {line.has_batch && (
                                            <Input
                                                type="date"
                                                aria-label={t('corr.grn.expiry')}
                                                className="w-40"
                                                value={lines[index].expiry_date}
                                                onChange={(e) => setLine(index, { expiry_date: e.target.value })}
                                            />
                                        )}
                                    </td>
                                    <td>
                                        <div className="flex items-center gap-1.5">
                                            <Input
                                                inputMode="decimal"
                                                aria-label={t('corr.grn.quantity')}
                                                className="w-28 text-right tabular-nums"
                                                value={lines[index].quantity}
                                                onChange={(e) => setLine(index, { quantity: e.target.value })}
                                            />
                                            <span className="text-muted-foreground text-xs">{line.unit}</span>
                                        </div>
                                    </td>
                                    <td>
                                        {line.has_batch && (
                                            <Input
                                                aria-label={t('corr.grn.batch_number')}
                                                className="w-40"
                                                value={lines[index].batch_number}
                                                onChange={(e) => setLine(index, { batch_number: e.target.value })}
                                            />
                                        )}
                                    </td>
                                    <td>
                                        {line.has_batch && (
                                            <input
                                                type="checkbox"
                                                aria-label={t('corr.grn.different_lot')}
                                                checked={lines[index].different_lot}
                                                onChange={(e) => setLine(index, { different_lot: e.target.checked })}
                                            />
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <p className="text-muted-foreground text-xs">{t('corr.grn.price_note')}</p>

                <div className="flex gap-2">
                    <Button variant="outline" asChild>
                        <Link href={`/purchasing/receipts/${receipt.id}`}>{t('corr.go_back')}</Link>
                    </Button>
                    <ActionDialog
                        action="correct"
                        recordLabel={label}
                        previewUrl={`/records/${type}/${receipt.id}/correct/preview`}
                        submitUrl={`/records/${type}/${receipt.id}/correct`}
                        reasons={correctionReasons}
                        replacement={replacement}
                        redirectTo="/purchasing/receipts"
                        trigger={<Button disabled={!usable}>{t('corr.grn.preview_correct')}</Button>}
                    />
                </div>
            </div>
        </AppLayout>
    );
}
