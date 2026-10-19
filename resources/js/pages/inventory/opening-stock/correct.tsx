import { ActionDialog } from '@/components/action-dialog';
import { inventoryCrumbs } from '@/components/inventory/inventory-ui';
import { type StockAvailability } from '@/components/inventory/stock-reason-dialog';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { formatDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { decimalInput, formatMoney } from '@/lib/utils';
import { Head, Link } from '@inertiajs/react';
import { useMemo, useState } from 'react';

// A type, not an interface: it travels in an Inertia payload (index signature).
type FormLine = {
    line_id: number | null;
    product_id: string;
    quantity: string;
    unit_cost: string;
    batch_number: string;
    expiry_date: string;
};

type ExistingLine = FormLine & {
    line_id: number;
    product: string | null;
    unit: string | null;
    on_hand: string;
    tracks_expiry: boolean;
};

interface Props {
    opening: {
        id: number;
        number: string;
        warehouse: string | null;
        as_at: string;
        note: string;
        total: string;
        lines: ExistingLine[];
    };
    availability: StockAvailability;
    type: string;
    correctionReasons: { id: number; name: string }[];
    products: { id: number; name: string; sku: string; unit: string | null; tracks_expiry: boolean }[];
}

const emptyLine = (): FormLine => ({ line_id: null, product_id: '', quantity: '', unit_cost: '', batch_number: '', expiry_date: '' });

/**
 * ⭐ Corrections wave 1, B4 (20 Oct 2026): Correct an opening stock
 * (FINAL-PLAN 3.3). Every line, pre-filled; change a count, a cost, a batch
 * number or an expiry, set a line to 0 to take it out, or add a product the
 * count missed. Nothing is saved from this page: "Preview and correct" opens
 * the action dialog, which shows what moves, then posts the difference to
 * Opening Balance Equity on the opening date (never to Inventory Write-offs).
 *
 * ⚠ No <form>: saving happens only by clicking the button (Joy, 19 Oct 2026).
 */
export default function CorrectOpeningStock({ opening, availability, type, correctionReasons, products }: Props) {
    const { t } = useTranslation();
    const [lines, setLines] = useState<FormLine[]>(
        opening.lines.map(({ line_id, product_id, quantity, unit_cost, batch_number, expiry_date }) => ({
            line_id,
            product_id,
            quantity,
            unit_cost,
            batch_number,
            expiry_date,
        })),
    );
    const [added, setAdded] = useState<FormLine[]>([]);
    const [note, setNote] = useState(opening.note);

    const breadcrumbs = inventoryCrumbs('Setup', 'Opening stock', {
        title: `Correct ${opening.number}`,
        href: `/inventory/opening-stock/${opening.id}/correct`,
    });
    const usable = availability.allowed || availability.requestable;
    const base = `/records/${type}/${opening.id}`;
    const productById = new Map(products.map((p) => [String(p.id), p]));

    const setLine = (index: number, patch: Partial<FormLine>) =>
        setLines((previous) => previous.map((l, i) => (i === index ? { ...l, ...patch } : l)));
    const setAddedLine = (index: number, patch: Partial<FormLine>) =>
        setAdded((previous) => previous.map((l, i) => (i === index ? { ...l, ...patch } : l)));

    const total = [...lines, ...added].reduce((sum, l) => sum + (Number(l.quantity) || 0) * (Number(l.unit_cost) || 0), 0);

    // Memoised: the dialog previews again whenever this changes, and only then.
    const replacement = useMemo(
        () => ({
            note,
            lines: [...lines, ...added.filter((l) => l.product_id !== '' && l.quantity !== '')].map((l) => ({
                ...l,
                batch_number: l.batch_number === '' ? null : l.batch_number,
                expiry_date: l.expiry_date === '' ? null : l.expiry_date,
            })),
        }),
        [lines, added, note],
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Correct ${opening.number}`} />
            <div className="flex max-w-5xl flex-col gap-4 p-4">
                <h1 className="text-lg font-semibold">{t('corr.stock.opening.correct_title', { number: opening.number })}</h1>

                <div className="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-700 dark:bg-amber-950/40">
                    {t('corr.stock.opening.banner', { warehouse: opening.warehouse ?? '', date: formatDate(opening.as_at), number: opening.number })}
                    {availability.date?.sentence ? ` ${availability.date.sentence}` : ''}
                </div>

                {!usable &&
                    availability.blockers.map((blocker) => (
                        <p key={blocker.code} className="text-destructive text-sm">
                            {blocker.message}
                        </p>
                    ))}

                <div className="overflow-x-auto">
                    <table className="w-full min-w-[56rem] text-sm">
                        <thead>
                            <tr className="text-muted-foreground border-b text-left">
                                <th className="py-2 pr-3">{t('corr.stock.opening.col_product')}</th>
                                <th className="py-2 pr-3">{t('corr.stock.opening.col_qty')}</th>
                                <th className="py-2 pr-3">{t('corr.stock.opening.col_cost')}</th>
                                <th className="py-2 pr-3">{t('corr.stock.batch')}</th>
                                <th className="py-2 pr-3">{t('corr.stock.opening.col_expiry')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {lines.map((line, index) => {
                                const original = opening.lines[index];

                                return (
                                    <tr key={line.line_id ?? `l-${index}`} className="border-b align-top">
                                        <td className="py-2 pr-3">
                                            {original.product}
                                            <span className="text-muted-foreground block text-xs">
                                                {t('corr.stock.opening.on_hand', { quantity: original.on_hand, unit: original.unit ?? '' })}
                                            </span>
                                        </td>
                                        <td className="py-2 pr-3">
                                            <Input
                                                aria-label={t('corr.stock.opening.col_qty')}
                                                inputMode="decimal"
                                                className="w-28"
                                                value={line.quantity}
                                                onChange={(e) => setLine(index, { quantity: decimalInput(e.target.value) })}
                                            />
                                            {line.quantity !== original.quantity && (
                                                <span className="text-muted-foreground block text-xs">
                                                    {t('corr.stock.opening.was', { value: original.quantity })}
                                                </span>
                                            )}
                                        </td>
                                        <td className="py-2 pr-3">
                                            <Input
                                                aria-label={t('corr.stock.opening.col_cost')}
                                                inputMode="decimal"
                                                className="w-28"
                                                value={line.unit_cost}
                                                onChange={(e) => setLine(index, { unit_cost: decimalInput(e.target.value) })}
                                            />
                                        </td>
                                        <td className="py-2 pr-3">
                                            <Input
                                                aria-label={t('corr.stock.batch')}
                                                className="w-36"
                                                value={line.batch_number}
                                                onChange={(e) => setLine(index, { batch_number: e.target.value })}
                                            />
                                        </td>
                                        <td className="py-2 pr-3">
                                            <Input
                                                aria-label={t('corr.stock.opening.col_expiry')}
                                                type="date"
                                                className="w-auto"
                                                value={line.expiry_date}
                                                onChange={(e) => setLine(index, { expiry_date: e.target.value })}
                                            />
                                        </td>
                                    </tr>
                                );
                            })}
                            {added.map((line, index) => (
                                <tr key={`new-${index}`} className="border-b align-top">
                                    <td className="py-2 pr-3">
                                        <SearchSelect
                                            id={`new-product-${index}`}
                                            value={line.product_id}
                                            onChange={(e) => setAddedLine(index, { product_id: e.target.value })}
                                        >
                                            <option value="">{t('corr.stock.opening.choose_product')}</option>
                                            {products.map((product) => (
                                                <option key={product.id} value={product.id}>
                                                    {product.name} ({product.sku})
                                                </option>
                                            ))}
                                        </SearchSelect>
                                    </td>
                                    <td className="py-2 pr-3">
                                        <Input
                                            aria-label={t('corr.stock.opening.col_qty')}
                                            inputMode="decimal"
                                            className="w-28"
                                            value={line.quantity}
                                            onChange={(e) => setAddedLine(index, { quantity: decimalInput(e.target.value) })}
                                        />
                                        <span className="text-muted-foreground block text-xs">{productById.get(line.product_id)?.unit ?? ''}</span>
                                    </td>
                                    <td className="py-2 pr-3">
                                        <Input
                                            aria-label={t('corr.stock.opening.col_cost')}
                                            inputMode="decimal"
                                            className="w-28"
                                            value={line.unit_cost}
                                            onChange={(e) => setAddedLine(index, { unit_cost: decimalInput(e.target.value) })}
                                        />
                                    </td>
                                    <td className="py-2 pr-3">
                                        <Input
                                            aria-label={t('corr.stock.batch')}
                                            className="w-36"
                                            value={line.batch_number}
                                            placeholder={t('corr.stock.opening.batch_auto')}
                                            onChange={(e) => setAddedLine(index, { batch_number: e.target.value })}
                                        />
                                    </td>
                                    <td className="py-2 pr-3">
                                        <Input
                                            aria-label={t('corr.stock.opening.col_expiry')}
                                            type="date"
                                            className="w-auto"
                                            value={line.expiry_date}
                                            onChange={(e) => setAddedLine(index, { expiry_date: e.target.value })}
                                        />
                                        <button
                                            type="button"
                                            className="text-muted-foreground mt-1 block text-xs underline"
                                            onClick={() => setAdded((previous) => previous.filter((_, i) => i !== index))}
                                        >
                                            {t('corr.stock.opening.remove_new')}
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <div>
                    <Button type="button" variant="outline" onClick={() => setAdded((previous) => [...previous, emptyLine()])}>
                        {t('corr.stock.opening.add_forgotten')}
                    </Button>
                </div>

                <div className="grid max-w-xl gap-2">
                    <Label htmlFor="opening-note">{t('corr.stock.note')}</Label>
                    <Input id="opening-note" value={note} onChange={(e) => setNote(e.target.value)} />
                </div>

                <p className="text-muted-foreground text-sm">
                    {t('corr.stock.opening.totals', { before: formatMoney(opening.total), after: formatMoney(total) })}
                </p>

                <div className="flex flex-wrap gap-2">
                    <ActionDialog
                        action="correct"
                        recordLabel={opening.number}
                        previewUrl={`${base}/correct/preview`}
                        submitUrl={`${base}/correct`}
                        reasons={correctionReasons}
                        replacement={replacement}
                        redirectTo="/inventory/opening-stock"
                        trigger={
                            <Button type="button" disabled={!usable}>
                                {t('corr.stock.preview_correct')}
                            </Button>
                        }
                    />
                    <Button type="button" variant="outline" asChild>
                        <Link href="/inventory/opening-stock">{t('corr.stock.go_back')}</Link>
                    </Button>
                </div>
            </div>
        </AppLayout>
    );
}
