import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/lib/i18n';

/** A register loan a voucher or journal line may name (Support\Loans\LoanOptions). */
export interface LoanOption {
    id: number;
    label: string;
    counterparty_id: number;
    account_ids: number[];
    live: boolean;
}

/**
 * "Which loan?" under a loan line's counterparty (loans plan v2, phase 2).
 *
 * Shown only when the chosen counterparty has a register loan on the chosen
 * head. While one of those loans is active the server refuses the line unless
 * it names a loan, so the picker says so; a counterparty with no register loan
 * gets no picker at all and is booked as before.
 */
export function LoanPicker({
    value,
    onChange,
    loans,
    accountId,
    partyId,
    error,
}: {
    value: string;
    onChange: (value: string) => void;
    loans: LoanOption[];
    accountId: string;
    partyId: string;
    error?: string;
}) {
    const { t } = useTranslation();
    const fits = loans.filter((loan) => String(loan.counterparty_id) === partyId && loan.account_ids.includes(Number(accountId)));

    if (partyId === '' || fits.length === 0) {
        return null;
    }

    const required = fits.some((loan) => loan.live);

    return (
        <div className="mt-2 grid gap-1">
            <Label required={required}>{t('loans.picker.label')}</Label>
            <SearchSelect value={value} onChange={(e) => onChange(e.target.value)} invalid={Boolean(error)}>
                <option value="">{required ? t('loans.picker.choose') : t('loans.picker.none')}</option>
                {fits.map((loan) => (
                    <option key={loan.id} value={String(loan.id)}>
                        {loan.label}
                    </option>
                ))}
            </SearchSelect>
            {required && <p className="text-muted-foreground text-xs">{t('loans.picker.hint')}</p>}
            <InputError message={error} />
        </div>
    );
}
