import { ConfirmDialog } from '@/components/confirm-dialog';
import { CounterpartyDialog, KIND_LABEL, type CounterpartyFields, type CounterpartyKind, type LinkOptions } from '@/components/counterparty-dialog';
import InputError from '@/components/input-error';
import { Amount } from '@/components/ledger-text';
import { EmptyRow, ReportHeading, ReportPage, ReportTable, StatRow, StatTile } from '@/components/report/report-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

interface Linked {
    id: number;
    label: string;
}

interface Detail {
    id: number;
    legal_name: string;
    kind: CounterpartyKind;
    related_party: boolean;
    relationship: string | null;
    tin: string | null;
    bin: string | null;
    registration_no: string | null;
    phone: string | null;
    email: string | null;
    address: string | null;
    bank_details: string | null;
    notes: string | null;
    is_active: boolean;
    customer_id: number | null;
    supplier_id: number | null;
    employee_id: number | null;
    customer: Linked | null;
    supplier: Linked | null;
    employee: Linked | null;
    created_by: string | null;
    created_at: string | null;
    updated_by: string | null;
    updated_at: string | null;
}

interface Balance {
    slug: string;
    label: string;
    amount: string;
    side: 'debit' | 'credit';
}

/**
 * One counterparty (loans plan v2, phase 1): who it is, what it is in the rest
 * of the ERP, and what it owes or is owed on each loan head in THIS business,
 * with the way to its statement and its balance confirmation letter.
 */
export default function CounterpartyShow({
    counterparty,
    balances,
    totals,
    business,
    canManage,
    linkOptions,
}: {
    counterparty: Detail;
    balances: Balance[];
    totals: { owed_to_us: string; we_owe: string };
    business: string;
    canManage: boolean;
    linkOptions?: LinkOptions;
}) {
    const { t } = useTranslation();
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [editing, setEditing] = useState(false);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Accounting', href: '/accounting/journals' },
        { title: t('counterparties.title'), href: '/accounting/counterparties' },
        { title: counterparty.legal_name, href: route('accounting.counterparties.show', counterparty.id) },
    ];

    const fields: CounterpartyFields = {
        legal_name: counterparty.legal_name,
        kind: counterparty.kind,
        related_party: counterparty.related_party,
        relationship: counterparty.relationship ?? '',
        tin: counterparty.tin ?? '',
        bin: counterparty.bin ?? '',
        registration_no: counterparty.registration_no ?? '',
        phone: counterparty.phone ?? '',
        email: counterparty.email ?? '',
        address: counterparty.address ?? '',
        bank_details: counterparty.bank_details ?? '',
        notes: counterparty.notes ?? '',
        customer_id: counterparty.customer_id ? String(counterparty.customer_id) : '',
        supplier_id: counterparty.supplier_id ? String(counterparty.supplier_id) : '',
        employee_id: counterparty.employee_id ? String(counterparty.employee_id) : '',
    };

    const openEditor = () => {
        setEditing(true);
        // The customer, supplier and employee lists load only when needed.
        router.reload({ only: ['linkOptions'] });
    };

    const detail = (label: ReactNode, value: string | null) => (
        <div className="grid gap-0.5">
            <dt className="text-muted-foreground text-xs">{label}</dt>
            <dd className="text-sm whitespace-pre-line">
                {value ?? <span className="text-muted-foreground">{t('counterparties.show.not_given')}</span>}
            </dd>
        </div>
    );

    const linked = [counterparty.customer, counterparty.supplier, counterparty.employee];
    const linkLabels = [t('counterparties.form.customer'), t('counterparties.form.supplier'), t('counterparties.form.employee')];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={counterparty.legal_name} />
            <ReportPage>
                <ReportHeading
                    title={
                        <span className="flex flex-wrap items-center gap-2">
                            {counterparty.legal_name}
                            <Badge variant="outline">{t(KIND_LABEL[counterparty.kind])}</Badge>
                            {counterparty.related_party && <Badge variant="secondary">{t('counterparties.related_badge')}</Badge>}
                            {!counterparty.is_active && <Badge variant="destructive">{t('counterparties.off_badge')}</Badge>}
                        </span>
                    }
                    actions={
                        <>
                            <Button variant="outline" size="sm" asChild>
                                <Link href={route('accounting.counterparties.statement', counterparty.id)}>{t('counterparties.show.statement')}</Link>
                            </Button>
                            <Button variant="outline" size="sm" asChild>
                                <Link href={route('accounting.counterparties.confirmation', counterparty.id)}>
                                    {t('counterparties.show.confirmation')}
                                </Link>
                            </Button>
                            {canManage && (
                                <Button size="sm" onClick={openEditor}>
                                    {t('counterparties.show.edit')}
                                </Button>
                            )}
                            {canManage &&
                                (counterparty.is_active ? (
                                    <ConfirmDialog
                                        trigger={
                                            <Button variant="ghost" size="sm">
                                                {t('counterparties.show.deactivate')}
                                            </Button>
                                        }
                                        title={t('counterparties.show.deactivate_title', { name: counterparty.legal_name })}
                                        description={t('counterparties.show.deactivate_body')}
                                        confirmLabel={t('counterparties.show.deactivate')}
                                        cancelLabel={t('counterparties.form.cancel')}
                                        destructive
                                        onConfirm={() =>
                                            router.post(route('accounting.counterparties.deactivate', counterparty.id), {}, { preserveScroll: true })
                                        }
                                    />
                                ) : (
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() =>
                                            router.post(route('accounting.counterparties.activate', counterparty.id), {}, { preserveScroll: true })
                                        }
                                    >
                                        {t('counterparties.show.activate')}
                                    </Button>
                                ))}
                        </>
                    }
                />
                <InputError message={errors.counterparty} />

                <StatRow columns={2}>
                    <StatTile label={t('counterparties.col.owed_to_us')} value={totals.owed_to_us} hint={business} />
                    <StatTile label={t('counterparties.col.we_owe')} value={totals.we_owe} hint={business} />
                </StatRow>

                <section className="grid gap-2">
                    <h2 className="text-base font-semibold">{t('counterparties.show.balances', { business })}</h2>
                    <ReportTable
                        minWidth="36rem"
                        head={
                            <>
                                <th className="py-2">{t('counterparties.show.col.head')}</th>
                                <th className="py-2 text-right">{t('counterparties.show.col.balance')}</th>
                                <th className="py-2">{t('counterparties.show.col.meaning')}</th>
                            </>
                        }
                    >
                        {balances.map((balance) => (
                            <tr key={balance.slug} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                <td className="py-2">{balance.label}</td>
                                <td className="py-2 text-right">
                                    <Amount value={balance.amount} tone={balance.side} />
                                </td>
                                <td className="text-muted-foreground py-2">
                                    {balance.side === 'debit'
                                        ? t('counterparties.show.owes_us', { name: counterparty.legal_name })
                                        : t('counterparties.show.we_owe', { name: counterparty.legal_name })}
                                </td>
                            </tr>
                        ))}
                        {balances.length === 0 && <EmptyRow colSpan={3}>{t('counterparties.show.no_balance')}</EmptyRow>}
                    </ReportTable>
                    <p className="text-muted-foreground text-xs">{t('counterparties.show.balances_note')}</p>
                </section>

                <section className="grid gap-3">
                    <h2 className="text-base font-semibold">{t('counterparties.show.details')}</h2>
                    <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {counterparty.related_party && detail(t('counterparties.form.relationship'), counterparty.relationship)}
                        {detail(t('counterparties.form.tin'), counterparty.tin)}
                        {detail(t('counterparties.form.bin'), counterparty.bin)}
                        {detail(t('counterparties.form.registration_no'), counterparty.registration_no)}
                        {detail(t('counterparties.form.phone'), counterparty.phone)}
                        {detail(t('counterparties.form.email'), counterparty.email)}
                        {detail(t('counterparties.form.address'), counterparty.address)}
                        {detail(t('counterparties.form.bank_details'), counterparty.bank_details)}
                        {detail(t('counterparties.form.notes'), counterparty.notes)}
                    </dl>
                    {linked.some((link) => link !== null) && (
                        <div className="grid gap-1">
                            <p className="text-muted-foreground text-xs">{t('counterparties.show.linked')}</p>
                            <ul className="text-sm">
                                {linked.map((link, index) =>
                                    link === null ? null : (
                                        <li key={index}>
                                            {linkLabels[index]}: {link.label}
                                        </li>
                                    ),
                                )}
                            </ul>
                        </div>
                    )}
                    <p className="text-muted-foreground text-xs">
                        {counterparty.created_by && counterparty.created_at
                            ? t('counterparties.show.created', { name: counterparty.created_by, date: showDate(counterparty.created_at) })
                            : null}
                        {counterparty.updated_by && counterparty.updated_at && counterparty.updated_at !== counterparty.created_at
                            ? ` ${t('counterparties.show.updated', { name: counterparty.updated_by, date: showDate(counterparty.updated_at) })}`
                            : null}
                    </p>
                </section>
            </ReportPage>

            {canManage && (
                <CounterpartyDialog
                    open={editing}
                    onOpenChange={setEditing}
                    counterpartyId={counterparty.id}
                    initial={fields}
                    linkOptions={linkOptions ?? null}
                    onSaved={() => router.reload()}
                />
            )}
        </AppLayout>
    );
}
