import { Pagination } from '@/components/data-table/pagination';
import { Chip, CompactTable, Panel, Pill } from '@/components/inventory/inventory-ui';
import { showDate } from '@/lib/dates';
import { type TranslationKey, useTranslation } from '@/lib/i18n';
import { formatMoney } from '@/lib/utils';
import { type Paginated } from '@/types';
import { router } from '@inertiajs/react';
import { DocumentLink, type DocumentRef, useDocumentLabel } from './document-link';

export interface PartyDocumentRow extends DocumentRef {
    key: string;
    id: number;
    amount: string | null;
    status: string | null;
    note: string | null;
    /** ⭐ B23: which side of a firm that is both our supplier and our customer. */
    side?: string;
}

export interface PartyDocuments {
    rows: Paginated<PartyDocumentRow>;
    types: { type: string; label: string; count: number }[];
    type: string;
}

/**
 * ⭐ PR5 (18 Oct 2026): every document a supplier or customer has, newest
 * first, by type, each number opening its own page (Tally's drill-down from
 * a ledger into the voucher). The server pages it (PartyDocuments::page), so
 * a busy customer does not load ten thousand rows.
 */
export function PartyDocumentsPanel({
    documents,
    baseUrl,
    keep = {},
    ownSide,
}: {
    documents: PartyDocuments;
    baseUrl: string;
    /** ⭐ B23: query values kept on every visit (the lifetime window). */
    keep?: Record<string, string>;
    /** ⭐ B23: this page's side; a row of the other side says which it is. */
    ownSide?: string;
}) {
    const { t, tr } = useTranslation();
    const labelOf = useDocumentLabel();
    const { rows, types, type } = documents;
    const total = types.reduce((sum, entry) => sum + entry.count, 0);

    const visit = (params: Record<string, string | number>) =>
        router.get(baseUrl, { ...keep, ...params }, { preserveState: true, preserveScroll: true, only: ['all_documents'] });

    return (
        <Panel title={t('docs.all_title')} flush>
            <p className="text-muted-foreground px-4 pb-2 text-xs">{t('docs.all_intro')}</p>
            {types.length > 1 && (
                <div className="flex flex-wrap gap-1.5 px-4 pb-3">
                    <Chip active={type === ''} count={total} onClick={() => visit({})}>
                        {t('docs.filter_all')}
                    </Chip>
                    {types.map((entry) => (
                        <Chip key={entry.type} active={type === entry.type} count={entry.count} onClick={() => visit({ doc_type: entry.type })}>
                            {labelOf(entry)}
                        </Chip>
                    ))}
                </div>
            )}
            <CompactTable minWidth="44rem">
                <thead>
                    <tr className="text-muted-foreground">
                        <th>{t('docs.col_date')}</th>
                        <th>{t('docs.col_document')}</th>
                        <th>{t('docs.col_number')}</th>
                        <th>{t('docs.col_details')}</th>
                        <th className="text-right">{t('docs.col_amount')}</th>
                    </tr>
                </thead>
                <tbody>
                    {rows.data.map((row) => (
                        <tr key={row.key}>
                            <td className="text-muted-foreground whitespace-nowrap">{showDate(row.date)}</td>
                            <td className="whitespace-nowrap">
                                {labelOf(row)}
                                {row.side && ownSide && row.side !== ownSide && (
                                    <span className="text-muted-foreground block text-xs">{tr(`life.side.${row.side}`, row.side)}</span>
                                )}
                            </td>
                            <td className="whitespace-nowrap">
                                <DocumentLink doc={row} />
                            </td>
                            <td className="text-muted-foreground">
                                {row.status && (
                                    <Pill tone={row.status === 'reversed' || row.status === 'cancelled' ? 'warning' : 'muted'} className="mr-1.5">
                                        {tr(`docs.status.${row.status}` as TranslationKey, row.status.replaceAll('_', ' '))}
                                    </Pill>
                                )}
                                {row.note}
                            </td>
                            <td className="text-right whitespace-nowrap tabular-nums">{row.amount === null ? '' : formatMoney(row.amount)}</td>
                        </tr>
                    ))}
                    {rows.data.length === 0 && (
                        <tr>
                            <td colSpan={5} className="text-muted-foreground p-4 text-center">
                                {t('docs.none')}
                            </td>
                        </tr>
                    )}
                </tbody>
            </CompactTable>
            <Pagination
                className="border-t px-4 py-2"
                meta={rows}
                onChange={(page, perPage) => visit({ ...(type ? { doc_type: type } : {}), docs_page: page, per_page: perPage })}
            />
        </Panel>
    );
}
