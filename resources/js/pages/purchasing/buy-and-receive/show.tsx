import { RelatedDocumentsPanel } from '@/components/documents/related-documents-panel';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { formatMoney } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';

interface DocumentRow {
    kind: string;
    label: string;
    number: string;
    state?: string;
    url: string;
}

interface Purchase {
    id: number;
    status: string;
    held_reason: string | null;
    purchased_on: string | null;
    supplier: string | null;
    supplier_id: number;
    created_by: string | null;
    goods: string;
    extra_costs: string;
    paid: string;
}

/**
 * ⭐ The result of one Buy and receive (ruling P4, 18 Oct 2026): every
 * document it made, each a link to its own screen with its own number. What
 * waits for approval is said in a sentence.
 */
export default function BuyAndReceiveShow({ purchase, documents }: { purchase: Purchase; documents: DocumentRow[] }) {
    const { t } = useTranslation();
    const { canQuickPurchase } = usePage<SharedData>().props;
    const completed = purchase.status === 'completed';

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Purchases', href: '/purchasing/overview' },
        { title: 'Buy and receive', href: '/purchasing/buy-and-receive/new' },
        { title: `#${purchase.id}`, href: `/purchasing/buy-and-receive/${purchase.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('quick.result')} />
            <div className="flex max-w-3xl flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center gap-3">
                    <h2 className="text-lg font-semibold">{t('quick.result')}</h2>
                    <Badge variant={completed ? 'default' : 'secondary'}>{completed ? t('quick.status.completed') : t('quick.status.waiting')}</Badge>
                </div>
                <p className="text-muted-foreground text-sm">
                    {purchase.supplier}, {showDate(purchase.purchased_on)}
                    {purchase.created_by && `, ${purchase.created_by}`}. Goods {formatMoney(purchase.goods)}
                    {Number(purchase.extra_costs) > 0 && `, extra costs ${formatMoney(purchase.extra_costs)}`}
                    {Number(purchase.paid) > 0 && `, paid ${formatMoney(purchase.paid)}`}.
                </p>
                {purchase.held_reason && (
                    <p role="status" className="rounded-lg border border-amber-500/50 p-3 text-sm">
                        {purchase.held_reason}
                    </p>
                )}

                <section className="flex flex-col gap-2">
                    <h3 className="text-sm font-medium">{t('quick.documents')}</h3>
                    <ul className="divide-y rounded-lg border text-sm">
                        {documents.map((doc) => (
                            <li key={doc.kind} className="flex flex-wrap items-center justify-between gap-2 p-3">
                                <span className="text-muted-foreground">{doc.label}</span>
                                <span className="flex items-center gap-3">
                                    {doc.state && <span className="text-muted-foreground tabular-nums">{doc.state}</span>}
                                    <Link href={doc.url} className="font-medium hover:underline">
                                        {doc.number}
                                    </Link>
                                </span>
                            </li>
                        ))}
                    </ul>
                </section>

                {canQuickPurchase && (
                    <Button asChild variant="outline" className="w-fit">
                        <Link href="/purchasing/buy-and-receive/new">{t('quick.again')}</Link>
                    </Button>
                )}
                {/* PR5 (18 Oct 2026): came from, led to, journals. */}
                <RelatedDocumentsPanel />
            </div>
        </AppLayout>
    );
}
