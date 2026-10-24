import { Field } from '@/components/form/field';
import { SearchableSelect } from '@/components/searchable-select';
import { useTranslation } from '@/lib/i18n';

export interface CommissionAgentOption {
    value: string;
    label: string;
}

/**
 * ⭐ "Sold by / agent" on an order, a counter bill or an invoice (Joy's Q18,
 * 25 Oct 2026). Lists only people with a commission agreement, so it is not
 * drawn at all while nobody has one. The value is "employee:12" and the like;
 * the server checks it again.
 */
export function AgentPicker({
    value,
    onChange,
    options,
    error,
    id = 'commission-agent',
}: {
    value: string;
    onChange: (value: string) => void;
    options: CommissionAgentOption[] | undefined;
    error?: string;
    id?: string;
}) {
    const { t } = useTranslation();

    if (!options || options.length === 0) {
        return null;
    }

    return (
        <Field label={t('commission.agent')} htmlFor={id} size="lg" error={error} hint={t('commission.agent_hint')}>
            <SearchableSelect id={id} value={value} clearable placeholder={t('commission.agent_none')} options={options} onChange={onChange} />
        </Field>
    );
}
