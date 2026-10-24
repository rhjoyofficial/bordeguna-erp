import { MergeDialog } from '@/components/corrections/merge-dialog';
import { CorrectionReasonDialog, type ReasonOption } from '@/components/purchasing/correction-actions';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/lib/i18n';
import { Link, router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

/** What the server says about a customer or supplier itself (PartyRecordActions). */
export interface PartyRecord {
    kind: 'customer' | 'supplier';
    may_manage: boolean;
    is_active: boolean;
    reactivate_url: string;
    reactivate_blocked: string | null;
    delete_url: string;
    delete_blocked: string | null;
    merged_into: { name: string; url: string } | null;
    reasons: ReasonOption[];
    /** The Owner's Merge (dry run first); null for anybody else or a merged record. */
    merge?: { url: string; candidates: { id: number; label: string }[]; reasons: ReasonOption[] } | null;
}

/** A blocked button stays visible, greyed, with its reason (FINAL-PLAN 2). */
function Greyed({ label, reason }: { label: string; reason: string }) {
    return (
        <span className="flex max-w-72 flex-col items-start gap-0.5">
            <Button size="sm" variant="outline" disabled title={reason}>
                {label}
            </Button>
            <span className="text-muted-foreground text-[11px] leading-tight">{reason}</span>
        </span>
    );
}

/**
 * ⭐ B13 (24 Oct 2026; FINAL-PLAN 3.2 / 3.3): Reactivate a switched-off
 * customer or supplier, or Delete one never used to the Recycle bin (the
 * Owner restores it). The Owner also gets Merge, dry run first.
 */
export function PartyRecordActions({ record, name, children }: { record: PartyRecord; name: string; children?: ReactNode }) {
    const { t } = useTranslation();
    const [busy, setBusy] = useState(false);

    if (!record.may_manage && !record.merge && !children) {
        return null;
    }

    return (
        <section className="flex flex-col gap-2 rounded-lg border p-4">
            <h2 className="font-semibold">{t('corr.fu.record_title')}</h2>
            {record.merged_into && (
                <p className="text-sm">
                    {t('corr.fu.merged_into')}{' '}
                    <Link href={record.merged_into.url} className="underline">
                        {record.merged_into.name}
                    </Link>
                </p>
            )}
            <div className="flex flex-wrap items-start gap-2">
                {record.may_manage &&
                    !record.is_active &&
                    (record.reactivate_blocked ? (
                        <Greyed label={t('corr.fu.reactivate')} reason={record.reactivate_blocked} />
                    ) : (
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={busy}
                            onClick={() =>
                                router.post(
                                    record.reactivate_url,
                                    {},
                                    { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) },
                                )
                            }
                        >
                            {t('corr.fu.reactivate')}
                        </Button>
                    ))}
                {record.may_manage &&
                    (record.delete_blocked ? (
                        <Greyed label={t('corr.fu.delete')} reason={record.delete_blocked} />
                    ) : (
                        <CorrectionReasonDialog
                            trigger={
                                <Button size="sm" variant="outline">
                                    {t('corr.fu.delete')}
                                </Button>
                            }
                            title={t('corr.fu.delete_title', { name })}
                            description={t('corr.fu.delete_help')}
                            reasons={record.reasons}
                            submitUrl={record.delete_url}
                            confirmLabel={t('corr.fu.delete')}
                            destructive
                        />
                    ))}
                {record.merge && (
                    <MergeDialog
                        trigger={
                            <Button size="sm" variant="outline">
                                {t('corr.fu.merge')}
                            </Button>
                        }
                        title={t(record.kind === 'customer' ? 'corr.fu.merge_customer_title' : 'corr.fu.merge_supplier_title', { name })}
                        help={t('corr.fu.merge_help', { name })}
                        keepLabel={t('corr.fu.keep_which')}
                        candidates={record.merge.candidates}
                        url={record.merge.url}
                        reasons={record.merge.reasons}
                        confirmLabel={t('corr.fu.merge')}
                    />
                )}
                {children}
            </div>
        </section>
    );
}
