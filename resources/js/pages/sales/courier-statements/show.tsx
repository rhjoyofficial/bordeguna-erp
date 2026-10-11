import { ConfirmDialog } from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import { SearchableSelect } from '@/components/searchable-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type TranslationKey, useTranslation } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useMemo, useState } from 'react';

interface Statement {
    id: number;
    courier: string | null;
    provider: string;
    format: string | null;
    statement_reference: string;
    statement_date: string;
    status: 'draft' | 'posted';
    source_filename: string | null;
    has_source: boolean;
    received_into: string | null;
    deposit_amount: string | null;
    received_on: string | null;
    bank_reference: string | null;
    deposit_difference_reason: string | null;
    payable_settled_amount: string | null;
    // Courier hub slice 8 (risk 9): posted after a closed month.
    posted_on: string | null;
    cutoff_note: string | null;
    proof: { name: string; url: string } | null;
}

interface Line {
    id: number;
    row_number: number;
    consignment_number: string;
    merchant_reference: string | null;
    // ours: this business's parcel · elsewhere: another business's · unlisted: nobody's in the ERP
    state: 'ours' | 'elsewhere' | 'unlisted';
    resolution: 'mine' | 'not_mine' | null;
    included: boolean;
    problem: string | null;
    note: string | null;
    suggestion: string | null;
    match_method: 'consignment' | 'merchant_reference' | null;
    erp_consignment: string | null;
    consignment_status: string | null;
    courier_status: string | null;
    hub_weight_grams: number | null;
    collected: string;
    erp_collected: string | null;
    delivery_fee: string;
    discount: string;
    charge: string;
    cod_fee: string;
    additional: string;
    compensation: string;
    adjustment: string;
    payout: string;
}

interface RowError {
    row: number;
    consignment: string | null;
    message: string;
}

interface Summary {
    file_total: string;
    share: string;
    collected: string;
    delivery_fees: string;
    return_fees: string;
    additional_charges: string;
    cod_fees: string;
    compensation: string;
    adjustment: string;
    open_payable: string;
    deposit_expected: boolean;
}

interface JournalLine {
    id: number;
    amount_minor: number;
    memo: string | null;
    account: { id: number; code: string; name: string } | null;
    money_account: { id: number; name: string } | null;
}

type Tab = 'all' | 'matched' | 'differences' | 'unmatched';

// Courier hub slice 8 (risk 4): collections less fees plus compensation and
// adjustments, against what the payout column says.
interface Equation {
    collected: string;
    net_fees: string;
    cod_fees: string;
    additional: string;
    compensation: string;
    adjustment: string;
    expected: string;
    payout: string;
    difference: string;
    balanced: boolean;
}

// A figure cell on the payout lines: a right-aligned column from sm up, a
// labelled row of its own below it.
const stacked =
    'p-3 text-right tabular-nums max-sm:flex max-sm:justify-between max-sm:gap-3 max-sm:py-1 max-sm:before:text-muted-foreground max-sm:before:text-xs max-sm:before:content-[attr(data-label)]';

// A term with its sign, without a "-0.00" or a "+-5.00" (QA walk, 11 Oct 2026).
function signed(sign: '-' | '+', value: string): string {
    const amount = Number(value.replace(/[^0-9.-]/g, ''));
    const bare = value.replace(/^-/, '');

    if (!amount) {
        return bare;
    }

    return (sign === '-') !== amount < 0 ? `-${bare}` : `+${bare}`;
}

function EquationRow({ label, equation }: { label: string; equation: Equation }) {
    const { t } = useTranslation();

    return (
        <tr className="border-t">
            <td className="py-1 pr-3 font-medium">{label}</td>
            <td className="py-1 pr-3 text-right tabular-nums">{equation.collected}</td>
            <td className="py-1 pr-3 text-right tabular-nums">{signed('-', equation.net_fees)}</td>
            <td className="py-1 pr-3 text-right tabular-nums">{signed('-', equation.cod_fees)}</td>
            <td className="py-1 pr-3 text-right tabular-nums">{signed('-', equation.additional)}</td>
            <td className="py-1 pr-3 text-right tabular-nums">{signed('+', equation.compensation)}</td>
            <td className="py-1 pr-3 text-right tabular-nums">{signed('+', equation.adjustment)}</td>
            <td className="py-1 pr-3 text-right font-medium tabular-nums">{equation.expected}</td>
            <td className="py-1 pr-3 text-right tabular-nums">{equation.payout}</td>
            <td className={`py-1 text-right tabular-nums ${equation.balanced ? 'text-muted-foreground' : 'text-destructive font-medium'}`}>
                {equation.balanced ? t('payouts.adds_up') : t('payouts.differs_by', { amount: equation.difference })}
            </td>
        </tr>
    );
}

function EquationPanel({ equations }: { equations: { file: Equation; share: Equation } }) {
    const { t } = useTranslation();

    return (
        <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border p-3 text-sm">
            <p className="text-muted-foreground mb-2">{t('payouts.equation_intro')}</p>
            <table className="w-full text-xs">
                <thead>
                    <tr className="text-muted-foreground text-left">
                        <th className="py-1 pr-3" />
                        <th className="py-1 pr-3 text-right">{t('payouts.col_collected')}</th>
                        <th className="py-1 pr-3 text-right">{t('payouts.col_net_fees')}</th>
                        <th className="py-1 pr-3 text-right">{t('payouts.col_cod_fees')}</th>
                        <th className="py-1 pr-3 text-right">{t('payouts.col_extra')}</th>
                        <th className="py-1 pr-3 text-right">{t('payouts.col_compensation')}</th>
                        <th className="py-1 pr-3 text-right">{t('payouts.col_adjustment')}</th>
                        <th className="py-1 pr-3 text-right">{t('payouts.col_should_pay')}</th>
                        <th className="py-1 pr-3 text-right">{t('payouts.col_payout_column')}</th>
                        <th className="py-1 text-right" />
                    </tr>
                </thead>
                <tbody>
                    <EquationRow label={t('payouts.whole_file')} equation={equations.file} />
                    <EquationRow label={t('payouts.this_business')} equation={equations.share} />
                </tbody>
            </table>
        </div>
    );
}

const taka = (minor: number) => (minor / 100).toLocaleString('en-BD', { minimumFractionDigits: 2 });
const minor = (value: string) => Math.round(parseFloat(value || '0') * 100);
const nonZero = (value: string) => minor(value) !== 0;

const stateLabel = (line: Line, t: (key: TranslationKey) => string) => {
    if (line.state === 'ours') {
        return `${t('payouts.ours')}${line.consignment_status ? `, ${line.consignment_status.toLowerCase()}` : ''}`;
    }
    if (line.state === 'elsewhere') {
        return t('payouts.another_business');
    }
    return line.resolution === 'mine'
        ? t('payouts.booked_by_hand_ours')
        : line.resolution === 'not_mine'
          ? t('payouts.not_ours')
          : t('payouts.not_in_erp');
};

const isDifference = (line: Line) => line.state === 'ours' && (line.problem !== null || line.note !== null);

export default function CourierStatementShow({
    statement,
    lines,
    rowErrors,
    summary,
    problems,
    can_post,
    can_download_source,
    moneyAccounts,
    journal,
    equations,
}: {
    statement: Statement;
    lines: Line[];
    rowErrors: RowError[];
    summary: Summary;
    problems: string[];
    can_post: boolean;
    can_download_source: boolean;
    moneyAccounts: { id: number; name: string }[];
    journal: { journal_number: string; lines: JournalLine[] } | null;
    equations: { file: Equation; share: Equation };
}) {
    const { t } = useTranslation();
    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('payouts.title'), href: '/sales/courier-statements' },
        { title: statement.statement_reference, href: `/sales/courier-statements/${statement.id}` },
    ];
    const isDraft = statement.status === 'draft';
    const pageErrors = usePage().props.errors as Record<string, string | undefined>;
    const [tab, setTab] = useState<Tab>('all');

    const openPayable = minor(summary.open_payable);
    const shareMinor = minor(summary.share);

    const { data, setData, post, errors, processing } = useForm<{
        received_money_account_id: string;
        deposit_amount: string;
        received_on: string;
        settle_payable: string;
        deposit_difference_reason: string;
        bank_reference: string;
        proof: File | null;
    }>({
        received_money_account_id: moneyAccounts.length === 1 ? String(moneyAccounts[0].id) : '',
        deposit_amount: summary.deposit_expected ? summary.file_total : '',
        received_on: new Date().toLocaleDateString('en-CA'),
        settle_payable: '',
        deposit_difference_reason: '',
        bank_reference: '',
        proof: null,
    });

    // What the bank should show once the courier nets an earlier shortfall.
    const expectedMinor = minor(summary.file_total) - minor(data.settle_payable);
    const depositExpected = expectedMinor > 0;
    const differs = depositExpected && data.deposit_amount !== '' && minor(data.deposit_amount) !== expectedMinor;

    const counts = useMemo(
        () => ({
            all: lines.length,
            matched: lines.filter((l) => l.state === 'ours').length,
            differences: lines.filter(isDifference).length,
            unmatched: lines.filter((l) => l.state !== 'ours').length,
        }),
        [lines],
    );

    const shown = lines.filter((line) =>
        tab === 'matched' ? line.state === 'ours' : tab === 'differences' ? isDifference(line) : tab === 'unmatched' ? line.state !== 'ours' : true,
    );

    const resolve = (line: Line, resolution: 'mine' | 'not_mine') =>
        router.post(route('sales.courier-statements.resolve', [statement.id, line.id]), { resolution }, { preserveScroll: true });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('sales.courier-statements.post', statement.id), { preserveScroll: true, forceFormData: true });
    };

    const settleAll = () => {
        const amount = Math.min(openPayable, Math.max(shareMinor, 0));
        setData((current) => ({
            ...current,
            settle_payable: (amount / 100).toFixed(2),
            deposit_amount: ((minor(summary.file_total) - amount) / 100).toFixed(2),
        }));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('payouts.statement_title', { reference: statement.statement_reference })} />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h2 className="text-lg font-medium">
                            {t('payouts.statement_heading', { courier: statement.courier ?? '', reference: statement.statement_reference })}
                        </h2>
                        <p className="text-muted-foreground text-sm">
                            {t('payouts.dated', { date: statement.statement_date })}
                            {statement.source_filename ? t('payouts.read_from', { file: statement.source_filename }) : ''}
                            {statement.format ? ` (${statement.format})` : ''}
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Button variant="outline" size="sm" asChild>
                            <a href={route('sales.courier-statements.workbook', statement.id)}>{t('payouts.exception_workbook')}</a>
                        </Button>
                        {can_download_source && (
                            <Button variant="ghost" size="sm" asChild>
                                <a href={route('sales.courier-statements.source', statement.id)}>{t('payouts.original_file')}</a>
                            </Button>
                        )}
                        <Badge variant={isDraft ? 'secondary' : 'default'}>{isDraft ? t('payouts.draft') : t('payouts.posted')}</Badge>
                    </div>
                </div>

                <div className="grid gap-3 text-sm sm:grid-cols-3">
                    <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-3">
                        <p className="text-muted-foreground">{t('payouts.whole_payout')}</p>
                        <p className="text-lg font-medium tabular-nums">{summary.file_total}</p>
                        <p className="text-muted-foreground text-xs">
                            {t('payouts.parcels_read', { count: lines.length })}
                            {rowErrors.length > 0
                                ? t(rowErrors.length === 1 ? 'payouts.row_not_read' : 'payouts.rows_not_read', { count: rowErrors.length })
                                : ''}
                        </p>
                    </div>
                    <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-3">
                        <p className="text-muted-foreground">{t('payouts.col_share')}</p>
                        <p className="text-lg font-medium tabular-nums">{summary.share}</p>
                        <p className="text-muted-foreground text-xs">
                            {t('payouts.share_breakdown', {
                                collected: summary.collected,
                                delivery: summary.delivery_fees,
                                returns: summary.return_fees,
                                extra: summary.additional_charges,
                                cod: summary.cod_fees,
                            })}
                            {nonZero(summary.compensation) ? t('payouts.plus_compensation', { amount: summary.compensation }) : ''}
                            {nonZero(summary.adjustment) ? t('payouts.plus_adjustments', { amount: summary.adjustment }) : ''}.
                        </p>
                    </div>
                    <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-3">
                        <p className="text-muted-foreground">{t('payouts.money')}</p>
                        {isDraft ? (
                            <>
                                {summary.deposit_expected && <p className="text-xs">{t('payouts.not_in_books_yet')}</p>}
                                {!summary.deposit_expected && (
                                    <p className="text-xs">
                                        {t('payouts.pays_nothing')} {shareMinor < 0 ? t('payouts.shortfall_recorded') : t('payouts.share_stays')}
                                    </p>
                                )}
                                {openPayable > 0 && (
                                    <p className="text-xs">
                                        {t('payouts.books_show_owed', { amount: summary.open_payable, courier: statement.courier ?? '' })}
                                    </p>
                                )}
                            </>
                        ) : statement.received_into ? (
                            <div className="text-sm">
                                <p>
                                    {t('payouts.arrived_in', {
                                        amount: statement.deposit_amount ?? '',
                                        account: statement.received_into,
                                        date: statement.received_on ?? '',
                                    })}
                                    {statement.bank_reference ? t('payouts.reference', { reference: statement.bank_reference }) : ''}
                                </p>
                                {statement.payable_settled_amount && (
                                    <p className="text-muted-foreground text-xs">
                                        {t('payouts.netted_off', { amount: statement.payable_settled_amount })}
                                    </p>
                                )}
                                {statement.deposit_difference_reason && (
                                    <p className="text-muted-foreground text-xs">
                                        {t('payouts.differed_from_file')} {statement.deposit_difference_reason}
                                    </p>
                                )}
                                {statement.proof && (
                                    <a href={statement.proof.url} className="text-xs underline underline-offset-4" target="_blank" rel="noreferrer">
                                        {t('payouts.proof')} {statement.proof.name}
                                    </a>
                                )}
                            </div>
                        ) : (
                            <p className="text-sm">{shareMinor < 0 ? t('payouts.nothing_arrived_shortfall') : t('payouts.nothing_arrived_share')}</p>
                        )}
                    </div>
                </div>

                <EquationPanel equations={equations} />

                {statement.cutoff_note && <p className="text-sm text-amber-700 dark:text-amber-400">{statement.cutoff_note}</p>}

                {isDraft && problems.length > 0 && (
                    <div className="border-destructive/50 bg-destructive/10 rounded-md border p-3 text-sm">
                        <p className="font-medium">{t('payouts.before_post')}</p>
                        <ul className="mt-1 list-disc pl-5">
                            {problems.map((problem) => (
                                <li key={problem}>{problem}</li>
                            ))}
                        </ul>
                    </div>
                )}
                <InputError message={pageErrors.statement} />

                {rowErrors.length > 0 && (
                    <div className="overflow-x-auto rounded-lg border">
                        <div className="flex flex-wrap items-center justify-between gap-2 p-3">
                            <p className="font-medium">{t('payouts.unreadable_rows')}</p>
                            <Button variant="outline" size="sm" asChild>
                                <a href={route('sales.courier-statements.errors', statement.id)}>{t('payouts.download_errors')}</a>
                            </Button>
                        </div>
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3">{t('payouts.col_row')}</th>
                                    <th className="p-3">{t('parcels.col_consignment')}</th>
                                    <th className="p-3">{t('payouts.col_problem')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rowErrors.map((error) => (
                                    <tr key={`${error.row}-${error.consignment}`} className="border-t">
                                        <td className="p-3 tabular-nums">{error.row}</td>
                                        <td className="p-3">{error.consignment ?? '-'}</td>
                                        <td className="text-destructive p-3">{error.message}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                <div className="flex flex-wrap gap-2" role="tablist">
                    {(
                        [
                            ['all', t('payouts.tab_all')],
                            ['matched', t('payouts.tab_matched')],
                            ['differences', t('payouts.tab_differences')],
                            ['unmatched', t('payouts.tab_unmatched')],
                        ] as [Tab, string][]
                    ).map(([key, label]) => (
                        <Button
                            key={key}
                            type="button"
                            role="tab"
                            aria-selected={tab === key}
                            size="sm"
                            variant={tab === key ? 'default' : 'outline'}
                            onClick={() => setTab(key)}
                        >
                            {label} ({counts[key]})
                        </Button>
                    ))}
                </div>

                <div className="overflow-x-auto rounded-lg border">
                    {/* Below sm each line is a card, every figure labelled and Ours / Not ours under it (QA walk, 11 Oct 2026). */}
                    <table className="w-full text-sm max-sm:block">
                        <thead className="bg-muted/50 text-left max-sm:hidden">
                            <tr>
                                <th className="p-3">{t('parcels.col_consignment')}</th>
                                <th className="p-3">{t('payouts.col_whose')}</th>
                                <th className="p-3 text-right">{t('payouts.col_collected')}</th>
                                <th className="p-3 text-right">{t('payouts.col_fee')}</th>
                                <th className="p-3 text-right">{t('payouts.col_net_fee')}</th>
                                <th className="p-3 text-right">{t('payouts.col_cod_fee')}</th>
                                <th className="p-3 text-right">{t('payouts.col_other')}</th>
                                <th className="p-3 text-right">{t('payouts.col_payout')}</th>
                                {isDraft && <th className="p-3"></th>}
                            </tr>
                        </thead>
                        <tbody className="max-sm:block">
                            {shown.map((line) => (
                                <tr
                                    key={line.id}
                                    className={`border-t align-top max-sm:block max-sm:py-2 ${line.included ? '' : 'text-muted-foreground'}`}
                                >
                                    <td className="p-3 max-sm:block max-sm:py-1">
                                        <span className="font-medium">{line.consignment_number}</span>
                                        {line.merchant_reference && (
                                            <span className="text-muted-foreground block text-xs">{line.merchant_reference}</span>
                                        )}
                                        {(line.courier_status || line.hub_weight_grams !== null) && (
                                            <span className="text-muted-foreground block text-xs">
                                                {line.courier_status ?? ''}
                                                {line.courier_status && line.hub_weight_grams !== null ? ', ' : ''}
                                                {line.hub_weight_grams !== null ? t('payouts.hub_weight', { grams: line.hub_weight_grams }) : ''}
                                            </span>
                                        )}
                                        {line.problem && <span className="text-destructive block text-xs">{line.problem}</span>}
                                        {line.note && <span className="block text-xs text-amber-700 dark:text-amber-400">{line.note}</span>}
                                        {line.suggestion && <span className="text-muted-foreground block text-xs italic">{line.suggestion}</span>}
                                    </td>
                                    <td className="p-3 max-sm:block max-sm:py-1">
                                        {stateLabel(line, t)}
                                        {line.match_method === 'merchant_reference' && (
                                            <span className="text-muted-foreground block text-xs">{t('payouts.matched_on_reference')}</span>
                                        )}
                                    </td>
                                    <td className={stacked} data-label={t('payouts.col_collected')}>
                                        {line.collected}
                                        {line.erp_collected !== null && line.erp_collected !== line.collected && (
                                            <span className="text-destructive block text-xs">
                                                {t('payouts.erp')} {line.erp_collected}
                                            </span>
                                        )}
                                    </td>
                                    <td className={stacked} data-label={t('payouts.col_fee')}>
                                        {line.delivery_fee}
                                        {nonZero(line.discount) && (
                                            <span className="text-muted-foreground block text-xs">
                                                {t('payouts.less')} {line.discount}
                                            </span>
                                        )}
                                    </td>
                                    <td className={stacked} data-label={t('payouts.col_net_fee')}>
                                        {line.charge}
                                    </td>
                                    <td className={stacked} data-label={t('payouts.col_cod_fee')}>
                                        {line.cod_fee}
                                    </td>
                                    <td className="p-3 text-right text-xs tabular-nums max-sm:block max-sm:py-0 max-sm:text-left">
                                        {nonZero(line.additional) && (
                                            <span className="block">
                                                {t('payouts.extra')} {line.additional}
                                            </span>
                                        )}
                                        {nonZero(line.compensation) && (
                                            <span className="block">
                                                {t('payouts.compensation')} {line.compensation}
                                            </span>
                                        )}
                                        {nonZero(line.adjustment) && (
                                            <span className="block">
                                                {t('payouts.adjustment')} {line.adjustment}
                                            </span>
                                        )}
                                    </td>
                                    <td className={stacked} data-label={t('payouts.col_payout')}>
                                        {line.payout}
                                    </td>
                                    {isDraft && (
                                        <td className="p-3 text-right whitespace-nowrap max-sm:block max-sm:py-1 max-sm:text-left">
                                            {line.state === 'unlisted' && (
                                                <>
                                                    <Button
                                                        size="sm"
                                                        variant={line.resolution === 'mine' ? 'default' : 'ghost'}
                                                        onClick={() => resolve(line, 'mine')}
                                                    >
                                                        {t('payouts.ours')}
                                                    </Button>
                                                    <Button
                                                        size="sm"
                                                        variant={line.resolution === 'not_mine' ? 'default' : 'ghost'}
                                                        onClick={() => resolve(line, 'not_mine')}
                                                    >
                                                        {t('payouts.not_ours')}
                                                    </Button>
                                                </>
                                            )}
                                        </td>
                                    )}
                                </tr>
                            ))}
                            {shown.length === 0 && (
                                <tr>
                                    <td colSpan={isDraft ? 9 : 8} className="text-muted-foreground p-6 text-center">
                                        {t('payouts.nothing_here')}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                {isDraft && (
                    <form onSubmit={submit} className="border-sidebar-border/70 dark:border-sidebar-border flex flex-col gap-4 rounded-md border p-4">
                        {openPayable > 0 && shareMinor > 0 && (
                            <div className="flex flex-wrap items-end gap-3">
                                <div className="grid gap-1">
                                    <Label>{t('payouts.settle_against')}</Label>
                                    <Input
                                        className="w-36"
                                        value={data.settle_payable}
                                        placeholder="0.00"
                                        onChange={(e) => setData('settle_payable', e.target.value)}
                                    />
                                    <span className="text-muted-foreground text-xs">
                                        {t('payouts.settle_up_to', { amount: taka(Math.min(openPayable, shareMinor)) })}
                                    </span>
                                    <InputError message={errors.settle_payable} />
                                </div>
                                <Button type="button" variant="outline" size="sm" onClick={settleAll}>
                                    {t('payouts.settle_in_full')}
                                </Button>
                            </div>
                        )}

                        {depositExpected && (
                            <div className="flex flex-wrap items-end gap-3">
                                <div className="grid gap-1">
                                    <Label required>{t('payouts.arrived_in_label')}</Label>
                                    <SearchableSelect
                                        value={data.received_money_account_id}
                                        onChange={(v) => setData('received_money_account_id', v)}
                                        options={moneyAccounts.map((a) => ({ value: String(a.id), label: a.name }))}
                                        placeholder={t('payouts.select_account')}
                                        searchPlaceholder={t('payouts.search_accounts')}
                                    />
                                    <InputError message={errors.received_money_account_id} />
                                </div>
                                <div className="grid gap-1">
                                    <Label required>{t('payouts.amount_arrived')}</Label>
                                    <Input className="w-36" value={data.deposit_amount} onChange={(e) => setData('deposit_amount', e.target.value)} />
                                    <span className="text-muted-foreground text-xs">{t('payouts.expected', { amount: taka(expectedMinor) })}</span>
                                    <InputError message={errors.deposit_amount} />
                                </div>
                                <div className="grid gap-1">
                                    <Label required>{t('payouts.on')}</Label>
                                    <Input
                                        type="date"
                                        className="w-40"
                                        value={data.received_on}
                                        onChange={(e) => setData('received_on', e.target.value)}
                                    />
                                    <InputError message={errors.received_on} />
                                </div>
                                <div className="grid gap-1">
                                    <Label>{t('payouts.bank_reference')}</Label>
                                    <Input className="w-44" value={data.bank_reference} onChange={(e) => setData('bank_reference', e.target.value)} />
                                    <InputError message={errors.bank_reference} />
                                </div>
                                <div className="grid gap-1">
                                    <Label>{t('payouts.proof_optional')}</Label>
                                    <Input
                                        type="file"
                                        accept=".pdf,.jpg,.jpeg,.png,.webp"
                                        onChange={(e) => setData('proof', e.target.files?.[0] ?? null)}
                                    />
                                    <InputError message={errors.proof} />
                                </div>
                            </div>
                        )}

                        {differs && (
                            <div className="grid max-w-xl gap-1">
                                <Label required>{t('payouts.why_differs')}</Label>
                                <Input
                                    value={data.deposit_difference_reason}
                                    placeholder={t('payouts.why_differs_example')}
                                    onChange={(e) => setData('deposit_difference_reason', e.target.value)}
                                />
                                <span className="text-muted-foreground text-xs">{t('payouts.differs_help')}</span>
                                <InputError message={errors.deposit_difference_reason} />
                            </div>
                        )}

                        <div className="flex flex-wrap items-center gap-3">
                            <Button disabled={processing || !can_post}>{t('payouts.post_statement')}</Button>
                            <ConfirmDialog
                                trigger={
                                    <Button type="button" variant="ghost">
                                        {t('payouts.discard_draft')}
                                    </Button>
                                }
                                title={t('payouts.discard_title', { reference: statement.statement_reference })}
                                description={t('payouts.discard_description')}
                                confirmLabel={t('payouts.discard')}
                                destructive
                                onConfirm={() => router.delete(route('sales.courier-statements.destroy', statement.id))}
                            />
                        </div>
                    </form>
                )}

                {journal && (
                    <div className="overflow-x-auto rounded-lg border">
                        <p className="p-3 font-medium">{t('payouts.books_effect', { journal: journal.journal_number })}</p>
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3">{t('payouts.col_account')}</th>
                                    <th className="p-3">{t('payouts.col_courier_or_bank')}</th>
                                    <th className="p-3">{t('payouts.col_memo')}</th>
                                    <th className="p-3 text-right">{t('payouts.col_debit')}</th>
                                    <th className="p-3 text-right">{t('payouts.col_credit')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {journal.lines.map((line) => (
                                    <tr key={line.id} className="border-t">
                                        <td className="p-3">
                                            {line.account?.code} {line.account?.name}
                                        </td>
                                        <td className="text-muted-foreground p-3">{line.money_account?.name ?? ''}</td>
                                        <td className="text-muted-foreground p-3">{line.memo}</td>
                                        <td className="p-3 text-right tabular-nums">{line.amount_minor > 0 ? taka(line.amount_minor) : ''}</td>
                                        <td className="p-3 text-right tabular-nums">{line.amount_minor < 0 ? taka(-line.amount_minor) : ''}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
