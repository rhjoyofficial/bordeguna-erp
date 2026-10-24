import { Field, FormRow } from '@/components/form/field';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/lib/i18n';
import { proposeSizeSku } from '@/lib/product-family';
import { useForm } from '@inertiajs/react';
import { type FormEventHandler, type ReactNode, useEffect, useState } from 'react';

/**
 * ⭐ Initial plan B22 (Joy, 24 Oct 2026; R10, Q9 = A): the popups that turn a
 * single product into a family and back. "Product can be easily changeable to
 * single variants to multiple variants." Each one changes only which family a
 * product belongs to (and Add a size makes one new product); stock, batches
 * and prices never move. The server checks everything again.
 *
 * ⚠ Enter never submits (owner UI rule): every form stops it in onKeyDown,
 * and only the button sends.
 */
const stopEnter = (e: React.KeyboardEvent) => {
    if (e.key === 'Enter' && (e.target as HTMLElement).tagName !== 'TEXTAREA') {
        e.preventDefault();
    }
};

const TEXTAREA =
    'border-input bg-background/80 focus-visible:ring-ring min-h-16 w-full rounded-md border px-3 py-2 text-sm shadow-xs focus-visible:ring-2 focus-visible:outline-none';

function Popup({
    open,
    onOpenChange,
    title,
    help,
    children,
    onSubmit,
    confirm,
    processing,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    help?: ReactNode;
    children: ReactNode;
    onSubmit: FormEventHandler;
    confirm: string;
    processing: boolean;
}) {
    const { t } = useTranslation();

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-xl">
                <form onSubmit={onSubmit} onKeyDown={stopEnter} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>{title}</DialogTitle>
                        {help && <DialogDescription>{help}</DialogDescription>}
                    </DialogHeader>
                    {children}
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={() => onOpenChange(false)}>
                            {t('ipf.cancel')}
                        </Button>
                        <Button type="submit" disabled={processing} aria-busy={processing || undefined}>
                            {confirm}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** "Make it a family", from a single product's page: this product is the first size. */
export function MakeFamilyButton({ product }: { product: { id: number; name: string; sku: string | null } }) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const blank = { name: product.name, sku: product.sku ?? '', attribute_name: 'Packaging size', value: '', description: '' };
    const { data, setData, post, errors, processing, clearErrors } = useForm(blank);

    useEffect(() => {
        if (open) {
            setData(blank);
            clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    return (
        <>
            <Button size="sm" variant="outline" onClick={() => setOpen(true)}>
                {t('ipf.make')}
            </Button>
            <Popup
                open={open}
                onOpenChange={setOpen}
                title={t('ipf.make_title', { name: product.name })}
                help={t('ipf.make_help', { name: product.name })}
                confirm={t('ipf.make_confirm')}
                processing={processing}
                onSubmit={(e) => {
                    e.preventDefault();
                    post(route('inventory.products.make-family', product.id), { onSuccess: () => setOpen(false) });
                }}
            >
                <FormRow>
                    <Field label={t('ipf.name')} htmlFor="family-name" size="grow" error={errors.name} required>
                        <Input id="family-name" value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="Miniket Rice" />
                    </Field>
                    <Field label={t('ipf.family_sku')} htmlFor="family-sku" size="md" error={errors.sku} required hint={t('ipf.family_sku_hint')}>
                        <Input
                            id="family-sku"
                            className="font-mono"
                            value={data.sku}
                            onChange={(e) => setData('sku', e.target.value.toUpperCase())}
                        />
                    </Field>
                </FormRow>
                <FormRow>
                    <Field
                        label={t('ipf.attribute')}
                        htmlFor="family-attribute"
                        size="md"
                        error={errors.attribute_name}
                        hint={t('ipf.attribute_hint')}
                    >
                        <Input id="family-attribute" value={data.attribute_name} onChange={(e) => setData('attribute_name', e.target.value)} />
                    </Field>
                    <Field label={t('ipf.this_size')} htmlFor="family-value" size="sm" error={errors.value} required>
                        <Input
                            id="family-value"
                            value={data.value}
                            onChange={(e) => setData('value', e.target.value)}
                            placeholder={t('ipf.value_placeholder')}
                        />
                    </Field>
                </FormRow>
                <Field label={t('ipf.description')} htmlFor="family-description" error={errors.description}>
                    <textarea
                        id="family-description"
                        className={TEXTAREA}
                        rows={2}
                        value={data.description}
                        onChange={(e) => setData('description', e.target.value)}
                    />
                </Field>
            </Popup>
        </>
    );
}

export interface JoinableFamily {
    id: number;
    name: string;
    sku: string;
    attribute_name: string;
    sizes: number;
}

/** "Join a family": an existing single product becomes one more size. */
export function JoinFamilyButton({ product, families }: { product: { id: number; name: string }; families: JoinableFamily[] }) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const { data, setData, post, errors, processing, clearErrors, reset } = useForm({ product_family_id: '', value: '' });

    useEffect(() => {
        if (open) {
            reset();
            clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const chosen = families.find((family) => String(family.id) === data.product_family_id);

    return (
        <>
            <Button size="sm" variant="outline" onClick={() => setOpen(true)}>
                {t('ipf.join')}
            </Button>
            <Popup
                open={open}
                onOpenChange={setOpen}
                title={t('ipf.join_title', { name: product.name })}
                help={t('ipf.join_help')}
                confirm={t('ipf.join_confirm')}
                processing={processing}
                onSubmit={(e) => {
                    e.preventDefault();
                    post(route('inventory.products.join-family', product.id), { onSuccess: () => setOpen(false) });
                }}
            >
                <FormRow>
                    <Field label={t('ipf.family')} htmlFor="join-family" size="lg" error={errors.product_family_id} required>
                        <SearchSelect id="join-family" value={data.product_family_id} onChange={(e) => setData('product_family_id', e.target.value)}>
                            <option value="">{t('ipf.choose')}</option>
                            {families.map((family) => (
                                <option key={family.id} value={family.id}>
                                    {`${family.name} (${family.sku}, ${family.sizes === 1 ? t('ipf.one_size') : t('ipf.sizes_count', { count: family.sizes })})`}
                                </option>
                            ))}
                        </SearchSelect>
                    </Field>
                    <Field label={chosen?.attribute_name ?? t('ipf.value')} htmlFor="join-value" size="sm" error={errors.value} required>
                        <Input
                            id="join-value"
                            value={data.value}
                            onChange={(e) => setData('value', e.target.value)}
                            placeholder={t('ipf.value_placeholder')}
                        />
                    </Field>
                </FormRow>
            </Popup>
        </>
    );
}

export interface FamilyForSize {
    id: number;
    name: string;
    sku: string;
    attribute_name: string;
}

/**
 * "Add a size": a new product pre-filled from the family. The SKU follows the
 * size as it is typed (family SKU + size) until somebody types their own; the
 * name likewise ("Miniket Rice 25 kg").
 */
export function AddSizeButton({
    family,
    template,
    units,
}: {
    family: FamilyForSize;
    template: { name: string; unit: string | null; type: string | null } | null;
    units: { id: number; name: string; abbreviation: string }[];
}) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const [ownSku, setOwnSku] = useState(false);
    const [ownName, setOwnName] = useState(false);
    const blank = { value: '', sku: family.sku, name: family.name, mrp: '', unit_of_measure_id: '', type: 'finished_good' };
    const { data, setData, post, errors, processing, clearErrors } = useForm(blank);

    useEffect(() => {
        if (open) {
            setData(blank);
            setOwnSku(false);
            setOwnName(false);
            clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const typeSize = (value: string) =>
        setData((current) => ({
            ...current,
            value,
            sku: ownSku ? current.sku : proposeSizeSku(family.sku, value),
            name: ownName ? current.name : `${family.name} ${value.trim()}`.trim(),
        }));

    return (
        <>
            <Button size="sm" onClick={() => setOpen(true)}>
                {t('ipf.add_size')}
            </Button>
            <Popup
                open={open}
                onOpenChange={setOpen}
                title={t('ipf.add_size_title', { name: family.name })}
                help={template ? t('ipf.add_size_copies', { unit: template.unit ?? '-', template: template.name }) : t('ipf.add_size_empty')}
                confirm={t('ipf.add_size')}
                processing={processing}
                onSubmit={(e) => {
                    e.preventDefault();
                    post(route('inventory.product-families.sizes.store', family.id), { preserveScroll: true, onSuccess: () => setOpen(false) });
                }}
            >
                <FormRow>
                    <Field label={family.attribute_name} htmlFor="size-value" size="sm" error={errors.value} required>
                        <Input
                            id="size-value"
                            value={data.value}
                            onChange={(e) => typeSize(e.target.value)}
                            placeholder={t('ipf.value_placeholder')}
                        />
                    </Field>
                    <Field label={t('ipf.size_sku')} htmlFor="size-sku" size="md" error={errors.sku} required hint={t('ipf.size_sku_hint')}>
                        <Input
                            id="size-sku"
                            className="font-mono"
                            value={data.sku}
                            onChange={(e) => {
                                setOwnSku(true);
                                setData('sku', e.target.value.toUpperCase());
                            }}
                        />
                    </Field>
                </FormRow>
                <FormRow>
                    <Field label={t('ipf.size_name')} htmlFor="size-name" size="grow" error={errors.name} required>
                        <Input
                            id="size-name"
                            value={data.name}
                            onChange={(e) => {
                                setOwnName(true);
                                setData('name', e.target.value);
                            }}
                        />
                    </Field>
                    <Field label={t('ipf.size_mrp')} htmlFor="size-mrp" size="sm" error={errors.mrp}>
                        <Input id="size-mrp" type="number" min="0" step="0.01" value={data.mrp} onChange={(e) => setData('mrp', e.target.value)} />
                    </Field>
                </FormRow>
                {template === null && (
                    <FormRow>
                        <Field label={t('ipf.stock_unit')} htmlFor="size-unit" error={errors.unit_of_measure_id} required>
                            <SearchSelect
                                id="size-unit"
                                value={data.unit_of_measure_id}
                                onChange={(e) => setData('unit_of_measure_id', e.target.value)}
                            >
                                <option value="">{t('ipf.choose')}</option>
                                {units.map((unit) => (
                                    <option key={unit.id} value={unit.id}>
                                        {`${unit.name} (${unit.abbreviation})`}
                                    </option>
                                ))}
                            </SearchSelect>
                        </Field>
                        <Field label={t('ipf.kind')} htmlFor="size-type" error={errors.type} required>
                            <SearchSelect id="size-type" value={data.type} onChange={(e) => setData('type', e.target.value)}>
                                <option value="finished_good">{t('ipf.kind_product')}</option>
                                <option value="raw_material">{t('ipf.kind_raw')}</option>
                                <option value="service">{t('ipf.kind_service')}</option>
                            </SearchSelect>
                        </Field>
                    </FormRow>
                )}
            </Popup>
        </>
    );
}

export interface EditableFamily extends FamilyForSize {
    description: string | null;
    category_id: number | null;
    brand_id: number | null;
}

/** Edit the family itself: its sizes keep their own name, SKU, category and brand. */
export function EditFamilyButton({
    family,
    categories,
    brands,
}: {
    family: EditableFamily;
    categories: { id: number; name: string }[];
    brands: { id: number; name: string }[];
}) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const current = () => ({
        name: family.name,
        sku: family.sku,
        attribute_name: family.attribute_name,
        description: family.description ?? '',
        category_id: family.category_id === null ? '' : String(family.category_id),
        brand_id: family.brand_id === null ? '' : String(family.brand_id),
    });
    const { data, setData, put, errors, processing, clearErrors } = useForm(current());

    useEffect(() => {
        if (open) {
            setData(current());
            clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    return (
        <>
            <Button size="sm" variant="outline" onClick={() => setOpen(true)}>
                {t('ipf.edit')}
            </Button>
            <Popup
                open={open}
                onOpenChange={setOpen}
                title={t('ipf.edit_title', { name: family.name })}
                help={t('ipf.edit_hint')}
                confirm={t('ipf.save')}
                processing={processing}
                onSubmit={(e) => {
                    e.preventDefault();
                    put(route('inventory.product-families.update', family.id), { preserveScroll: true, onSuccess: () => setOpen(false) });
                }}
            >
                <FormRow>
                    <Field label={t('ipf.name')} htmlFor="edit-family-name" size="grow" error={errors.name} required>
                        <Input id="edit-family-name" value={data.name} onChange={(e) => setData('name', e.target.value)} />
                    </Field>
                    <Field label={t('ipf.family_sku')} htmlFor="edit-family-sku" size="md" error={errors.sku} required>
                        <Input
                            id="edit-family-sku"
                            className="font-mono"
                            value={data.sku}
                            onChange={(e) => setData('sku', e.target.value.toUpperCase())}
                        />
                    </Field>
                </FormRow>
                <FormRow>
                    <Field label={t('ipf.category')} htmlFor="edit-family-category" error={errors.category_id}>
                        <SearchSelect id="edit-family-category" value={data.category_id} onChange={(e) => setData('category_id', e.target.value)}>
                            <option value="">{t('ipf.none')}</option>
                            {categories.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name}
                                </option>
                            ))}
                        </SearchSelect>
                    </Field>
                    <Field label={t('ipf.brand')} htmlFor="edit-family-brand" error={errors.brand_id}>
                        <SearchSelect id="edit-family-brand" value={data.brand_id} onChange={(e) => setData('brand_id', e.target.value)}>
                            <option value="">{t('ipf.none')}</option>
                            {brands.map((b) => (
                                <option key={b.id} value={b.id}>
                                    {b.name}
                                </option>
                            ))}
                        </SearchSelect>
                    </Field>
                    <Field label={t('ipf.attribute')} htmlFor="edit-family-attribute" size="md" error={errors.attribute_name}>
                        <Input id="edit-family-attribute" value={data.attribute_name} onChange={(e) => setData('attribute_name', e.target.value)} />
                    </Field>
                </FormRow>
                <Field label={t('ipf.description')} htmlFor="edit-family-description" error={errors.description}>
                    <textarea
                        id="edit-family-description"
                        className={TEXTAREA}
                        rows={2}
                        value={data.description}
                        onChange={(e) => setData('description', e.target.value)}
                    />
                </Field>
            </Popup>
        </>
    );
}
