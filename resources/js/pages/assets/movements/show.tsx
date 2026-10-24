import { AssetAction, pick, type AssetAvailability, type ReasonOption } from '@/components/assets/asset-action';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';

interface Movement {
    id: number;
    number: string;
    kind_label: string;
    status: string;
    status_label: string;
    from: string;
    to: string;
    moved_on: string;
    // A send-out's "expected back" (13 Oct 2026); null on every other kind.
    expected_back_on: string | null;
    overdue: boolean;
    created_by: string | null;
    note: string | null;
    received_by: string | null;
    received_at: string | null;
    lines: { item_id: number; tag: string | null; name: string | null; quantity: number; condition: string | null }[];
    photos: { id: number; url: string; name: string }[];
}

/**
 * One hand-over (slice A2), read back. A transfer on its way has the one
 * button that finishes it: somebody at the other place says it arrived.
 */
interface Reversal {
    reverses: { id: number; number: string } | null;
    reversed_by: { id: number; number: string } | null;
    reversed_at: string | null;
    reason: string | null;
    who: string | null;
}

export default function AssetMovementShow({
    movement,
    can_receive,
    actions,
    reasons,
    reversal,
}: {
    movement: Movement;
    can_receive: boolean;
    // ⭐ Corrections wave 3 (24 Oct 2026): Reverse a done hand-over, Cancel a transfer on its way.
    actions: AssetAvailability[];
    reasons: { reversal: ReasonOption[]; cancellation: ReasonOption[] };
    reversal: Reversal;
}) {
    const { t } = useTranslation();
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Things we keep', href: '/assets/items' },
        { title: 'Hand-overs', href: '/assets/movements' },
        { title: movement.number, href: `/assets/movements/${movement.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={movement.number} />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">
                            {movement.number} <span className="font-normal">{movement.kind_label}</span>
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            {movement.from} to {movement.to}, on {showDate(movement.moved_on)}
                            {movement.created_by && `, recorded by ${movement.created_by}`}
                            {movement.expected_back_on && (
                                <span className={movement.overdue ? 'text-destructive font-medium' : ''}>
                                    . Expected back {showDate(movement.expected_back_on)}
                                    {movement.overdue && ' (overdue)'}
                                </span>
                            )}
                        </p>
                        <div className="mt-2">
                            <Badge variant={movement.status === 'dispatched' ? 'destructive' : 'secondary'}>{movement.status_label}</Badge>
                            {movement.received_at && (
                                <span className="text-muted-foreground ml-2 text-xs">
                                    received {showDate(movement.received_at)}
                                    {movement.received_by && ` by ${movement.received_by}`}
                                </span>
                            )}
                        </div>
                    </div>
                    <div className="flex flex-wrap items-start gap-2">
                        <AssetAction
                            availability={pick(actions, 'reverse')}
                            label={t('corr.assets.reverse')}
                            title={t('corr.assets.stays', {
                                action: t('corr.assets.reverse'),
                                number: movement.number,
                                state: t('corr.assets.state_reversed'),
                            })}
                            description={t('corr.assets.reverse_handover_note')}
                            url={`/assets/movements/${movement.id}/reverse`}
                            reasons={reasons.reversal}
                        />
                        <AssetAction
                            availability={pick(actions, 'cancel')}
                            label={t('corr.assets.cancel')}
                            title={t('corr.assets.stays', {
                                action: t('corr.assets.cancel'),
                                number: movement.number,
                                state: t('corr.assets.state_cancelled'),
                            })}
                            description={t('corr.assets.cancel_transfer_note')}
                            url={`/assets/movements/${movement.id}/reverse`}
                            reasons={reasons.cancellation}
                            destructive
                        />
                        {can_receive && (
                            <ConfirmDialog
                                trigger={<Button>It has arrived</Button>}
                                title={`Receive ${movement.number} at ${movement.to}?`}
                                description="Say this only with the things in front of you. From then on the register keeps them at this place."
                                confirmLabel="Yes, received"
                                onConfirm={() => router.post(`/assets/movements/${movement.id}/receive`, {}, { preserveScroll: true })}
                            />
                        )}
                    </div>
                </div>

                {(reversal.reverses || reversal.reversed_at) && (
                    <p className="rounded-lg border border-amber-500/40 bg-amber-500/5 p-3 text-sm">
                        {reversal.reverses && (
                            <Link href={`/assets/movements/${reversal.reverses.id}`} className="underline underline-offset-2">
                                {t('corr.assets.reversal_of', { number: reversal.reverses.number })}
                            </Link>
                        )}
                        {reversal.reversed_by && (
                            <Link href={`/assets/movements/${reversal.reversed_by.id}`} className="underline underline-offset-2">
                                {t('corr.assets.reversed_by', { number: reversal.reversed_by.number })}
                            </Link>
                        )}
                        {reversal.reversed_at && (
                            <span className="text-muted-foreground">
                                {' '}
                                {showDate(reversal.reversed_at)}
                                {reversal.who && `, ${reversal.who}`}
                                {reversal.reason && `: ${reversal.reason}`}
                            </span>
                        )}
                    </p>
                )}

                <section className="grid gap-2">
                    <h2 className="font-medium">Things</h2>
                    <ul className="grid gap-1 text-sm">
                        {movement.lines.map((line) => (
                            <li key={`${line.item_id}-${line.tag}`}>
                                <Link href={`/assets/items/${line.item_id}`} className="font-mono text-xs underline underline-offset-2">
                                    {line.tag}
                                </Link>{' '}
                                {line.name}
                                {line.quantity > 1 && ` x ${line.quantity}`}
                                {line.condition && <span className="text-muted-foreground"> - {line.condition} at hand-over</span>}
                            </li>
                        ))}
                    </ul>
                </section>

                {movement.note && (
                    <section className="grid gap-1">
                        <h2 className="font-medium">Note</h2>
                        <p className="text-sm whitespace-pre-line">{movement.note}</p>
                    </section>
                )}

                {movement.photos.length > 0 && (
                    <section className="grid gap-2">
                        <h2 className="font-medium">Hand-over photos</h2>
                        <div className="flex flex-wrap gap-2">
                            {movement.photos.map((file) => (
                                <a key={file.id} href={file.url} target="_blank" rel="noreferrer">
                                    <img src={file.url} alt={file.name} className="size-28 rounded-md border object-cover" />
                                </a>
                            ))}
                        </div>
                    </section>
                )}
            </div>
        </AppLayout>
    );
}
