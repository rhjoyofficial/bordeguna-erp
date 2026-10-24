import { ActionDialog } from '@/components/action-dialog';
import { DateWords } from '@/components/form/date-words';
import InputError from '@/components/input-error';
import { inventoryCrumbs } from '@/components/inventory/inventory-ui';
import { ProductionBatchPicker, type BatchPick } from '@/components/inventory/production-batch-picker';
import { type StockAvailability } from '@/components/inventory/stock-reason-dialog';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { dhakaToday } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { fifoEstimate, fromStock, outputFromComponent, recipeStep, suggestQuantity, toStock, type CostLayer } from '@/lib/recipe-fill';
import { decimalInput, formatMoney, formatQuantity } from '@/lib/utils';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect, useMemo, useRef, useState } from 'react';

const selectClass = 'h-10 w-full min-w-0 rounded-md border border-input bg-background px-3 text-sm';

interface Line {
    [key: string]: string | undefined | BatchPick[];
    product_id: string;
    quantity: string;
    waste: string;
    /** PR3: the unit the quantity and waste are typed in; blank = the stock unit. */
    unit_id: string;
    /** ⭐ B8 (Joy, 24 Oct 2026; Q8 = B): every batch the line comes from, in the stock unit. */
    batches: BatchPick[];
}

interface UnitOption {
    id: number;
    abbreviation: string;
    /** Stock units per one of this unit, for the PREVIEW only. */
    factor: string;
}

interface ProductOption {
    id: number;
    name: string;
    sku: string;
    unit: string | null;
    stock_unit_id: number;
    tracks_expiry: boolean;
    /** PR3: counted in whole jars/pcs (a count unit, fractions not allowed). */
    whole_only: boolean;
    /** PR3: units the server can convert into the stock unit; the stock unit first. */
    units: UnitOption[];
}

interface CanMake {
    quantity: string;
    limited_by_product_id: number;
    limited_by: string | null;
}

interface CostLine {
    [key: string]: string | undefined;
    kind: string;
    account_id: string;
    amount: string;
    note: string;
}

interface CostKind {
    value: string;
    label: string;
    /** The head suggested for this kind, when the chart has it. */
    account_id: number | null;
}

interface Bom {
    id: number;
    version: number;
    name: string | null;
    output_quantity: string;
    lines: { product_id: number; quantity_per_output: string; wastage_percent: string }[];
}

/**
 * ⚠⚠ C-BOM-3. A SUGGESTION, NEVER A DECISION. Everything computed here lands in
 * an editable box; the run records what the operator says was actually
 * consumed, and the server recomputes the STANDARD it is measured against from
 * the recipe itself (C-BOM-2). The arithmetic lives in lib/recipe-fill.ts
 * (PR3), in the component's stock unit (C-BOM-4); a line typed in another
 * unit is converted by the unit's preview factor and again by the server.
 */
const breadcrumbs = inventoryCrumbs('Production', 'Runs', { title: 'New run', href: '/inventory/production-runs/new' });

/** ⭐ Corrections wave 1 (20 Oct 2026): a recorded run as this form shows it (ProductionRunForm::of). */
type RunForm = {
    warehouse_id: string;
    output_product_id: string;
    output_quantity: string;
    bill_of_material_id: string;
    output_expiry_date: string;
    output_mrp: string;
    occurred_at: string;
    expense_waste: boolean;
    note: string;
    lines: Line[];
    costs: CostLine[];
};

const emptyLine = (): Line => ({ product_id: '', quantity: '', waste: '', unit_id: '', batches: [] });

const emptyCost = (kind: CostKind | undefined): CostLine => ({
    kind: kind?.value ?? 'labour',
    account_id: kind?.account_id ? String(kind.account_id) : '',
    amount: '',
    note: '',
});

export default function ProductionRunCreate({
    warehouses,
    defaultWarehouseId,
    products,
    stockByProduct,
    bomsByOutputProduct,
    canMake,
    costLayers,
    costKinds,
    expenseAccounts,
    initialOutputProductId,
    mode = 'new',
    run,
    availability,
    correctionType,
    correctionReasons = [],
}: {
    /** ⭐ Corrections wave 1 (20 Oct 2026): the same form edits a draft and corrects a confirmed run. */
    mode?: 'new' | 'edit' | 'correct';
    run?: { id: number; run_number: string; form: RunForm };
    availability?: StockAvailability;
    correctionType?: string;
    correctionReasons?: { id: number; name: string }[];
    warehouses: { id: number; name: string; code: string }[];
    defaultWarehouseId: number | null;
    products: ProductOption[];
    stockByProduct: Record<string, Record<string, string>>;
    bomsByOutputProduct: Record<string, Bom>;
    canMake: Record<string, Record<string, CanMake | null>>;
    costLayers: Record<string, Record<string, CostLayer[]>>;
    costKinds: CostKind[];
    expenseAccounts: { id: number; label: string }[];
    initialOutputProductId: number | null;
}) {
    const { data, setData, post, put, errors, processing, transform } = useForm({
        warehouse_id: run?.form.warehouse_id ?? String(defaultWarehouseId ?? warehouses[0]?.id ?? ''),
        output_product_id:
            run?.form.output_product_id ??
            (initialOutputProductId && products.some((p) => p.id === initialOutputProductId) ? String(initialOutputProductId) : ''),
        output_quantity: run?.form.output_quantity ?? '',
        bill_of_material_id: run?.form.bill_of_material_id ?? '',
        output_expiry_date: run?.form.output_expiry_date ?? '',
        output_mrp: run?.form.output_mrp ?? '',
        occurred_at: run?.form.occurred_at ?? dhakaToday(),
        expense_waste: (run?.form.expense_waste ?? false) as boolean,
        note: run?.form.note ?? '',
        confirm: (mode === 'new') as boolean,
        record_again: false as boolean,
        lines: (run?.form.lines.length ? run.form.lines.map((line) => ({ ...line, batches: line.batches ?? [] })) : [emptyLine()]) as Line[],
        costs: (run?.form.costs ?? []) as CostLine[],
    });
    const { t } = useTranslation();

    const productById = new Map(products.map((p) => [String(p.id), p]));
    // ⭐ B8: batches belong to one warehouse, so another warehouse clears every pick.
    const changeWarehouse = (id: string) =>
        setData((current) => ({ ...current, warehouse_id: id, lines: current.lines.map((line) => ({ ...line, batches: [] })) }));
    const available = (productId: string) => stockByProduct[productId]?.[data.warehouse_id] ?? '0';

    // 17 Oct 2026: the same rule the server refuses by (RecordProductionRun).
    const expiryRequired = productById.get(data.output_product_id)?.tracks_expiry ?? false;

    const bom: Bom | undefined = bomsByOutputProduct[data.output_product_id];
    const outputProduct = productById.get(data.output_product_id);

    // PR3: the unit a line is typed in, and its preview factor.
    const unitsFor = (productId: string): UnitOption[] => productById.get(productId)?.units ?? [];
    const unitOf = (line: Line): UnitOption | undefined => {
        const options = unitsFor(line.product_id);

        return options.find((u) => String(u.id) === line.unit_id) ?? options[0];
    };
    const stockQuantity = (line: Line): number => toStock(line.quantity, unitOf(line)?.factor);
    const wholeOnly = (productId: string): boolean => productById.get(productId)?.whole_only ?? false;
    const stockUnitId = (productId: string): string => {
        const id = productById.get(productId)?.stock_unit_id;

        return id ? String(id) : '';
    };

    // ⭐ PR3: two-way fill. On (the default), changing the output fills every
    // recipe input, and changing a recipe input works out the output it
    // supports and fills the others. Off, a typed number is left alone.
    // ⭐ 20 Oct 2026: an edited or corrected run opens with what it recorded,
    // so the recipe never re-fills over it until somebody switches this on.
    const [autoFill, setAutoFill] = useState(run === undefined);
    const [drivenBy, setDrivenBy] = useState<{ productId: string; quantity: string; unit: string } | null>(null);
    // The output change below was made BY a component's fill: the effect must
    // not re-fill over the number the operator just typed.
    const skipNextFill = useRef(false);

    // P9 (18 Oct 2026): has the operator edited a line by hand? Until then the
    // recipe owns the table (lib/recipe-fill.ts), so re-scaling on a changed
    // output quantity never silently discards typed numbers.
    const linesTouched = useRef(run !== undefined);
    const [recipeEdited, setRecipeEdited] = useState(false);

    const recipeLines = (forBom: Bom, quantity: number): Line[] =>
        forBom.lines.map((line) => ({
            product_id: String(line.product_id),
            quantity: suggestQuantity(line, forBom.output_quantity, quantity, wholeOnly(String(line.product_id))),
            waste: '',
            unit_id: stockUnitId(String(line.product_id)),
            batches: [] as BatchPick[],
        }));

    // A null quantity lists the recipe's products and leaves the amounts blank.
    const applyRecipe = (forBom: Bom, quantity: number | null) => {
        const lines =
            quantity === null
                ? forBom.lines.map((line) => ({
                      product_id: String(line.product_id),
                      quantity: '',
                      waste: '',
                      unit_id: stockUnitId(String(line.product_id)),
                      batches: [] as BatchPick[],
                  }))
                : recipeLines(forBom, quantity);
        linesTouched.current = false;
        setRecipeEdited(false);
        setDrivenBy(null);
        setData((current) => ({ ...current, bill_of_material_id: String(forBom.id), lines: lines.length > 0 ? lines : [emptyLine()] }));
    };

    /**
     * PR3: re-fill the recipe's inputs IN PLACE for an output quantity, keeping
     * every other line, each line's waste, and (for `except`) the number the
     * operator typed. A recipe line typed in another unit is re-expressed in it.
     */
    const refillInPlace = (forBom: Bom, quantity: number, lines: Line[], except: number | null): Line[] =>
        lines.map((line, index) => {
            const spec = forBom.lines.find((l) => String(l.product_id) === line.product_id);

            if (!spec || index === except) {
                return line;
            }

            const stock = Number(suggestQuantity(spec, forBom.output_quantity, quantity, wholeOnly(line.product_id)));
            const unit = unitOf(line);

            // Whole jars stay whole: the stock figure is the one rounded.
            return {
                ...line,
                quantity: unit && String(unit.id) !== stockUnitId(line.product_id) ? fromStock(stock, unit.factor) : stock > 0 ? String(stock) : '',
            };
        });

    const recipeHolds = (forBom: Bom, lines: Line[]): boolean =>
        forBom.lines.length > 0 && forBom.lines.every((spec) => lines.some((line) => line.product_id === String(spec.product_id)));

    // C-BOM-3. Pre-fill on choosing the output, and re-scale when the output
    // quantity changes - but ONLY while no line has been edited by hand.
    useEffect(() => {
        if (!bom) {
            if (data.bill_of_material_id !== '') {
                // The lines came from another product's recipe and nobody edited
                // them: they belong to that product, not this one.
                if (!linesTouched.current) {
                    setData((current) => ({ ...current, bill_of_material_id: '', lines: [emptyLine()] }));
                } else {
                    setData('bill_of_material_id', '');
                }
            }

            return;
        }

        if (skipNextFill.current) {
            skipNextFill.current = false;

            return;
        }

        const step = recipeStep({ hasRecipe: true, quantity: data.output_quantity, touched: linesTouched.current });
        const quantity = Number(data.output_quantity);

        if (step === 'fill') {
            applyRecipe(bom, quantity);
        } else if (step === 'products-only') {
            applyRecipe(bom, null);
        } else if (autoFill && quantity > 0 && recipeHolds(bom, data.lines)) {
            // ⭐ PR3: edited lines, but every recipe input is still there: fill
            // the recipe's amounts in place, leave the rest as typed.
            setDrivenBy(null);
            setData((current) => ({ ...current, bill_of_material_id: String(bom.id), lines: refillInPlace(bom, quantity, current.lines, null) }));
        } else {
            setRecipeEdited(true);

            // ⚠ Bug hunt 16 Oct 2026: after the output changed, the typed lines
            // were kept but the OLD output's recipe id was still sent, and the
            // server refused it ("That recipe makes a different product"). The
            // lines are not this recipe's, so no recipe is claimed for them.
            if (data.bill_of_material_id !== '' && data.bill_of_material_id !== String(bom.id)) {
                setData('bill_of_material_id', '');
            }
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [data.output_product_id, data.output_quantity]);

    // F23 (practice run, 17 Oct 2026): the form starts on the default
    // warehouse, but the raw materials may sit elsewhere (Savar). Which inputs
    // does a warehouse not hold enough of, and which warehouse holds them all?
    const warehouseTouched = useRef(run !== undefined);
    const inputs = data.lines.filter((line) => line.product_id !== '');
    const shortIn = (warehouseId: string) =>
        inputs.filter((line) => {
            const have = Number(stockByProduct[line.product_id]?.[warehouseId] ?? '0');
            const need = stockQuantity(line);

            return Number.isFinite(need) && need > 0 ? have < need : have <= 0;
        });
    const shortHere = shortIn(data.warehouse_id);
    const holdsAll =
        inputs.length === 0 ? undefined : warehouses.find((w) => String(w.id) !== data.warehouse_id && shortIn(String(w.id)).length === 0);
    const [movedTo, setMovedTo] = useState<string | null>(null);

    // Not yet chosen by hand: move to the warehouse that holds every input.
    useEffect(() => {
        if (!warehouseTouched.current && shortHere.length > 0 && holdsAll) {
            changeWarehouse(String(holdsAll.id));
            setMovedTo(holdsAll.name);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [JSON.stringify(inputs), data.warehouse_id]);

    const touchLines = () => {
        linesTouched.current = true;
        if (bom) setRecipeEdited(true);
    };

    const setLine = (index: number, patch: Partial<Line>) => {
        touchLines();
        const lines = [...data.lines];
        lines[index] = { ...lines[index], ...patch };
        setData('lines', lines);
    };

    /**
     * ⭐ PR3: a quantity typed on a line. With the fill on and the line one of
     * the recipe's, the output becomes what this quantity supports and the
     * other inputs follow (Joy: "when I add qty of them, auto calculate how
     * many can be made, auto fill the necessary amount used").
     */
    const setLineQuantity = (index: number, quantity: string) => {
        const line = { ...data.lines[index], quantity };
        const spec = bom?.lines.find((l) => String(l.product_id) === line.product_id);

        if (!autoFill || !bom || !spec) {
            setLine(index, { quantity });

            return;
        }

        const output = outputFromComponent(spec, bom.output_quantity, stockQuantity(line), outputProduct?.whole_only ?? false);
        const lines = [...data.lines];
        lines[index] = line;
        // Typed by hand, so a later output change re-fills IN PLACE (units and
        // waste kept) rather than resetting the table; but the recipe still
        // owns the amounts, so the box does not say "left alone".
        linesTouched.current = true;

        if (output === '' || Number(output) <= 0) {
            setDrivenBy(null);
            setData('lines', lines);

            return;
        }

        skipNextFill.current = output !== data.output_quantity;
        setDrivenBy({ productId: line.product_id, quantity, unit: unitOf(line)?.abbreviation ?? '' });
        setData((current) => ({
            ...current,
            bill_of_material_id: recipeHolds(bom, lines) ? String(bom.id) : current.bill_of_material_id,
            output_quantity: output,
            lines: refillInPlace(bom, Number(output), lines, index),
        }));
    };

    // PR3: a unit change re-expresses the same amount; nothing is re-filled.
    const setLineUnit = (index: number, unitId: string) => {
        const line = data.lines[index];
        const unit = unitsFor(line.product_id).find((u) => String(u.id) === unitId);
        const lines = [...data.lines];
        lines[index] = {
            ...line,
            unit_id: unitId,
            quantity: line.quantity === '' ? '' : fromStock(stockQuantity(line), unit?.factor),
            waste: line.waste === '' ? '' : fromStock(toStock(line.waste, unitOf(line)?.factor), unit?.factor),
        };
        setData('lines', lines);
    };

    const can: CanMake | null = bom ? (canMake[data.output_product_id]?.[data.warehouse_id] ?? null) : null;

    // ⭐ PR3: the finished unit cost, live. Materials priced from the batches
    // FIFO would take in this warehouse, plus the cost lines. An estimate: the
    // run's real figure is set at confirm.
    const layersHere = costLayers[data.warehouse_id] ?? {};
    // ⭐ B8: product id => exact cost of its picked batches (null while they do not cover the line).
    const [pickedCost, setPickedCost] = useState<Record<string, number | null>>({});
    let materialsPaisa = 0;
    let wastePaisa = 0;
    let short = false;

    for (const line of data.lines) {
        const quantity = stockQuantity(line);

        if (!line.product_id || !(quantity > 0)) {
            continue;
        }

        // ⭐ B8: the exact cost of the batches picked, once they cover the line.
        const picked = pickedCost[line.product_id];
        const estimate =
            picked !== undefined && picked !== null ? { paisa: picked, short: false } : fifoEstimate(layersHere[line.product_id], quantity);
        materialsPaisa += estimate.paisa;
        short = short || estimate.short;

        const waste = toStock(line.waste, unitOf(line)?.factor);

        if (data.expense_waste && waste > 0) {
            wastePaisa += Math.round((estimate.paisa * Math.min(waste, quantity)) / quantity);
        }
    }

    const costsPaisa = data.costs.reduce((sum, cost) => sum + Math.round((Number(cost.amount) || 0) * 100), 0);
    const totalPaisa = materialsPaisa - wastePaisa + costsPaisa;
    const outputQuantity = Number(data.output_quantity);
    const showCost = outputQuantity > 0 && totalPaisa > 0;
    const taka = (paisa: number) => formatMoney(paisa / 100);

    const setCost = (index: number, patch: Partial<CostLine>) => {
        const costs = [...data.costs];
        costs[index] = { ...costs[index], ...patch };
        setData('costs', costs);
    };

    // The form as the server reads it: blanks as null, blank cost rows dropped.
    const payload = (form: typeof data) => ({
        ...form,
        output_expiry_date: form.output_expiry_date || null,
        output_mrp: form.output_mrp || null,
        note: form.note || null,
        bill_of_material_id: form.bill_of_material_id || null,
        lines: form.lines.map((line) => ({
            ...line,
            waste: line.waste || '0',
            unit_id: line.unit_id || null,
            // ⭐ B8: a picker row left without a batch is not a pick.
            batches: line.batches.filter((pick) => pick.stock_batch_id !== ''),
        })),
        // PR3: a cost row left blank is not a cost.
        costs: form.costs.filter((cost) => cost.amount !== '' || cost.account_id !== ''),
    });

    const send = (recordAgain: boolean) => {
        // P8 (18 Oct 2026): true only from the "same run a moment ago" warning.
        transform((form) => ({ ...payload(form), record_again: recordAgain }));

        if (mode === 'edit' && run) {
            put(route('inventory.production-runs.update', run.id));

            return;
        }

        post(route('inventory.production-runs.store'));
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        // ⚠ Correct never saves from here: only the dialog's own button does.
        if (mode === 'correct') {
            return;
        }

        send(false);
    };

    // ⭐ Correct: the edited run, for the action dialog (memoised: it previews
    // again whenever this changes, and only then).
    // eslint-disable-next-line react-hooks/exhaustive-deps
    const replacement = useMemo(() => payload(data), [JSON.stringify(data)]);
    const correctable = availability ? availability.allowed || availability.requestable : false;

    const formErrors = errors as Record<string, string>;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={run ? `${mode === 'edit' ? 'Edit' : 'Correct'} ${run.run_number}` : 'New production run'} />
            <div className="flex flex-col gap-6 p-4">
                {mode !== 'new' && run && (
                    <div className="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-700 dark:bg-amber-950/40">
                        {mode === 'edit'
                            ? t('corr.stock.run.editing', { number: run.run_number })
                            : t('corr.stock.run.correcting', { number: run.run_number })}
                        {mode === 'correct' && availability?.date?.sentence ? ` ${availability.date.sentence}` : ''}
                        {mode === 'correct' &&
                            availability &&
                            !correctable &&
                            availability.blockers.map((blocker) => (
                                <span key={blocker.code} className="text-destructive block">
                                    {blocker.message}
                                </span>
                            ))}
                    </div>
                )}
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <div className="grid gap-4 md:grid-cols-3">
                        <div className="grid gap-2">
                            <Label htmlFor="warehouse_id" required>
                                Warehouse
                            </Label>
                            <SearchSelect
                                id="warehouse_id"
                                value={data.warehouse_id}
                                onChange={(e) => {
                                    warehouseTouched.current = true;
                                    setMovedTo(null);
                                    changeWarehouse(e.target.value);
                                }}
                            >
                                {warehouses.map((w) => (
                                    <option key={w.id} value={w.id}>
                                        {w.name} ({w.code})
                                    </option>
                                ))}
                            </SearchSelect>
                            <InputError message={errors.warehouse_id} />
                            {movedTo !== null && shortHere.length === 0 && (
                                <p className="text-muted-foreground text-xs">Chosen because {movedTo} holds every input.</p>
                            )}
                            {shortHere.length > 0 && (
                                <p className="text-xs text-amber-700 dark:text-amber-400">
                                    Not enough here: {shortHere.map((line) => productById.get(line.product_id)?.name ?? 'item').join(', ')}.
                                    {holdsAll ? (
                                        <>
                                            {' '}
                                            {holdsAll.name} holds them all.{' '}
                                            <button
                                                type="button"
                                                className="underline"
                                                onClick={() => {
                                                    warehouseTouched.current = true;
                                                    changeWarehouse(String(holdsAll.id));
                                                }}
                                            >
                                                Use {holdsAll.name}
                                            </button>
                                        </>
                                    ) : (
                                        ' No single warehouse holds them all: move stock first, or change the quantities.'
                                    )}
                                </p>
                            )}
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="occurred_at" required>
                                Date of the work
                            </Label>
                            <Input
                                id="occurred_at"
                                aria-required
                                type="date"
                                // MFC-003 (14 Oct 2026): no day that has not come yet.
                                max={dhakaToday()}
                                value={data.occurred_at}
                                onChange={(e) => setData('occurred_at', e.target.value)}
                            />
                            <DateWords value={data.occurred_at} />
                            <InputError message={errors.occurred_at} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="note">Note</Label>
                            <Input id="note" value={data.note} onChange={(e) => setData('note', e.target.value)} placeholder="optional" />
                        </div>
                    </div>

                    {bom && (
                        <div className="rounded-lg border p-3 text-sm">
                            <p className="font-medium">
                                Recipe v{bom.version}
                                {bom.name ? `, ${bom.name}` : ''} is set for this product.
                            </p>
                            <p className="text-muted-foreground mt-1">
                                {recipeEdited && !(autoFill && recipeHolds(bom, data.lines))
                                    ? 'The lines below have been edited, so they are left alone. The run records what you say was consumed; the recipe only saves typing.'
                                    : 'The lines below are filled in from it and scale with the output quantity. Change, remove or add any of them: the run records what you say was consumed.'}
                            </p>
                            {!(Number(data.output_quantity) > 0) && !recipeEdited && (
                                <p className="text-muted-foreground mt-1">{t('production.recipe.waiting')}</p>
                            )}
                            {/* ⭐ PR3: "Can make N, limited by X" from this warehouse's shelf. */}
                            {can && (
                                <div className="mt-2 flex flex-wrap items-center gap-3">
                                    <p className={Number(can.quantity) > 0 ? 'font-medium' : 'font-medium text-amber-700 dark:text-amber-400'}>
                                        {Number(can.quantity) > 0
                                            ? t('production.can_make.here', {
                                                  quantity: formatQuantity(can.quantity),
                                                  unit: outputProduct?.unit ?? '',
                                                  component: can.limited_by ?? '',
                                              })
                                            : t('production.can_make.none', { component: can.limited_by ?? '' })}
                                    </p>
                                    {Number(can.quantity) > 0 && (
                                        <Button type="button" variant="outline" size="sm" onClick={() => setData('output_quantity', can.quantity)}>
                                            {t('production.can_make.button', { quantity: formatQuantity(can.quantity) })}
                                        </Button>
                                    )}
                                </div>
                            )}
                            <div className="mt-2 flex items-center gap-2">
                                <Checkbox id="auto_fill" checked={autoFill} onCheckedChange={(checked) => setAutoFill(checked === true)} />
                                <Label htmlFor="auto_fill" className="font-normal">
                                    {t('production.auto_fill.label')}
                                </Label>
                            </div>
                            {drivenBy && (
                                <p className="text-muted-foreground mt-1">
                                    {t('production.auto_fill.driven', {
                                        component: productById.get(drivenBy.productId)?.name ?? '',
                                        quantity: formatQuantity(drivenBy.quantity),
                                        unit: drivenBy.unit,
                                    })}
                                </p>
                            )}
                            {Number(data.output_quantity) > 0 && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    className="mt-2"
                                    onClick={() => applyRecipe(bom, Number(data.output_quantity))}
                                >
                                    {recipeEdited ? 'Replace my lines with the recipe' : 'Re-apply the recipe'}
                                </Button>
                            )}
                        </div>
                    )}

                    <h2 className="mt-2 font-medium">Inputs consumed</h2>
                    {/* ⭐ Traceability (17 Oct 2026): the shelf and the ledger must take the same tin. */}
                    {/* ⭐ B8 (Joy, 24 Oct 2026; Q8 = B): the person picks every batch; no FEFO proposal. */}
                    <p className="text-muted-foreground -mt-2 text-sm">{t('ips.run.inputs_hint')}</p>
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3">
                                        Product
                                        <span aria-hidden="true" className="text-destructive ml-0.5 font-semibold">
                                            *
                                        </span>
                                    </th>
                                    <th className="w-32 p-3">Available</th>
                                    <th className="w-36 p-3">
                                        Consumed (incl. waste)
                                        <span aria-hidden="true" className="text-destructive ml-0.5 font-semibold">
                                            *
                                        </span>
                                    </th>
                                    <th className="w-24 p-3">{t('units.recipe.unit')}</th>
                                    <th className="w-32 p-3">Of which waste</th>
                                    <th className="w-16 p-3"></th>
                                </tr>
                            </thead>
                            <tbody>
                                {data.lines.map((line, index) => {
                                    const product = productById.get(line.product_id);
                                    const unit = unitOf(line);
                                    const converted = unit !== undefined && product !== undefined && unit.id !== product.stock_unit_id;

                                    return [
                                        <tr key={index} className="border-t align-top">
                                            <td className="p-3">
                                                <SearchSelect
                                                    className={`${selectClass} w-full`}
                                                    value={line.product_id}
                                                    onChange={(e) =>
                                                        setLine(index, {
                                                            product_id: e.target.value,
                                                            unit_id: stockUnitId(e.target.value),
                                                            batches: [],
                                                        })
                                                    }
                                                >
                                                    <option value="">Choose a product</option>
                                                    {products.map((p) => (
                                                        <option key={p.id} value={p.id}>
                                                            {p.name} ({p.sku})
                                                        </option>
                                                    ))}
                                                </SearchSelect>
                                                <InputError message={errors[`lines.${index}.product_id` as keyof typeof errors]} />
                                            </td>
                                            <td className="text-muted-foreground p-3 tabular-nums">
                                                {line.product_id ? `${formatQuantity(available(line.product_id))} ${product?.unit ?? ''}` : '-'}
                                            </td>
                                            <td className="p-3">
                                                <Input
                                                    aria-required
                                                    inputMode="decimal"
                                                    value={line.quantity}
                                                    onChange={(e) => setLineQuantity(index, decimalInput(e.target.value))}
                                                />
                                                {converted && line.quantity !== '' && (
                                                    <p className="text-muted-foreground mt-1 text-xs tabular-nums">
                                                        {t('units.recipe.in_stock', {
                                                            quantity: formatQuantity(stockQuantity(line)),
                                                            unit: product?.unit ?? '',
                                                        })}
                                                    </p>
                                                )}
                                                <InputError message={errors[`lines.${index}.quantity` as keyof typeof errors]} />
                                            </td>
                                            <td className="p-3">
                                                {/* PR3: only units the server can turn into the stock unit. */}
                                                <select
                                                    className={selectClass}
                                                    aria-label={t('units.recipe.unit')}
                                                    title={t('units.recipe.unit_hint')}
                                                    value={unit ? String(unit.id) : ''}
                                                    disabled={!line.product_id || unitsFor(line.product_id).length < 2}
                                                    onChange={(e) => setLineUnit(index, e.target.value)}
                                                >
                                                    {unitsFor(line.product_id).map((u) => (
                                                        <option key={u.id} value={u.id}>
                                                            {u.abbreviation}
                                                        </option>
                                                    ))}
                                                </select>
                                                <InputError message={errors[`lines.${index}.unit_id` as keyof typeof errors]} />
                                            </td>
                                            <td className="p-3">
                                                <Input
                                                    inputMode="decimal"
                                                    value={line.waste}
                                                    onChange={(e) => setLine(index, { waste: decimalInput(e.target.value) })}
                                                    placeholder="0"
                                                />
                                                <InputError message={errors[`lines.${index}.waste` as keyof typeof errors]} />
                                            </td>
                                            <td className="p-3">
                                                {data.lines.length > 1 && (
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => {
                                                            touchLines();
                                                            setData(
                                                                'lines',
                                                                data.lines.filter((_, i) => i !== index),
                                                            );
                                                        }}
                                                    >
                                                        Remove
                                                    </Button>
                                                )}
                                            </td>
                                        </tr>,
                                        <tr key={`${index}-batches`} className="align-top">
                                            <td colSpan={6} className="px-3 pb-3">
                                                <ProductionBatchPicker
                                                    productId={line.product_id}
                                                    warehouseId={data.warehouse_id}
                                                    runDate={data.occurred_at}
                                                    unit={product?.unit ?? ''}
                                                    needed={stockQuantity(line)}
                                                    picks={line.batches}
                                                    onChange={(picks) => setLine(index, { batches: picks })}
                                                    onCost={(paisa) =>
                                                        setPickedCost((current) =>
                                                            current[line.product_id] === paisa ? current : { ...current, [line.product_id]: paisa },
                                                        )
                                                    }
                                                    error={
                                                        (errors[`lines.${index}.batches` as keyof typeof errors] as string | undefined) ??
                                                        (Object.entries(errors).find(([key]) => key.startsWith(`lines.${index}.batches.`))?.[1] as
                                                            | string
                                                            | undefined)
                                                    }
                                                />
                                            </td>
                                        </tr>,
                                    ];
                                })}
                            </tbody>
                        </table>
                    </div>
                    <div>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => {
                                touchLines();
                                setData('lines', [...data.lines, emptyLine()]);
                            }}
                        >
                            Add input line
                        </Button>
                    </div>

                    <h2 className="mt-2 font-medium">Output produced</h2>
                    <div className="grid gap-4 md:grid-cols-5">
                        <div className="grid gap-2 md:col-span-2">
                            <Label htmlFor="output_product_id" required>
                                Product
                            </Label>
                            <SearchSelect
                                id="output_product_id"
                                value={data.output_product_id}
                                onChange={(e) => setData('output_product_id', e.target.value)}
                            >
                                <option value="">Choose the product or in-between item</option>
                                {products.map((p) => (
                                    <option key={p.id} value={p.id}>
                                        {p.name} ({p.sku})
                                    </option>
                                ))}
                            </SearchSelect>
                            <InputError message={errors.output_product_id} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="output_quantity" required>
                                Quantity{data.output_product_id ? ` (${productById.get(data.output_product_id)?.unit ?? ''})` : ''}
                            </Label>
                            <Input
                                id="output_quantity"
                                aria-required
                                inputMode="decimal"
                                value={data.output_quantity}
                                onChange={(e) => setData('output_quantity', decimalInput(e.target.value))}
                            />
                            <InputError message={errors.output_quantity} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="output_expiry_date" required={expiryRequired}>
                                Expiry (EXP)
                            </Label>
                            <Input
                                id="output_expiry_date"
                                aria-required={expiryRequired}
                                type="date"
                                value={data.output_expiry_date}
                                onChange={(e) => setData('output_expiry_date', e.target.value)}
                            />
                            <DateWords value={data.output_expiry_date} />
                            <InputError message={errors.output_expiry_date} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="output_mrp">MRP (label only)</Label>
                            <Input
                                id="output_mrp"
                                inputMode="decimal"
                                value={data.output_mrp}
                                onChange={(e) => setData('output_mrp', decimalInput(e.target.value))}
                                placeholder="optional"
                            />
                            <InputError message={errors.output_mrp} />
                        </div>
                    </div>

                    <div className="flex items-center gap-2">
                        <Checkbox
                            id="expense_waste"
                            checked={data.expense_waste}
                            onCheckedChange={(checked) => setData('expense_waste', checked === true)}
                        />
                        <Label htmlFor="expense_waste" className="font-normal">
                            Expense the waste to the loss account (abnormal events only; normal waste stays in the pack cost)
                        </Label>
                    </div>

                    {/* ⭐ PR3: Tally's "additional cost" on a Manufacturing Journal. */}
                    <h2 className="mt-2 font-medium">{t('production.costs.title')}</h2>
                    <p className="text-muted-foreground -mt-2 text-sm">{t('production.costs.hint')}</p>
                    {data.costs.length > 0 && (
                        <div className="overflow-x-auto rounded-lg border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="w-44 p-3">{t('production.costs.kind')}</th>
                                        <th className="p-3">{t('production.costs.account')}</th>
                                        <th className="w-36 p-3">{t('production.costs.amount')}</th>
                                        <th className="w-56 p-3">{t('production.costs.note')}</th>
                                        <th className="w-16 p-3"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {data.costs.map((cost, index) => (
                                        <tr key={index} className="border-t align-top">
                                            <td className="p-3">
                                                <select
                                                    className={selectClass}
                                                    aria-label={t('production.costs.kind')}
                                                    value={cost.kind}
                                                    onChange={(e) => {
                                                        const kind = costKinds.find((k) => k.value === e.target.value);
                                                        setCost(index, {
                                                            kind: e.target.value,
                                                            // The suggested head only fills an empty choice.
                                                            account_id: cost.account_id || (kind?.account_id ? String(kind.account_id) : ''),
                                                        });
                                                    }}
                                                >
                                                    {costKinds.map((kind) => (
                                                        <option key={kind.value} value={kind.value}>
                                                            {kind.label}
                                                        </option>
                                                    ))}
                                                </select>
                                                <InputError message={formErrors[`costs.${index}.kind`]} />
                                            </td>
                                            <td className="p-3">
                                                <SearchSelect
                                                    className={`${selectClass} w-full`}
                                                    value={cost.account_id}
                                                    onChange={(e) => setCost(index, { account_id: e.target.value })}
                                                >
                                                    <option value="">{t('production.costs.choose_account')}</option>
                                                    {expenseAccounts.map((account) => (
                                                        <option key={account.id} value={account.id}>
                                                            {account.label}
                                                        </option>
                                                    ))}
                                                </SearchSelect>
                                                <InputError message={formErrors[`costs.${index}.account_id`]} />
                                            </td>
                                            <td className="p-3">
                                                <Input
                                                    aria-label={t('production.costs.amount')}
                                                    inputMode="decimal"
                                                    value={cost.amount}
                                                    onChange={(e) => setCost(index, { amount: decimalInput(e.target.value) })}
                                                />
                                                <InputError message={formErrors[`costs.${index}.amount`]} />
                                            </td>
                                            <td className="p-3">
                                                <Input
                                                    aria-label={t('production.costs.note')}
                                                    value={cost.note}
                                                    onChange={(e) => setCost(index, { note: e.target.value })}
                                                    placeholder="optional"
                                                />
                                            </td>
                                            <td className="p-3">
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        setData(
                                                            'costs',
                                                            data.costs.filter((_, i) => i !== index),
                                                        )
                                                    }
                                                >
                                                    {t('production.costs.remove')}
                                                </Button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                    <div>
                        <Button type="button" variant="outline" onClick={() => setData('costs', [...data.costs, emptyCost(costKinds[0])])}>
                            {t('production.costs.add')}
                        </Button>
                    </div>

                    {/* ⭐ PR3: the finished unit cost, live. */}
                    {showCost && (
                        <div className="rounded-lg border p-3 text-sm">
                            <p className="font-medium">{t('production.cost_preview.title')}</p>
                            <p className="mt-1 tabular-nums">
                                {t('production.cost_preview.line', {
                                    materials: taka(materialsPaisa - wastePaisa),
                                    costs: taka(costsPaisa),
                                    total: taka(totalPaisa),
                                    unit_cost: formatMoney(totalPaisa / 100 / outputQuantity),
                                    unit: outputProduct?.unit ?? '',
                                })}
                            </p>
                            {wastePaisa > 0 && (
                                <p className="text-muted-foreground mt-1">{t('production.cost_preview.waste', { waste: taka(wastePaisa) })}</p>
                            )}
                            {short && <p className="mt-1 text-amber-700 dark:text-amber-400">{t('production.cost_preview.short')}</p>}
                            <p className="text-muted-foreground mt-1 text-xs">{t('production.cost_preview.basis')}</p>
                        </div>
                    )}

                    {mode === 'new' && (
                        <div className="flex items-center gap-2">
                            <Checkbox id="confirm" checked={data.confirm} onCheckedChange={(checked) => setData('confirm', checked === true)} />
                            <Label htmlFor="confirm" className="font-normal">
                                Confirm immediately (uncheck to save as a draft)
                            </Label>
                        </div>
                    )}

                    <InputError message={formErrors.run} />
                    <InputError message={formErrors.lines} />
                    {formErrors.repeat && (
                        <div className="flex flex-wrap items-center gap-3 rounded-lg border border-amber-300 p-3 text-sm dark:border-amber-700">
                            <p className="text-amber-800 dark:text-amber-300">{formErrors.repeat}</p>
                            {formErrors.repeat_run_id && (
                                <Link className="underline" href={route('inventory.production-runs.show', formErrors.repeat_run_id)}>
                                    {t('production.repeat.open')}
                                </Link>
                            )}
                            <Button type="button" variant="outline" size="sm" disabled={processing} onClick={() => send(true)}>
                                {t('production.repeat.again')}
                            </Button>
                        </div>
                    )}

                    <div className="flex flex-wrap gap-2">
                        {mode === 'correct' && run && correctionType ? (
                            <ActionDialog
                                action="correct"
                                recordLabel={run.run_number}
                                previewUrl={`/records/${correctionType}/${run.id}/correct/preview`}
                                submitUrl={`/records/${correctionType}/${run.id}/correct`}
                                reasons={correctionReasons}
                                replacement={replacement}
                                redirectTo={`/inventory/production-runs/${run.id}`}
                                trigger={
                                    <Button type="button" size="lg" disabled={!correctable}>
                                        {t('corr.stock.preview_correct')}
                                    </Button>
                                }
                            />
                        ) : (
                            <Button type="submit" disabled={processing} size="lg">
                                {mode === 'edit' ? t('corr.stock.run.save_draft') : data.confirm ? 'Record and confirm run' : 'Save draft'}
                            </Button>
                        )}
                        {run && (
                            <Button type="button" variant="outline" size="lg" asChild>
                                <Link href={`/inventory/production-runs/${run.id}`}>{t('corr.stock.go_back')}</Link>
                            </Button>
                        )}
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
