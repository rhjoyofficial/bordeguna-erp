import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { useTranslation, type TranslationKey } from '@/lib/i18n';

/** The note a loan head carries (Support\CounterpartyRules::guidanceKey on the server). */
export type LoanRule = 'loan' | 'related_only' | 'not_related' | 'owner_loan';

export interface CounterpartyOption {
    id: number;
    label: string;
    /** Marked a related party: only these may sit on 11752, and never on 11751. */
    related: boolean;
}

const HINT: Record<LoanRule, TranslationKey> = {
    loan: 'counterparties.hint.loan',
    related_only: 'counterparties.hint.related_only',
    not_related: 'counterparties.hint.not_related',
    owner_loan: 'counterparties.hint.owner_loan',
};

const NOTE: Record<LoanRule, TranslationKey> = {
    loan: 'counterparties.note.loan',
    related_only: 'counterparties.note.related_only',
    not_related: 'counterparties.note.not_related',
    owner_loan: 'counterparties.note.owner_loan',
};

/**
 * The short line a ledger-head picker shows under a loan head (the ChatGPT
 * confirmation of 14 Oct 2026: internal money is an inter-business transfer,
 * never a loan; 2220 is for a documented owner loan only).
 */
export function useLoanRuleHint(): (rule: LoanRule | null | undefined) => string | null {
    const { t } = useTranslation();

    return (rule) => (rule ? t(HINT[rule]) : null);
}

/**
 * "Which counterparty?" under a loan line, the way 1170 asks "Which employee?"
 * (owner, 14 Oct 2026). On 11752 it lists only related parties and on 11751
 * only the others, the same rule PostJournal enforces, so the list never
 * offers a choice the server will refuse.
 *
 * ⚠ The note under it is the head's rule in full, translated; the server's
 * refusal is still the last word.
 */
export function CounterpartyPicker({
    value,
    onChange,
    options,
    rule,
    error,
}: {
    value: string;
    onChange: (value: string) => void;
    options: CounterpartyOption[];
    rule: LoanRule | null;
    error?: string;
}) {
    const { t } = useTranslation();

    const shown = options.filter((option) => (rule === 'related_only' ? option.related : rule === 'not_related' ? !option.related : true));

    return (
        <div className="mt-2 grid gap-1">
            <SearchSelect value={value} onChange={(e) => onChange(e.target.value)} invalid={Boolean(error)}>
                <option value="">{t('counterparties.picker.which')}</option>
                {shown.map((option) => (
                    <option key={option.id} value={option.id}>
                        {option.related ? t('counterparties.picker.related', { name: option.label }) : option.label}
                    </option>
                ))}
            </SearchSelect>
            <InputError message={error} />
            {rule && <p className="text-muted-foreground text-xs">{t(NOTE[rule])}</p>}
        </div>
    );
}
