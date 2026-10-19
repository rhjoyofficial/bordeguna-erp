import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/lib/i18n';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

/**
 * ⭐ A product created from INSIDE the purchase order (purchase redesign PR1,
 * "New item" in the item list). Name, kind, unit and SKU are enough to
 * order it; everything else is filled in later on the product screen.
 *
 * ⚠ JSON to the ordinary product endpoint (ProductController::store answers
 * JSON when asked), never an Inertia visit, so the half-typed order under the
 * dialog survives. Same permission as the products screen
 * (inventory.catalog.manage). The SKU is proposed by the same rule the
 * products screen uses and stays editable.
 */
function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

const selectClass = 'border-input bg-background h-9 rounded-md border px-2 text-sm';

export function ProductQuickCreate({
    open,
    onOpenChange,
    initialName = '',
    units,
    categories,
    onCreated,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    initialName?: string;
    units: { id: number; name: string; abbreviation: string }[];
    categories: { id: number; name: string }[];
    onCreated: (row: { id: number; name: string }) => void;
}) {
    const { t } = useTranslation();
    const { current_business_id } = usePage<SharedData>().props;
    const [values, setValues] = useState({ name: '', type: 'raw_material', unit_of_measure_id: '', category_id: '', sku: '' });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [skuNote, setSkuNote] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (open) {
            setValues({ name: initialName, type: 'raw_material', unit_of_measure_id: units[0] ? String(units[0].id) : '', category_id: '', sku: '' });
            setErrors({});
            setSkuNote(null);
        }
    }, [open, initialName, units]);

    const set = (key: keyof typeof values, value: string) => setValues((all) => ({ ...all, [key]: value }));

    async function proposeSku() {
        const params = new URLSearchParams({ name: values.name, type: values.type });

        if (values.category_id) {
            params.set('category_id', values.category_id);
        }

        try {
            const response = await fetch(`/inventory/products/sku-proposal?${params.toString()}`, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            const body = (await response.json()) as { sku: string | null; reason: string | null };

            if (body.sku) {
                set('sku', body.sku);
                setSkuNote(null);
            } else {
                setSkuNote(body.reason);
            }
        } catch {
            setSkuNote(t('pof.product_sku_type'));
        }
    }

    async function save() {
        setSaving(true);
        setErrors({});

        try {
            const response = await fetch('/inventory/products', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': xsrfToken(),
                    ...(current_business_id ? { 'X-Business-Id': String(current_business_id) } : {}),
                },
                body: JSON.stringify({
                    name: values.name,
                    type: values.type,
                    unit_of_measure_id: values.unit_of_measure_id || null,
                    category_id: values.category_id || null,
                    sku: values.sku,
                }),
            });

            if (response.status === 422) {
                const body = (await response.json()) as { errors: Record<string, string[]> };
                setErrors(Object.fromEntries(Object.entries(body.errors).map(([key, value]) => [key, value[0]])));

                return;
            }

            if (!response.ok) {
                setErrors({ name: response.status === 403 ? t('pof.not_allowed') : t('pof.could_not_save') });

                return;
            }

            onCreated((await response.json()) as { id: number; name: string });
            onOpenChange(false);
        } catch {
            setErrors({ name: t('pof.could_not_save') });
        } finally {
            setSaving(false);
        }
    }

    const ready = values.name.trim() !== '' && values.unit_of_measure_id !== '' && values.sku.trim() !== '';

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{t('pof.new_product')}</DialogTitle>
                </DialogHeader>
                <div className="grid gap-3">
                    <div className="grid gap-1">
                        <Label htmlFor="qp-name" required>
                            {t('pof.product_name')}
                        </Label>
                        <Input id="qp-name" autoFocus value={values.name} onChange={(e) => set('name', e.target.value)} />
                        <InputError message={errors.name} />
                    </div>
                    <div className="flex flex-wrap gap-3">
                        <div className="grid gap-1">
                            <Label htmlFor="qp-type" required>
                                {t('pof.product_kind')}
                            </Label>
                            <select id="qp-type" className={selectClass} value={values.type} onChange={(e) => set('type', e.target.value)}>
                                <option value="raw_material">{t('pof.kind_raw')}</option>
                                <option value="finished_good">{t('pof.kind_finished')}</option>
                                <option value="service">{t('pof.kind_service')}</option>
                            </select>
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="qp-unit" required>
                                {t('pof.unit')}
                            </Label>
                            <select
                                id="qp-unit"
                                className={selectClass}
                                value={values.unit_of_measure_id}
                                onChange={(e) => set('unit_of_measure_id', e.target.value)}
                            >
                                {units.map((unit) => (
                                    <option key={unit.id} value={unit.id}>
                                        {unit.name} ({unit.abbreviation})
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.unit_of_measure_id} />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="qp-category">{t('pof.category')}</Label>
                            <select
                                id="qp-category"
                                className={selectClass}
                                value={values.category_id}
                                onChange={(e) => set('category_id', e.target.value)}
                            >
                                <option value="">-</option>
                                {categories.map((category) => (
                                    <option key={category.id} value={category.id}>
                                        {category.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="qp-sku" required>
                            SKU
                        </Label>
                        <div className="flex gap-2">
                            <Input id="qp-sku" className="w-48" value={values.sku} onChange={(e) => set('sku', e.target.value.toUpperCase())} />
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="h-9"
                                onClick={proposeSku}
                                disabled={values.name.trim() === ''}
                            >
                                {t('pof.suggest_sku')}
                            </Button>
                        </div>
                        {skuNote && <p className="text-muted-foreground text-xs">{skuNote}</p>}
                        <InputError message={errors.sku} />
                    </div>
                </div>
                <DialogFooter>
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                        {t('pof.cancel')}
                    </Button>
                    <Button type="button" disabled={!ready || saving} onClick={save}>
                        {t('pof.save_product')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
