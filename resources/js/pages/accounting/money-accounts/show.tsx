import { CompactTable, Panel, Pill, Stat } from '@/components/inventory/inventory-ui';
import { type LifetimeRange, LifetimeRangeBar } from '@/components/lifetime/lifetime-ui';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { useTranslation } from '@/lib/i18n';
import { formatMoney } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

interface AccountInfo {
    id: number;
    name: string;
    type_label: string;
    account_number: string | null;
    bank_name: string | null;
    branch: string | null;
    provider: string | null;
    is_active: boolean;
    ledger_account: string | null;
    ledger_url: string | null;
    balance: string;
    balance_is_negative: boolean;
}

interface Lifetime {
    opening: string;
    money_in: string;
    money_out: string;
    closing: string;
    kinds: { label: string; module: string; count: number; money_in: string; money_out: string; net: string }[];
}

/**
 * ⭐ B23 lifetime views (25 Oct 2026; R77): one cash box, bank or wallet.
 * What came in and went out over its whole life (or a window), by the kind of
 * document that moved it, read from its own ledger head; the lines themselves
 * open in the general ledger.
 */
export default function MoneyAccountShow({ account, range, lifetime }: { account: AccountInfo; range: LifetimeRange; lifetime: Lifetime }) {
    const { t } = useTranslation();
    const base = `/accounting/money-accounts/${account.id}`;
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Accounting', href: '/accounting/journals' },
        { title: t('life.ma_back'), href: '/accounting/money-accounts' },
        { title: account.name, href: base },
    ];
    const details = [account.bank_name, account.branch, account.account_number, account.provider].filter(Boolean).join(' · ');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={account.name} />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="flex flex-wrap items-center gap-2 text-xl font-semibold">
                            {account.name}
                            {!account.is_active && <Pill tone="muted">{t('life.inactive')}</Pill>}
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            {account.type_label}
                            {details && ` · ${details}`}
                        </p>
                        {account.ledger_account && (
                            <p className="text-muted-foreground text-xs">
                                {t('life.ma_ledger_head')}: {account.ledger_account}
                            </p>
                        )}
                    </div>
                    {account.ledger_url && (
                        <Button variant="outline" size="sm" asChild>
                            <Link href={account.ledger_url}>{t('life.ma_ledger')}</Link>
                        </Button>
                    )}
                </div>

                <LifetimeRangeBar range={range} baseUrl={base} exportUrl={`${base}/lifetime-export`} />

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    <Stat
                        label={t('life.ma_balance')}
                        value={`Tk ${formatMoney(account.balance)}`}
                        tone={account.balance_is_negative ? 'danger' : 'ok'}
                    />
                    <Stat label={t('life.ma_opening')} value={`Tk ${formatMoney(lifetime.opening)}`} />
                    <Stat label={t('life.ma_in')} value={`Tk ${formatMoney(lifetime.money_in)}`} />
                    <Stat label={t('life.ma_out')} value={`Tk ${formatMoney(lifetime.money_out)}`} />
                    <Stat label={t('life.ma_closing')} value={`Tk ${formatMoney(lifetime.closing)}`} />
                </div>

                <Panel title={t('life.ma_kinds')} flush>
                    <p className="text-muted-foreground px-4 pb-2 text-xs">{account.ledger_account ? t('life.ma_intro') : t('life.ma_no_ledger')}</p>
                    <CompactTable minWidth="40rem">
                        <thead>
                            <tr className="text-muted-foreground">
                                <th>{t('life.col_kind')}</th>
                                <th>{t('life.col_area')}</th>
                                <th className="text-right">{t('life.col_count')}</th>
                                <th className="text-right">{t('life.col_in')}</th>
                                <th className="text-right">{t('life.col_out')}</th>
                                <th className="text-right">{t('life.col_net')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {lifetime.kinds.map((kind) => (
                                <tr key={kind.label}>
                                    <td>{kind.label}</td>
                                    <td className="text-muted-foreground">{kind.module}</td>
                                    <td className="text-right tabular-nums">{kind.count}</td>
                                    <td className="text-right tabular-nums">{formatMoney(kind.money_in)}</td>
                                    <td className="text-right tabular-nums">{formatMoney(kind.money_out)}</td>
                                    <td className="text-right font-medium tabular-nums">{formatMoney(kind.net)}</td>
                                </tr>
                            ))}
                            {lifetime.kinds.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="text-muted-foreground p-4 text-center">
                                        {t('life.none')}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </CompactTable>
                </Panel>
            </div>
        </AppLayout>
    );
}
