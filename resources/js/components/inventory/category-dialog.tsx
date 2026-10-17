import { Field, FormRow } from '@/components/form/field';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/lib/i18n';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect } from 'react';

export interface DialogCategory {
    id: number;
    name: string;
    code: string | null;
    // ⭐ Phase 2 / 1B (17 Oct 2026): the market pricing defaults products inherit.
    market_markup_percent?: string | null;
    market_min_margin_percent?: string | null;
    market_rounding_step?: string | null;
}

const EMPTY = { name: '', code: '', market_markup_percent: '', market_min_margin_percent: '', market_rounding_step: '' };

/** "20.0000" as "20": what a person typed. */
const plain = (value: string | null | undefined): string => {
    if (value === null || value === undefined || value === '') return '';
    const number = parseFloat(value);
    return Number.isFinite(number) ? String(number) : value;
};

/**
 * Add a product category or correct one in a popup (14 Oct 2026). The form
 * that sat open above the list is gone. Same fields, same routes, same server
 * rules.
 *
 * ⚠ The SKU code stays optional. Left blank it is stored as NULL and the SKU
 * generator declines to propose until somebody sets it, which is the honest
 * answer; a star on it would push people to invent one.
 */
export function CategoryDialog({
    open,
    onOpenChange,
    category,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    category: DialogCategory | null;
}) {
    const editing = category !== null;
    const { t } = useTranslation();
    const { data, setData, post, put, errors, processing, reset, clearErrors, setDefaults } = useForm(EMPTY);

    // Fresh fields every time it opens: blank for a new category, filled for an edit.
    useEffect(() => {
        if (!open) {
            return;
        }

        const next = category
            ? {
                  name: category.name,
                  code: category.code ?? '',
                  market_markup_percent: plain(category.market_markup_percent),
                  market_min_margin_percent: plain(category.market_min_margin_percent),
                  market_rounding_step: plain(category.market_rounding_step),
              }
            : EMPTY;
        setDefaults(next);
        setData(next);
        clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, category]);

    const close = () => {
        clearErrors();
        reset();
        onOpenChange(false);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (category) {
            put(route('inventory.categories.update', category.id), { preserveScroll: true, onSuccess: close });

            return;
        }

        post(route('inventory.categories.store'), { preserveScroll: true, onSuccess: close });
    };

    return (
        <Dialog open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
            <DialogContent className="sm:max-w-lg" aria-describedby={undefined}>
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>{editing ? `Edit ${category.name}` : 'Add a category'}</DialogTitle>
                    </DialogHeader>

                    <FormRow>
                        <Field label="Name" htmlFor="category-name" size="md" error={errors.name} required>
                            <Input
                                id="category-name"
                                aria-required
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                placeholder="e.g. Bulk Honey"
                            />
                        </Field>
                        <Field label="SKU code" htmlFor="category-code" className="w-40" error={errors.code}>
                            <Input
                                id="category-code"
                                value={data.code}
                                onChange={(e) => setData('code', e.target.value.toUpperCase())}
                                placeholder="e.g. HON"
                                className="font-mono"
                            />
                        </Field>
                    </FormRow>

                    {/* ⭐ Phase 2 / 1B (17 Oct 2026): the market pricing defaults every
                        market-priced product in this category inherits. Definitions in
                        Sales\Support\ProductPricing: markup ON COST, margin ON SELLING price. */}
                    <div className="grid gap-2">
                        <p className="text-[13px] font-medium">{t('market.rules')}</p>
                        <p className="text-muted-foreground text-xs">{t('market.category_rules_hint')}</p>
                        <FormRow>
                            <Field label={t('market.markup')} htmlFor="category-markup" size="sm" error={errors.market_markup_percent}>
                                <Input
                                    id="category-markup"
                                    inputMode="decimal"
                                    value={data.market_markup_percent}
                                    onChange={(e) => setData('market_markup_percent', e.target.value)}
                                    placeholder="e.g. 20"
                                />
                            </Field>
                            <Field label={t('market.min_margin')} htmlFor="category-margin" size="sm" error={errors.market_min_margin_percent}>
                                <Input
                                    id="category-margin"
                                    inputMode="decimal"
                                    value={data.market_min_margin_percent}
                                    onChange={(e) => setData('market_min_margin_percent', e.target.value)}
                                    placeholder="e.g. 25"
                                />
                            </Field>
                            <Field label={t('market.step')} htmlFor="category-step" size="xs" error={errors.market_rounding_step}>
                                <Input
                                    id="category-step"
                                    inputMode="decimal"
                                    value={data.market_rounding_step}
                                    onChange={(e) => setData('market_rounding_step', e.target.value)}
                                    placeholder="10"
                                />
                            </Field>
                        </FormRow>
                        <p className="text-muted-foreground text-xs">{t('market.formula')}</p>
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={close}>
                            Cancel
                        </Button>
                        <Button disabled={processing}>{editing ? 'Save category' : 'Add category'}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
