import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { usePage } from '@inertiajs/react';
import { useState } from 'react';

/*
 * ⭐ Joy, 25 Oct 2026 (Q11 = A, R87): over the credit limit, the screen blocks
 * the sale and offers Override. A Manager or the Owner chooses THEIR OWN name,
 * types THEIR OWN password and a reason, and the person at the screen presses
 * the same Save button again. The password is re-checked on the server and
 * never kept (the form clears it after every try).
 *
 * Shown by the order form, the invoice form and the counter whenever the
 * server answered with a `credit` refusal.
 */

export type CreditAuthoriser = { id: number; name: string };

export type CreditOverrideFields = {
    override_by: string;
    override_password: string;
    override_reason: string;
};

export const NO_OVERRIDE: CreditOverrideFields = { override_by: '', override_password: '', override_reason: '' };

export function CreditOverridePanel({
    message,
    authorisers,
    value,
    onChange,
    errors,
}: {
    /** The server's sentence: why the sale is blocked. */
    message: string;
    authorisers: CreditAuthoriser[];
    value: CreditOverrideFields;
    onChange: (patch: Partial<CreditOverrideFields>) => void;
    errors: Partial<Record<keyof CreditOverrideFields, string>>;
}) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(value.override_by !== '' || value.override_reason !== '');

    return (
        <div role="alert" className="border-destructive/40 bg-destructive/5 grid w-fit max-w-xl gap-3 rounded-lg border p-3 text-sm">
            <p className="font-medium">{t('salesctl.credit.title')}</p>
            <p>{message}</p>
            {!open ? (
                <Button type="button" variant="outline" size="sm" className="w-fit" onClick={() => setOpen(true)}>
                    {t('salesctl.credit.override')}
                </Button>
            ) : (
                <div className="grid gap-3">
                    <p className="text-muted-foreground text-xs">{t('salesctl.credit.intro')}</p>
                    <div className="grid w-fit gap-1">
                        <Label htmlFor="override_by" required>
                            {t('salesctl.credit.who')}
                        </Label>
                        <select
                            id="override_by"
                            className="border-input bg-background h-9 w-auto rounded-md border px-3 text-sm"
                            value={value.override_by}
                            onChange={(e) => onChange({ override_by: e.target.value })}
                        >
                            <option value="">{t('salesctl.credit.choose')}</option>
                            {authorisers.map((person) => (
                                <option key={person.id} value={String(person.id)}>
                                    {person.name}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.override_by} />
                    </div>
                    <div className="grid w-fit gap-1">
                        <Label htmlFor="override_password" required>
                            {t('salesctl.credit.password')}
                        </Label>
                        <Input
                            id="override_password"
                            type="password"
                            className="w-56"
                            autoComplete="new-password"
                            value={value.override_password}
                            onChange={(e) => onChange({ override_password: e.target.value })}
                            onKeyDown={(e) => {
                                // Enter never submits (owner UI rule).
                                if (e.key === 'Enter') e.preventDefault();
                            }}
                        />
                        <InputError message={errors.override_password} />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="override_reason" required>
                            {t('salesctl.credit.reason')}
                        </Label>
                        <Input
                            id="override_reason"
                            className="w-80 max-w-full"
                            maxLength={500}
                            placeholder={t('salesctl.credit.reason_hint')}
                            value={value.override_reason}
                            onChange={(e) => onChange({ override_reason: e.target.value })}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') e.preventDefault();
                            }}
                        />
                        <InputError message={errors.override_reason} />
                    </div>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="text-muted-foreground w-fit"
                        onClick={() => {
                            onChange(NO_OVERRIDE);
                            setOpen(false);
                        }}
                    >
                        {t('salesctl.credit.cancel')}
                    </Button>
                </div>
            )}
        </div>
    );
}

export type CreditOverrideRow = { id: number; at: string; by: string; entered_by: string | null; reason: string; method: string };

/*
 * The overrides on this record (who, when, why): the order page and the
 * invoice page. Reads `credit_overrides` from the page's props; nothing when
 * there are none.
 */
export function CreditOverridesNote() {
    const { t } = useTranslation();
    const rows = (usePage().props as { credit_overrides?: CreditOverrideRow[] }).credit_overrides ?? [];

    if (rows.length === 0) return null;

    return (
        <div className="grid w-fit max-w-2xl gap-1 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-800 dark:bg-amber-950/30">
            <p className="font-medium">{t('salesctl.credit.overridden')}</p>
            {rows.map((row) => (
                <p key={row.id} className="text-muted-foreground text-xs">
                    {t('salesctl.credit.overridden_by', { name: row.by, date: showDate(row.at), reason: row.reason })}
                </p>
            ))}
        </div>
    );
}
