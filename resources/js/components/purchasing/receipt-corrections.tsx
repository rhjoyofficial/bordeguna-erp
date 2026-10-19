import { ActionDialog } from '@/components/action-dialog';
import { BlockedAction, CorrectionReasonDialog, type ActionSetProps, type ReasonOption } from '@/components/purchasing/correction-actions';
import { RecordHistory } from '@/components/record-history';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/lib/i18n';
import { Link } from '@inertiajs/react';
import { useState } from 'react';

/**
 * ⭐ Corrections wave 1 (19 Oct 2026): a goods receipt's buttons from
 * GoodsReceiptActionPolicy: Fix batch details (same goods, label typed wrong),
 * Correct (its own page, pre-filled, previewed in ActionDialog) and Reverse
 * (ActionDialog), plus the History and the correction chain.
 */
export type ReceiptCorrection = {
    actions: ActionSetProps;
    reasons: Record<string, ReasonOption[]>;
    type: string;
    reversed: { on: string | null; by: string | null; reason: string | null } | null;
    corrected_by: { id: number; label: string } | null;
    correction_of: { id: number; label: string } | null;
    details_fixed_at: string | null;
};

type BatchLine = {
    id: number;
    product: string | null;
    batch: { batch_number: string | null; manufactured_date: string | null; expiry_date: string | null } | null;
};

export function ReceiptCorrections({
    receipt,
    lines,
    correction,
}: {
    receipt: { id: number; supplier_challan_number: string | null; note: string | null };
    lines: BatchLine[];
    correction: ReceiptCorrection;
}) {
    const { t } = useTranslation();
    const label = t('receipts.receipt_no', { id: receipt.id });
    const batched = lines.filter((line) => line.batch !== null);
    const [challan, setChallan] = useState(receipt.supplier_challan_number ?? '');
    const [note, setNote] = useState(receipt.note ?? '');
    const [labels, setLabels] = useState(() =>
        batched.map((line) => ({
            id: line.id,
            batch_number: line.batch?.batch_number ?? '',
            manufactured_date: line.batch?.manufactured_date ?? '',
            expiry_date: line.batch?.expiry_date ?? '',
        })),
    );
    const setLabel = (index: number, field: 'batch_number' | 'manufactured_date' | 'expiry_date', value: string) =>
        setLabels((all) => all.map((row, i) => (i === index ? { ...row, [field]: value } : row)));

    const buttons = correction.actions.actions.map((availability) => {
        const text =
            { fix_batch_details: t('corr.grn.fix_batch'), correct: t('corr.grn.correct'), reverse: t('corr.grn.reverse') }[availability.action] ??
            availability.label;

        if (!availability.allowed && !availability.requestable) {
            return <BlockedAction key={availability.action} availability={availability} label={text} />;
        }

        switch (availability.action) {
            case 'fix_batch_details':
                return (
                    <CorrectionReasonDialog
                        key="fix"
                        trigger={
                            <Button size="sm" variant="outline">
                                {text}
                            </Button>
                        }
                        title={t('corr.grn.fix_title', { label })}
                        description={t('corr.grn.fix_text')}
                        reasons={correction.reasons.fix_details ?? []}
                        submitUrl={`/purchasing/receipts/${receipt.id}/fix-batch-details`}
                        confirmLabel={text}
                        data={() => ({ supplier_challan_number: challan, note, lines: labels })}
                    >
                        {labels.map((row, index) => (
                            <div key={row.id} className="grid gap-2 rounded-md border p-2.5">
                                <p className="font-medium">{batched[index]?.product}</p>
                                {/* Dates first (Joy, 19 Oct 2026). */}
                                <div className="flex flex-wrap gap-3">
                                    <div className="grid gap-1">
                                        <Label htmlFor={`fix-mfg-${row.id}`}>{t('corr.grn.mfg')}</Label>
                                        <Input
                                            id={`fix-mfg-${row.id}`}
                                            type="date"
                                            className="w-40"
                                            value={row.manufactured_date}
                                            onChange={(e) => setLabel(index, 'manufactured_date', e.target.value)}
                                        />
                                    </div>
                                    <div className="grid gap-1">
                                        <Label htmlFor={`fix-exp-${row.id}`}>{t('corr.grn.expiry')}</Label>
                                        <Input
                                            id={`fix-exp-${row.id}`}
                                            type="date"
                                            className="w-40"
                                            value={row.expiry_date}
                                            onChange={(e) => setLabel(index, 'expiry_date', e.target.value)}
                                        />
                                    </div>
                                    <div className="grid gap-1">
                                        <Label htmlFor={`fix-batch-${row.id}`}>{t('corr.grn.batch_number')}</Label>
                                        <Input
                                            id={`fix-batch-${row.id}`}
                                            className="w-40"
                                            value={row.batch_number}
                                            onChange={(e) => setLabel(index, 'batch_number', e.target.value)}
                                        />
                                    </div>
                                </div>
                            </div>
                        ))}
                        <div className="flex flex-wrap gap-3">
                            <div className="grid gap-1">
                                <Label htmlFor="fix-challan">{t('corr.grn.challan')}</Label>
                                <Input id="fix-challan" className="w-48" value={challan} onChange={(e) => setChallan(e.target.value)} />
                            </div>
                            <div className="grid flex-1 gap-1">
                                <Label htmlFor="fix-note">{t('corr.grn.note')}</Label>
                                <Input id="fix-note" value={note} onChange={(e) => setNote(e.target.value)} />
                            </div>
                        </div>
                    </CorrectionReasonDialog>
                );
            case 'correct':
                return (
                    <Button key="correct" size="sm" variant="outline" asChild>
                        <Link href={`/purchasing/receipts/${receipt.id}/correct`}>{text}</Link>
                    </Button>
                );
            case 'reverse':
                return (
                    <ActionDialog
                        key="reverse"
                        action="reverse"
                        recordLabel={label}
                        previewUrl={`/records/${correction.type}/${receipt.id}/reverse/preview`}
                        submitUrl={`/records/${correction.type}/${receipt.id}/reverse`}
                        reasons={correction.reasons.reversal ?? []}
                        allowToday={availability.allowed && availability.date?.rule === 'original'}
                        trigger={
                            <Button size="sm" variant="outline">
                                {text}
                            </Button>
                        }
                    />
                );
            default:
                return null;
        }
    });

    return (
        <div className="flex flex-col items-end gap-1.5">
            <div className="flex flex-wrap items-start justify-end gap-2">
                {!correction.reversed && buttons}
                <span className="self-center">
                    <RecordHistory type={correction.type} id={receipt.id} label={label} />
                </span>
            </div>
            {correction.reversed && (
                <p className="text-destructive text-xs">
                    {correction.corrected_by ? t('corr.corrected') : t('corr.reversed')}
                    {correction.reversed.on ? ` ${correction.reversed.on}` : ''}
                    {correction.reversed.reason ? `: ${correction.reversed.reason}` : ''}
                    {correction.corrected_by && (
                        <>
                            {' · '}
                            <Link href={`/purchasing/receipts/${correction.corrected_by.id}`} className="underline">
                                {t('corr.see', { label: correction.corrected_by.label })}
                            </Link>
                        </>
                    )}
                </p>
            )}
            {correction.correction_of && (
                <p className="text-muted-foreground text-xs">
                    <Link href={`/purchasing/receipts/${correction.correction_of.id}`} className="underline">
                        {t('corr.correction_of', { label: correction.correction_of.label })}
                    </Link>
                </p>
            )}
            {correction.details_fixed_at && <span className="text-muted-foreground text-xs">{t('corr.edited')}</span>}
        </div>
    );
}
