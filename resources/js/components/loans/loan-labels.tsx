import { StatusPill } from '@/components/ledger-text';
import { Badge } from '@/components/ui/badge';
import { useTranslation, type TranslationKey } from '@/lib/i18n';

/**
 * The Loan Register's words (loans plan v2, phase 2), one place for every page:
 * the server sends values (`given`, `active`, `equal_installments`) and the page
 * says them in English or Bangla from here.
 */
export type LoanDirection = 'given' | 'taken';

/** How the server summarises a loan for a list or a page heading (LoanPresenter::summary). */
export interface LoanSummary {
    id: number;
    number: string;
    direction: LoanDirection;
    status: string;
    counterparty: { id: number; name: string | null; kind: string | null; related: boolean; relationship: string | null };
    principal: string;
    rate: string;
    start_date: string;
    maturity_date: string;
    outstanding: string;
    accrued: string;
    overdue: boolean;
    next_due: { date: string; amount: string } | null;
}

export const DIRECTION_LABEL: Record<LoanDirection, TranslationKey> = {
    given: 'loans.direction.given',
    taken: 'loans.direction.taken',
};

export const STATUS_LABEL: Record<string, TranslationKey> = {
    draft: 'loans.status.draft',
    pending_approval: 'loans.status.pending_approval',
    approved: 'loans.status.approved',
    active: 'loans.status.active',
    settled: 'loans.status.settled',
    written_off: 'loans.status.written_off',
    cancelled: 'loans.status.cancelled',
};

export const REPAYMENT_LABEL: Record<string, TranslationKey> = {
    equal_installments: 'loans.repayment.equal_installments',
    bullet: 'loans.repayment.bullet',
    custom: 'loans.repayment.custom',
};

export const FREQUENCY_LABEL: Record<string, TranslationKey> = {
    monthly: 'loans.frequency.monthly',
    quarterly: 'loans.frequency.quarterly',
    at_maturity: 'loans.frequency.at_maturity',
};

export const DAY_COUNT_LABEL: Record<string, TranslationKey> = {
    actual_365: 'loans.day_count.actual_365',
    actual_360: 'loans.day_count.actual_360',
};

export const WATERFALL_LABEL: Record<string, TranslationKey> = {
    interest_first: 'loans.waterfall.interest_first',
    principal_first: 'loans.waterfall.principal_first',
};

export const ENTRY_LABEL: Record<string, TranslationKey> = {
    disbursement: 'loans.entry.disbursement',
    receipt: 'loans.entry.receipt',
    repayment: 'loans.entry.repayment',
    accrual: 'loans.entry.accrual',
    accrual_adjustment: 'loans.entry.accrual_adjustment',
    write_off: 'loans.entry.write_off',
    waiver: 'loans.entry.waiver',
    manual: 'loans.entry.manual',
};

export const ROW_STATUS_LABEL: Record<string, TranslationKey> = {
    paid: 'loans.row.paid',
    part_paid: 'loans.row.part_paid',
    overdue: 'loans.row.overdue',
    due: 'loans.row.due',
    upcoming: 'loans.row.upcoming',
    closed: 'loans.row.closed',
    superseded: 'loans.row.superseded',
};

export const CHANGE_STATUS_LABEL: Record<string, TranslationKey> = {
    pending: 'loans.change.pending',
    applied: 'loans.change.applied',
    posted: 'loans.change.posted',
    rejected: 'loans.change.rejected',
    reversed: 'loans.change.reversed',
    withdrawn: 'corr.fu.withdrawn',
};

/** The loan's state as a coloured pill, in the reader's language. */
export function LoanStatus({ status }: { status: string }) {
    const { t } = useTranslation();
    const key = STATUS_LABEL[status];

    return <StatusPill status={status === 'active' ? 'posted' : status === 'approved' ? 'neutral' : status} label={key ? t(key) : status} />;
}

/** "Related party", shown wherever a related counterparty is named. */
export function RelatedBadge({ related }: { related: boolean }) {
    const { t } = useTranslation();

    return related ? <Badge variant="secondary">{t('loans.related')}</Badge> : null;
}

/** A fresh key for a money form, so the same form sent twice is booked once. */
export function newSubmissionKey(): string {
    if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) {
        return crypto.randomUUID();
    }

    return `${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

/** "1,234.56" -> 123456 paisa; anything unreadable is 0. */
export function toPaisa(value: string): number {
    const clean = value.replace(/[,\s]/g, '');

    if (!/^-?\d+(\.\d+)?$/.test(clean)) {
        return 0;
    }

    const [whole, fraction = ''] = clean.replace('-', '').split('.');
    const paisa = Number(whole) * 100 + Number((fraction + '00').slice(0, 2));

    return clean.startsWith('-') ? -paisa : paisa;
}

/** 123456 paisa -> "1,234.56". Display only; the server's figures are the record. */
export function formatPaisa(paisa: number): string {
    const negative = paisa < 0;
    const absolute = Math.abs(paisa);
    const whole = Math.floor(absolute / 100).toLocaleString('en-US');
    const fraction = String(absolute % 100).padStart(2, '0');

    return `${negative ? '-' : ''}${whole}.${fraction}`;
}

/** 123456 paisa -> "1234.56", for a form field. */
export function paisaToInput(paisa: number): string {
    return `${Math.floor(Math.abs(paisa) / 100)}.${String(Math.abs(paisa) % 100).padStart(2, '0')}`;
}
