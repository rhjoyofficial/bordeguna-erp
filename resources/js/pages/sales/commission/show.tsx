import { CorrectionButtons, type AccountsCorrections } from '@/components/accounting/correction-buttons';
import { CommissionAgreementDialog, type AgreementRow, type CommissionOptions } from '@/components/sales/commission-agreement-dialog';
import { PayCommissionDialog, SettleCommissionDialog } from '@/components/sales/commission-money-dialogs';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { formatMoney, formatQuantity } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';

/**
 * ⭐ One commission agreement (25 Oct 2026): its progress, Edit and Settle in
 * popups, and every settlement with its payments. A settlement or a payment
 * is reversed through the corrections engine (CorrectionButtons), kept and
 * marked; its money goes back to "to settle" or "owed".
 */
interface Payment {
    id: number;
    number: string;
    paid_on: string;
    amount: string;
    money_account: string | null;
    reversed: boolean;
    reversal_reason: string | null;
    corrections: AccountsCorrections;
}

interface Settlement {
    id: number;
    number: string;
    settled_on: string;
    from_date: string;
    through_date: string;
    sold_quantity: string;
    sold_value: string;
    amount: string;
    method: string;
    method_label: string;
    money_account: string | null;
    payroll_month: string | null;
    status: string;
    is_posted: boolean;
    owed: string;
    note: string | null;
    reversal_reason: string | null;
    corrections: AccountsCorrections | null;
    payments: Payment[];
}

type Agreement = AgreementRow & { created_by: string | null; decision_note: string | null; last_settled_through: string | null };

export default function CommissionAgreementShow({
    agreement,
    settlements,
    options,
}: {
    agreement: Agreement;
    settlements: Settlement[];
    options: CommissionOptions;
}) {
    const { t } = useTranslation();
    const [editing, setEditing] = useState(false);
    const [settling, setSettling] = useState(false);
    const [paying, setPaying] = useState<Settlement | null>(null);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Sales', href: '/sales/overview' },
        { title: 'Commission agreements', href: '/sales/commission-agreements' },
        { title: agreement.number, href: `/sales/commission-agreements/${agreement.id}` },
    ];

    const progress = agreement.progress;
    const howLabel = (s: Settlement) =>
        s.method === 'paid_now'
            ? `${s.method_label}: ${s.money_account ?? ''}`
            : s.method === 'payroll'
              ? `${s.method_label}: ${s.payroll_month ?? ''}`
              : s.method_label;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={agreement.number} />
            <div className="space-y-4 p-4">
                <Link href="/sales/commission-agreements" className="text-muted-foreground text-sm hover:underline">
                    {t('commission.back')}
                </Link>

                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="space-y-1">
                        <h1 className="text-lg font-semibold">
                            {agreement.number}: {agreement.party_name}
                        </h1>
                        <p className="text-sm">{agreement.deal_summary}</p>
                        <p className="text-muted-foreground text-sm">
                            {showDate(agreement.valid_from)} {t('commission.to')}{' '}
                            {agreement.valid_to ? showDate(agreement.valid_to) : t('commission.open_end')}
                            {' . '}
                            {agreement.products.length + agreement.categories.length === 0
                                ? t('commission.every_product')
                                : [...agreement.products.map((p) => p.name), ...agreement.categories.map((c) => c.name)].join(', ')}
                        </p>
                        <p className="text-muted-foreground text-xs">
                            {agreement.status_label}
                            {agreement.created_by ? ` . ${t('commission.made_by', { name: agreement.created_by })}` : ''}
                        </p>
                        {agreement.note && <p className="text-sm">{agreement.note}</p>}
                    </div>
                    <div className="flex gap-2">
                        {(agreement.status === 'active' || agreement.status === 'pending') && (
                            <Button variant="outline" size="sm" onClick={() => setEditing(true)}>
                                {t('commission.edit')}
                            </Button>
                        )}
                        {agreement.status === 'active' && (
                            <Button size="sm" onClick={() => setSettling(true)}>
                                {t('commission.settle')}
                            </Button>
                        )}
                    </div>
                </div>

                {progress && (
                    <div className="flex flex-wrap gap-6 rounded-lg border p-3 text-sm">
                        <div>
                            <div className="text-muted-foreground text-xs">{t('commission.sold')}</div>
                            <div>
                                {t('commission.units', { qty: formatQuantity(progress.sold_quantity) })}, Tk {formatMoney(progress.sold_value)}
                            </div>
                        </div>
                        <div>
                            <div className="text-muted-foreground text-xs">{t('commission.earned')}</div>
                            <div>Tk {formatMoney(progress.earned)}</div>
                        </div>
                        <div>
                            <div className="text-muted-foreground text-xs">{t('commission.settled')}</div>
                            <div>Tk {formatMoney(progress.settled)}</div>
                        </div>
                        <div>
                            <div className="text-muted-foreground text-xs">{t('commission.to_settle')}</div>
                            <div className="font-semibold">Tk {formatMoney(progress.to_settle)}</div>
                        </div>
                        {progress.target_percent !== null && (
                            <div>
                                <div className="text-muted-foreground text-xs">
                                    {t('commission.target_progress', { percent: progress.target_percent })}
                                </div>
                                <div className="bg-muted mt-1 h-2 w-40 overflow-hidden rounded">
                                    <div className="bg-primary h-2" style={{ width: `${Math.min(progress.target_percent, 100)}%` }} />
                                </div>
                                {progress.next_gap !== null && progress.next_reward !== null && (
                                    <div className="text-muted-foreground mt-1 text-xs">
                                        {t('commission.next_step', {
                                            gap: formatQuantity(progress.next_gap),
                                            reward: formatMoney(progress.next_reward),
                                        })}
                                    </div>
                                )}
                            </div>
                        )}
                    </div>
                )}

                <h2 className="text-sm font-semibold">{t('commission.settlements')}</h2>
                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="bg-muted/50 text-left">
                                <th className="px-3 py-2 font-medium">{t('commission.number')}</th>
                                <th className="px-3 py-2 font-medium">{t('commission.settled_on')}</th>
                                <th className="px-3 py-2 font-medium">{t('commission.sales_window')}</th>
                                <th className="px-3 py-2 text-right font-medium">{t('commission.amount')}</th>
                                <th className="px-3 py-2 font-medium">{t('commission.how')}</th>
                                <th className="px-3 py-2 text-right font-medium">{t('commission.owed')}</th>
                                <th className="px-3 py-2 font-medium">{t('commission.status')}</th>
                                <th className="px-3 py-2" />
                            </tr>
                        </thead>
                        <tbody>
                            {settlements.length === 0 ? (
                                <tr>
                                    <td colSpan={8} className="text-muted-foreground px-3 py-4 text-center">
                                        {t('commission.no_settlements')}
                                    </td>
                                </tr>
                            ) : (
                                settlements.map((s) => <SettlementRows key={s.id} s={s} how={howLabel(s)} onPay={() => setPaying(s)} />)
                            )}
                        </tbody>
                    </table>
                </div>

                <CommissionAgreementDialog
                    open={editing}
                    onOpenChange={setEditing}
                    options={options}
                    agreement={agreement}
                    settledLock={agreement.last_settled_through !== null}
                />
                <SettleCommissionDialog
                    open={settling}
                    onOpenChange={setSettling}
                    agreementId={agreement.id}
                    number={agreement.number}
                    partyType={agreement.party_type}
                    toSettle={progress?.to_settle ?? null}
                    moneyAccounts={options.moneyAccounts}
                />
                {paying && (
                    <PayCommissionDialog
                        open={paying !== null}
                        onOpenChange={(open) => !open && setPaying(null)}
                        settlementId={paying.id}
                        number={paying.number}
                        owed={paying.owed}
                        moneyAccounts={options.moneyAccounts}
                    />
                )}
            </div>
        </AppLayout>
    );
}

function SettlementRows({ s, how, onPay }: { s: Settlement; how: string; onPay: () => void }) {
    const { t } = useTranslation();
    const owes = s.is_posted && s.method === 'payable' && Number(s.owed) > 0;

    return (
        <>
            <tr id={`settlement-${s.id}`} className="border-t align-top">
                <td className="px-3 py-2 font-medium">{s.number}</td>
                <td className="px-3 py-2 whitespace-nowrap">{showDate(s.settled_on)}</td>
                <td className="px-3 py-2 whitespace-nowrap">
                    {showDate(s.from_date)} {t('commission.to')} {showDate(s.through_date)}
                    <div className="text-muted-foreground text-xs">
                        {t('commission.units', { qty: formatQuantity(s.sold_quantity) })}, Tk {formatMoney(s.sold_value)}
                    </div>
                </td>
                <td className="px-3 py-2 text-right">{formatMoney(s.amount)}</td>
                <td className="px-3 py-2">{how}</td>
                <td className="px-3 py-2 text-right">{s.method === 'payable' && s.is_posted ? formatMoney(s.owed) : '-'}</td>
                <td className="px-3 py-2">
                    {s.status}
                    {s.reversal_reason && <div className="text-muted-foreground text-xs">{s.reversal_reason}</div>}
                </td>
                <td className="px-3 py-2">
                    <div className="flex flex-wrap justify-end gap-2">
                        {owes && (
                            <Button size="sm" variant="outline" onClick={onPay}>
                                {t('commission.pay')}
                            </Button>
                        )}
                        {s.corrections && <CorrectionButtons corrections={s.corrections} recordId={s.id} recordLabel={s.number} />}
                    </div>
                </td>
            </tr>
            {s.payments.map((p) => (
                <tr key={`p${p.id}`} id={`payment-${p.id}`} className="bg-muted/20 text-xs">
                    <td className="px-3 py-1 pl-6">{p.number}</td>
                    <td className="px-3 py-1">{showDate(p.paid_on)}</td>
                    <td className="px-3 py-1">{p.money_account}</td>
                    <td className="px-3 py-1 text-right">{formatMoney(p.amount)}</td>
                    <td className="px-3 py-1">{t('commission.paid')}</td>
                    <td />
                    <td className="px-3 py-1">
                        {p.reversed ? t('commission.reversed') : ''}
                        {p.reversal_reason && <div className="text-muted-foreground">{p.reversal_reason}</div>}
                    </td>
                    <td className="px-3 py-1">
                        <div className="flex justify-end">
                            {!p.reversed && <CorrectionButtons corrections={p.corrections} recordId={p.id} recordLabel={p.number} />}
                        </div>
                    </td>
                </tr>
            ))}
        </>
    );
}
