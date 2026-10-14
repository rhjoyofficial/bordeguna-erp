import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type TranslationKey, useTranslation } from '@/lib/i18n';
import { useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

/**
 * PUR-003 (owner decision 14 Oct 2026): what the server says about a reversal
 * (Reversible::reversalSummary), or null for a live document.
 */
export interface Reversal {
    on: string | null;
    at: string | null;
    by: string | null;
    reason: string | null;
    journal: string | null;
}

type Kind = 'payment' | 'credit_note' | 'expense';

/**
 * The Reverse form every Purchasing money document carries: a required reason
 * and, if the user wants it, the document's own date instead of today. The
 * server decides whether that date is still open; a refusal comes back under
 * the reason field.
 */
export function ReverseDocumentForm({
    kind,
    action,
    originalDate,
    idPrefix,
    onDone,
}: {
    kind: Kind;
    action: string;
    originalDate: string;
    idPrefix: string;
    onDone?: () => void;
}) {
    const { t } = useTranslation();
    const { data, setData, post, errors, processing } = useForm({ reason: '', on_original_date: false as boolean });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(action, { preserveScroll: true, onSuccess: () => onDone?.() });
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-2 rounded-lg border p-4">
            <h2 className="text-sm font-semibold">{t(`reverse.${kind}.title` as TranslationKey)}</h2>
            <p className="text-muted-foreground text-sm">{t(`reverse.${kind}.explain` as TranslationKey)}</p>
            <Label htmlFor={`${idPrefix}-reason`} required>
                {t('reverse.reason')}
            </Label>
            <Input
                id={`${idPrefix}-reason`}
                aria-required
                value={data.reason}
                onChange={(e) => setData('reason', e.target.value)}
                className="max-w-md"
            />
            <InputError message={errors.reason} />
            <div className="flex max-w-md items-start gap-2">
                <Checkbox
                    id={`${idPrefix}-original-date`}
                    checked={data.on_original_date}
                    onCheckedChange={(checked) => setData('on_original_date', checked === true)}
                />
                <Label htmlFor={`${idPrefix}-original-date`} className="leading-snug font-normal">
                    {t('reverse.on_original_date', { date: originalDate })}
                </Label>
            </div>
            <Button type="submit" variant="outline" disabled={processing} className="w-fit">
                {t('reverse.submit')}
            </Button>
        </form>
    );
}

/** Who reversed it, when and why, with the bills it had settled when there were any. */
export function ReversalNotice({ reversal, released }: { reversal: Reversal; released?: { bill_number: string | null; amount: string }[] | null }) {
    const { t } = useTranslation();

    return (
        <div className="border-destructive/40 flex flex-col gap-1 rounded-lg border p-4 text-sm">
            <Badge variant="destructive" className="w-fit">
                {t('reverse.badge')}
            </Badge>
            <span>{t('reverse.done_by', { date: reversal.on ?? '-', name: reversal.by ?? '-', reason: reversal.reason ?? '-' })}</span>
            {released && released.length > 0 && (
                <span className="text-muted-foreground">
                    {t('reverse.released', { bills: released.map((line) => `${line.bill_number ?? '-'} (${line.amount})`).join(', ') })}
                </span>
            )}
        </div>
    );
}
