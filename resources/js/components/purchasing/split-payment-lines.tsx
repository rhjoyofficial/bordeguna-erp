import InputError from '@/components/input-error';
import { SearchableSelect } from '@/components/searchable-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/lib/i18n';
import { formatMoney } from '@/lib/utils';
import { Plus, X } from 'lucide-react';

export type SplitLine = { money_account_id: string; amount: string };

export type SplitAccount = { id: number; name: string; balance?: string | null };

/** Money typed in a box, as whole paisa. */
const toPaisa = (value: string): number => Math.round((Number(value) || 0) * 100);

/** Only the rows the server should see: an account and an amount both typed. */
export const filledSplitLines = (rows: SplitLine[]): SplitLine[] => rows.filter((row) => row.money_account_id !== '' && toPaisa(row.amount) > 0);

export const splitPaisa = (rows: SplitLine[]): number => filledSplitLines(rows).reduce((sum, row) => sum + toPaisa(row.amount), 0);

/**
 * ⭐ Initial plan B2 (R57, 25 Oct 2026): "some from bank, some from bKash, the
 * rest stays due". Sits under a screen's own "Paid from" box: that account
 * pays the rest, each row here pays what is typed on it.
 *
 * ⚠ The picker lists whatever accounts the screen was given, so a new account
 * kind (Employee cash) appears here with no change. The server checks every
 * account on its own (balance, permission, active) and is the authority; the
 * "pays the rest" line is only a preview.
 */
export function SplitPaymentLines({
    accounts,
    primaryAccountId,
    rows,
    onChange,
    totalPaisa,
    errors = {},
}: {
    accounts: SplitAccount[];
    primaryAccountId: string;
    rows: SplitLine[];
    onChange: (rows: SplitLine[]) => void;
    /** The cash leaving for this payment, when the screen knows it. */
    totalPaisa: number | null;
    errors?: Record<string, string | undefined>;
}) {
    const { t } = useTranslation();
    const primary = accounts.find((a) => String(a.id) === primaryAccountId);
    const others = splitPaisa(rows);
    const rest = totalPaisa === null ? null : totalPaisa - others;

    const setRow = (index: number, patch: Partial<SplitLine>) => onChange(rows.map((row, i) => (i === index ? { ...row, ...patch } : row)));

    return (
        <div className="flex flex-col gap-2">
            {rows.map((row, index) => {
                const taken = new Set([primaryAccountId, ...rows.filter((_, i) => i !== index).map((r) => r.money_account_id)]);

                return (
                    <div key={index} className="flex flex-wrap items-start gap-2">
                        <div className="grid w-60 gap-1">
                            <SearchableSelect
                                value={row.money_account_id}
                                onChange={(v) => setRow(index, { money_account_id: v })}
                                options={accounts.map((a) => ({
                                    value: String(a.id),
                                    label: a.balance != null ? `${a.name} (${formatMoney(a.balance)})` : a.name,
                                    disabled: taken.has(String(a.id)),
                                }))}
                                placeholder={t('ipp.split.pick')}
                                invalid={Boolean(errors[`split_lines.${index}.money_account_id`])}
                            />
                            <InputError message={errors[`split_lines.${index}.money_account_id`]} />
                        </div>
                        <div className="grid w-36 gap-1">
                            <Input
                                inputMode="decimal"
                                aria-label={t('ipp.split.amount')}
                                className="text-right tabular-nums"
                                value={row.amount}
                                onChange={(e) => setRow(index, { amount: e.target.value })}
                            />
                            <InputError message={errors[`split_lines.${index}.amount`]} />
                        </div>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            aria-label={t('ipp.split.remove')}
                            title={t('ipp.split.remove')}
                            onClick={() => onChange(rows.filter((_, i) => i !== index))}
                        >
                            <X className="size-4" />
                        </Button>
                    </div>
                );
            })}
            <div>
                <Button type="button" variant="outline" size="sm" onClick={() => onChange([...rows, { money_account_id: '', amount: '' }])}>
                    <Plus className="size-4" />
                    {t('ipp.split.add')}
                </Button>
            </div>
            {rows.length === 0 && <p className="text-muted-foreground text-xs">{t('ipp.split.hint')}</p>}
            {rows.length > 0 && rest !== null && rest > 0 && (
                <p className="text-sm">
                    {primary
                        ? t('ipp.split.rest', { account: primary.name, amount: formatMoney(rest / 100) })
                        : t('ipp.split.rest_unnamed', { amount: formatMoney(rest / 100) })}
                </p>
            )}
            {rows.length > 0 && rest !== null && rest <= 0 && others > 0 && (
                <p className="text-destructive text-sm">{t('ipp.split.too_much', { amount: formatMoney(others / 100) })}</p>
            )}
            <InputError message={errors.split_lines} />
        </div>
    );
}
