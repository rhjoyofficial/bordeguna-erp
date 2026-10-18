import { Field } from '@/components/form/field';
import { SearchSelect } from '@/components/search-select';
import { Input } from '@/components/ui/input';
import { type TranslationKey, useTranslation } from '@/lib/i18n';
import { PAYMENT_TERM_OPTIONS, type PaymentTermOption, usesDays } from '@/lib/payment-terms';

/**
 * The terms in words, in the reader's language: "Net 30 days". `fallback` is
 * what shows when there are no structured terms (the old free-text note).
 */
export function useTermsLabel() {
    const { t } = useTranslation();

    return (type: string | null | undefined, days: number | string | null | undefined, fallback: string | null = null): string | null => {
        if (type === 'immediate' || type === 'advance' || type === 'net_days' || type === 'end_of_month') {
            return t(`terms.label.${type}` as TranslationKey, { days: days === null || days === undefined ? 0 : String(days) });
        }

        return fallback;
    };
}

/**
 * ⭐ Ruling P6: the payment-terms pair (kind + days), the same two boxes on the
 * supplier, the purchase order and the bill. `defaultLabel` names what a
 * blank choice means ("Supplier's terms (Net 30 days)"); without it a blank
 * choice reads "Not set".
 */
export function PaymentTermsFields({
    idPrefix,
    type,
    days,
    onType,
    onDays,
    options = PAYMENT_TERM_OPTIONS,
    label,
    defaultLabel,
    typeError,
    daysError,
}: {
    idPrefix: string;
    type: string;
    days: string;
    onType: (value: string) => void;
    onDays: (value: string) => void;
    options?: PaymentTermOption[];
    label?: string;
    defaultLabel?: string;
    typeError?: string;
    daysError?: string;
}) {
    const { t } = useTranslation();

    return (
        <>
            <Field label={label ?? t('terms.title')} htmlFor={`${idPrefix}-terms-type`} error={typeError}>
                <SearchSelect
                    fit
                    id={`${idPrefix}-terms-type`}
                    value={type}
                    onChange={(e) => {
                        onType(e.target.value);

                        if (!usesDays(e.target.value)) {
                            onDays('');
                        }
                    }}
                >
                    <option value="">{defaultLabel ?? t('terms.not_set')}</option>
                    {options.map((option) => (
                        <option key={option.value} value={option.value}>
                            {t(`terms.type.${option.value}` as TranslationKey)}
                        </option>
                    ))}
                </SearchSelect>
            </Field>
            {usesDays(type) && (
                <Field label={t('terms.days')} htmlFor={`${idPrefix}-terms-days`} size="xs" error={daysError} required>
                    <Input
                        id={`${idPrefix}-terms-days`}
                        inputMode="numeric"
                        aria-required
                        value={days}
                        onChange={(e) => onDays(e.target.value.replace(/[^0-9]/g, ''))}
                        placeholder="30"
                    />
                </Field>
            )}
        </>
    );
}
