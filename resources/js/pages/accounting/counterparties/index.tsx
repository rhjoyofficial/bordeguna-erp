import { COUNTERPARTY_KINDS, CounterpartyDialog, KIND_LABEL, type CounterpartyKind } from '@/components/counterparty-dialog';
import { Amount, DocNo } from '@/components/ledger-text';
import { EmptyRow, FilterBar, ReportHeading, ReportPage, ReportTable } from '@/components/report/report-shell';
import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { useTranslation } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface Balance {
    slug: string;
    label: string;
    amount: string;
    side: 'debit' | 'credit';
}

interface Row {
    id: number;
    legal_name: string;
    kind: CounterpartyKind;
    related_party: boolean;
    tin: string | null;
    phone: string | null;
    is_active: boolean;
    balances: Balance[];
    owed_to_us: string;
    we_owe: string;
}

interface HeadTotal {
    slug: string;
    label: string;
    counterparties_total: string;
    ledger_total: string;
    side: 'debit' | 'credit';
    agrees: boolean;
}

/**
 * Accounting > Counterparties (loans plan v2, phase 1, 14 Oct 2026): every
 * bank, lender and borrower, with what each owes or is owed on each loan head
 * in THIS business, and each head totalled against the ledger underneath.
 */
export default function CounterpartiesIndex({
    filters,
    counterparties,
    heads,
    canManage,
}: {
    filters: { search: string; kind: string; status: string };
    counterparties: Row[];
    heads: HeadTotal[];
    canManage: boolean;
}) {
    const { t } = useTranslation();
    const [form, setForm] = useState(filters);
    const [adding, setAdding] = useState(false);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Accounting', href: '/accounting/journals' },
        { title: t('counterparties.title'), href: '/accounting/counterparties' },
    ];

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        router.get(route('accounting.counterparties.index'), form, { preserveState: true });
    };

    const side = (sideOf: 'debit' | 'credit') => (sideOf === 'credit' ? t('counterparties.cr') : t('counterparties.dr'));
    const filtered = filters.search !== '' || filters.kind !== '' || filters.status !== 'active';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('counterparties.title')} />
            <ReportPage>
                <ReportHeading
                    title={t('counterparties.title')}
                    description={t('counterparties.description')}
                    actions={
                        canManage && (
                            <Button size="sm" onClick={() => setAdding(true)}>
                                <Plus className="size-4" />
                                {t('counterparties.picker.new')}
                            </Button>
                        )
                    }
                />

                <FilterBar onSubmit={submit} label={t('counterparties.show_button')}>
                    <div className="grid gap-2">
                        <Label htmlFor="search">{t('counterparties.search')}</Label>
                        <Input
                            id="search"
                            className="w-64"
                            value={form.search}
                            placeholder={t('counterparties.search_placeholder')}
                            onChange={(e) => setForm({ ...form, search: e.target.value })}
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="kind">{t('counterparties.col.kind')}</Label>
                        <SearchSelect id="kind" value={form.kind} onChange={(e) => setForm({ ...form, kind: e.target.value })}>
                            <option value="">{t('counterparties.all_kinds')}</option>
                            {COUNTERPARTY_KINDS.map((kind) => (
                                <option key={kind} value={kind}>
                                    {t(KIND_LABEL[kind])}
                                </option>
                            ))}
                        </SearchSelect>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="status">{t('counterparties.status')}</Label>
                        <SearchSelect id="status" value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })}>
                            <option value="active">{t('counterparties.status.active')}</option>
                            <option value="inactive">{t('counterparties.status.inactive')}</option>
                            <option value="all">{t('counterparties.status.all')}</option>
                        </SearchSelect>
                    </div>
                </FilterBar>

                <ReportTable
                    minWidth="60rem"
                    head={
                        <>
                            <th className="py-2">{t('counterparties.col.name')}</th>
                            <th className="py-2">{t('counterparties.col.kind')}</th>
                            <th className="py-2">{t('counterparties.col.tin')}</th>
                            <th className="py-2">{t('counterparties.col.phone')}</th>
                            <th className="py-2">{t('counterparties.col.balances')}</th>
                            <th className="py-2 text-right">{t('counterparties.col.owed_to_us')}</th>
                            <th className="py-2 text-right">{t('counterparties.col.we_owe')}</th>
                        </>
                    }
                >
                    {counterparties.map((row) => (
                        <tr key={row.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b align-top">
                            <td className="py-2">
                                <DocNo href={route('accounting.counterparties.show', row.id)} className="font-sans">
                                    {row.legal_name}
                                </DocNo>
                                <div className="mt-1 flex flex-wrap gap-1">
                                    {row.related_party && <Badge variant="secondary">{t('counterparties.related_badge')}</Badge>}
                                    {!row.is_active && <Badge variant="outline">{t('counterparties.off_badge')}</Badge>}
                                </div>
                            </td>
                            <td className="text-muted-foreground py-2">{t(KIND_LABEL[row.kind])}</td>
                            <td className="text-muted-foreground py-2 tabular-nums">{row.tin}</td>
                            <td className="text-muted-foreground py-2 tabular-nums">{row.phone}</td>
                            <td className="py-2">
                                {row.balances.length === 0 ? (
                                    <span className="text-muted-foreground">{t('counterparties.nothing_outstanding')}</span>
                                ) : (
                                    <ul className="grid gap-0.5">
                                        {row.balances.map((balance) => (
                                            <li key={balance.slug} className="flex flex-wrap gap-x-2">
                                                <span className="text-muted-foreground">{balance.label}</span>
                                                <Amount value={`${balance.amount} ${side(balance.side)}`} tone={balance.side} />
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </td>
                            <td className="py-2 text-right">
                                <Amount value={row.owed_to_us} tone="amount" />
                            </td>
                            <td className="py-2 text-right">
                                <Amount value={row.we_owe} tone="amount" />
                            </td>
                        </tr>
                    ))}
                    {counterparties.length === 0 && (
                        <EmptyRow colSpan={7}>{filtered ? t('counterparties.none_match') : t('counterparties.empty')}</EmptyRow>
                    )}
                </ReportTable>

                <section className="grid gap-2">
                    <h2 className="text-base font-semibold">{t('counterparties.heads.title')}</h2>
                    <ReportTable
                        minWidth="40rem"
                        head={
                            <>
                                <th className="py-2">{t('counterparties.heads.col.head')}</th>
                                <th className="py-2 text-right">{t('counterparties.heads.col.parties')}</th>
                                <th className="py-2 text-right">{t('counterparties.heads.col.ledger')}</th>
                                <th className="py-2" />
                            </>
                        }
                    >
                        {heads.map((head) => (
                            <tr key={head.slug} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                <td className="py-2">{head.label}</td>
                                <td className="py-2 text-right">
                                    <Amount value={`${head.counterparties_total} ${side(head.side)}`} tone="amount" />
                                </td>
                                <td className="py-2 text-right">
                                    <Amount value={`${head.ledger_total} ${side(head.side)}`} tone="amount" />
                                </td>
                                <td className="py-2">
                                    {head.agrees ? (
                                        <span className="text-emerald-700 dark:text-emerald-400">{t('counterparties.heads.agrees')}</span>
                                    ) : (
                                        <a href="/accounting/reconciliation" className="text-destructive font-medium underline">
                                            {t('counterparties.heads.differs')}
                                        </a>
                                    )}
                                </td>
                            </tr>
                        ))}
                        {heads.length === 0 && <EmptyRow colSpan={4}>{t('counterparties.heads.empty')}</EmptyRow>}
                    </ReportTable>
                </section>
            </ReportPage>

            {canManage && <CounterpartyDialog open={adding} onOpenChange={setAdding} onSaved={(row) => router.visit(row.url)} />}
        </AppLayout>
    );
}
