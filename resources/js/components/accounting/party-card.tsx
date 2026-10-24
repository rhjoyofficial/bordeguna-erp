import { ConfirmDialog } from '@/components/confirm-dialog';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/lib/i18n';
import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

export interface PartyPosition {
    we_owe: string;
    they_owe: string;
    open_bills: number;
    open_invoices: number;
    /** Our advance still with them (1160), part of the net since 20 Oct 2026. */
    advance: string;
    net: string;
    net_side: 'us' | 'them' | 'even';
    settleable: string;
}

export interface PartyCardProps {
    linked: { supplier_id: number; supplier_name: string; customer_id: number; customer_name: string } | null;
    position: PartyPosition | null;
    can_view: boolean;
    can_link: boolean;
    can_raise: boolean;
    options: { id: number; label: string }[];
    eligible: boolean;
}

/**
 * ⭐ "Also our customer / Also our supplier" (decision D-F, 18 Oct 2026), the
 * same card on the supplier page and the customer page.
 *
 * Linked: the other side, then "We owe them X · They owe us Y · Net Z", the
 * combined statement and, for Finance and the Owner, Raise a set-off. Not
 * linked: link an existing row or create the other side, for a person who
 * may manage both lists; for anybody else the card stays out of the way.
 *
 * ⚠ The two ledgers are never merged: the card only reads them side by side.
 */
export function PartyCard({ party, side, selfId }: { party: PartyCardProps; side: 'supplier' | 'customer'; selfId: number }) {
    const { t } = useTranslation();
    const [other, setOther] = useState('');

    if (party.linked === null && (!party.can_link || !party.eligible)) {
        return null;
    }

    const title = side === 'supplier' ? t('party.card_title_customer') : t('party.card_title_supplier');

    if (party.linked === null) {
        const link = () =>
            router.post(
                route('accounting.parties.link'),
                side === 'supplier' ? { supplier_id: selfId, customer_id: other } : { supplier_id: other, customer_id: selfId },
                { preserveScroll: true },
            );

        const create = () =>
            router.post(
                side === 'supplier'
                    ? route('accounting.parties.customer-from-supplier', selfId)
                    : route('accounting.parties.supplier-from-customer', selfId),
            );

        return (
            <section className="border-sidebar-border/70 dark:border-sidebar-border bg-card flex flex-col gap-3 rounded-xl border p-4">
                <h2 className="text-sm font-semibold">{title}</h2>
                <p className="text-muted-foreground text-sm">
                    {side === 'supplier' ? t('party.not_linked_customer') : t('party.not_linked_supplier')}
                </p>
                <div className="flex flex-wrap items-center gap-2">
                    <SearchSelect fit id="party-other" value={other} onChange={(e) => setOther(e.target.value)}>
                        <option value="">{side === 'supplier' ? t('party.choose_customer') : t('party.choose_supplier')}</option>
                        {party.options.map((option) => (
                            <option key={option.id} value={option.id}>
                                {option.label}
                            </option>
                        ))}
                    </SearchSelect>
                    <Button size="sm" variant="outline" disabled={other === ''} onClick={link}>
                        {t('party.link')}
                    </Button>
                    <ConfirmDialog
                        trigger={
                            <Button size="sm" variant="outline">
                                {side === 'supplier' ? t('party.create_customer') : t('party.create_supplier')}
                            </Button>
                        }
                        title={side === 'supplier' ? t('party.create_customer') : t('party.create_supplier')}
                        description={side === 'supplier' ? t('party.create_confirm_customer') : t('party.create_confirm_supplier')}
                        confirmLabel={side === 'supplier' ? t('party.create_customer') : t('party.create_supplier')}
                        onConfirm={create}
                    />
                </div>
                <p className="text-muted-foreground text-xs">{t('party.separate_ledgers')}</p>
            </section>
        );
    }

    const linked = party.linked;
    const position = party.position;
    const otherHref = side === 'supplier' ? `/sales/customers/${linked.customer_id}` : `/purchasing/suppliers/${linked.supplier_id}`;
    const otherName = side === 'supplier' ? linked.customer_name : linked.supplier_name;
    const net =
        position === null
            ? null
            : position.net_side === 'us'
              ? t('party.net_us', { amount: position.net })
              : position.net_side === 'them'
                ? t('party.net_them', { amount: position.net })
                : t('party.net_even');

    return (
        <section className="border-sidebar-border/70 dark:border-sidebar-border bg-card flex flex-col gap-3 rounded-xl border p-4">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h2 className="text-sm font-semibold">
                    {title}:{' '}
                    <Link href={otherHref} className="underline">
                        {otherName}
                    </Link>
                </h2>
                {party.can_link && (
                    <ConfirmDialog
                        trigger={<button className="text-muted-foreground text-xs underline">{t('party.unlink')}</button>}
                        title={t('party.unlink')}
                        description={t('party.unlink_confirm')}
                        confirmLabel={t('party.unlink')}
                        destructive
                        onConfirm={() => router.post(route('accounting.parties.unlink', linked.customer_id), {}, { preserveScroll: true })}
                    />
                )}
            </div>

            {position !== null && (
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <p className="text-muted-foreground text-xs">{t('party.we_owe')}</p>
                        <p className="text-lg font-semibold tabular-nums">{position.we_owe}</p>
                        <p className="text-muted-foreground text-xs">{t('party.open_bills', { count: position.open_bills })}</p>
                    </div>
                    <div>
                        <p className="text-muted-foreground text-xs">{t('party.they_owe')}</p>
                        <p className="text-lg font-semibold tabular-nums">{position.they_owe}</p>
                        <p className="text-muted-foreground text-xs">{t('party.open_invoices', { count: position.open_invoices })}</p>
                    </div>
                    <div>
                        <p className="text-muted-foreground text-xs">{t('party.advance')}</p>
                        <p className="text-lg font-semibold tabular-nums">{position.advance}</p>
                        <p className="text-muted-foreground text-xs">{t('party.advance_hint')}</p>
                    </div>
                    <div>
                        <p className="text-muted-foreground text-xs">{t('party.net')}</p>
                        <p className="text-lg font-semibold tabular-nums">{net}</p>
                    </div>
                </div>
            )}

            <div className="flex flex-wrap gap-2">
                {party.can_view && (
                    <Button asChild size="sm" variant="outline">
                        <Link href={`/accounting/party-statement?supplier=${linked.supplier_id}`}>{t('party.statement')}</Link>
                    </Button>
                )}
                {party.can_raise && party.can_view && position !== null && position.open_bills > 0 && position.open_invoices > 0 && (
                    <Button asChild size="sm" variant="outline">
                        <Link href={`/accounting/set-offs?supplier=${linked.supplier_id}`}>{t('party.raise_set_off')}</Link>
                    </Button>
                )}
            </div>
            <p className="text-muted-foreground text-xs">{t('party.separate_ledgers')}</p>
        </section>
    );
}
