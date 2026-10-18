import { type TranslationKey, useTranslation } from '@/lib/i18n';
import { Link } from '@inertiajs/react';

/**
 * One document as every connected screen receives it (DocumentLinks::ref on
 * the server). `url` is null when the viewer may not open it: the number
 * then shows as plain text, never a link that answers 403.
 */
export interface DocumentRef {
    type: string;
    label: string;
    number: string;
    date: string | null;
    url: string | null;
}

/** The type name in the reader's language, the server's English otherwise. */
export function useDocumentLabel() {
    const { tr } = useTranslation();

    return (doc: Pick<DocumentRef, 'type' | 'label'>) => {
        const key = doc.label === 'Customer advance' ? 'doc.customer_advance' : `doc.${doc.type}`;

        // A voucher carries its own kind (Payment voucher, Journal voucher).
        return doc.type === 'voucher' ? doc.label : tr(key as TranslationKey, doc.label);
    };
}

export function DocumentLink({ doc, className }: { doc: Pick<DocumentRef, 'number' | 'url'>; className?: string }) {
    if (!doc.url) {
        return <span className={className ?? 'font-mono text-xs'}>{doc.number}</span>;
    }

    return (
        <Link href={doc.url} className={className ?? 'font-mono text-xs underline-offset-2 hover:underline'}>
            {doc.number}
        </Link>
    );
}
