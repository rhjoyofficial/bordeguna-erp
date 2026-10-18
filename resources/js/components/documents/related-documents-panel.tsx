import { Panel } from '@/components/inventory/inventory-ui';
import { showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { usePage } from '@inertiajs/react';
import { DocumentLink, type DocumentRef, useDocumentLabel } from './document-link';

export interface RelatedDocuments {
    from: DocumentRef[];
    to: DocumentRef[];
    journals: (DocumentRef & { is_reversal: boolean; source: string | null })[];
}

function DocumentList({ title, docs }: { title: string; docs: DocumentRef[] }) {
    const labelOf = useDocumentLabel();

    if (docs.length === 0) {
        return null;
    }

    return (
        <div>
            <p className="text-muted-foreground mb-1 text-xs font-medium">{title}</p>
            <ul className="space-y-1 text-sm">
                {docs.map((doc) => (
                    <li key={`${doc.type}-${doc.number}-${doc.url ?? ''}`} className="flex flex-wrap items-baseline gap-x-2">
                        <span>{labelOf(doc)}</span>
                        <DocumentLink doc={doc} />
                        <span className="text-muted-foreground text-xs">{showDate(doc.date)}</span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

/**
 * ⭐ PR5 (18 Oct 2026): on every document page, what it came from, what was
 * made from it, and the journal entries under them (Tally's walk from the
 * order to the receipt note, the purchase, the payment and the voucher).
 * Read from the same links the engines write, so two documents never
 * disagree about whether they are connected.
 */
export function RelatedDocumentsPanel({ related: given, className }: { related?: RelatedDocuments | null; className?: string }) {
    const { t } = useTranslation();
    // Every document page's controller sends `related`; reading it here keeps
    // each page to one line.
    const page = usePage<{ related?: RelatedDocuments }>();
    const related = given ?? page.props.related;

    if (!related) {
        return null;
    }

    const empty = related.from.length === 0 && related.to.length === 0 && related.journals.length === 0;

    return (
        <Panel title={t('docs.related_title')} className={className}>
            <p className="text-muted-foreground mt-1 mb-3 text-xs">{t('docs.related_intro')}</p>
            {empty ? (
                <p className="text-muted-foreground text-sm">{t('docs.no_related')}</p>
            ) : (
                <div className="grid gap-4 sm:grid-cols-3">
                    <DocumentList title={t('docs.came_from')} docs={related.from} />
                    <DocumentList title={t('docs.led_to')} docs={related.to} />
                    <DocumentList title={t('docs.journals')} docs={related.journals} />
                </div>
            )}
        </Panel>
    );
}
