import { CorrectionButtons, type AccountsCorrections } from '@/components/accounting/correction-buttons';
import { DuplicateWarning } from '@/components/accounting/duplicate-warning';
import { Pagination } from '@/components/data-table/pagination';
import InputError from '@/components/input-error';
import { Amount, DateCell, DocNo } from '@/components/ledger-text';
import { ReportHeading } from '@/components/report/report-shell';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Joy's answer 9 (25 Oct 2026): Accounts > Bank & cash > SSL settlements. SSL's
 * payout is recorded in a popup (date first): the gross leaves SSL Commerz
 * Clearing, SSL's charge goes to Payment Gateway Charge, the net lands in the
 * bank. Reverse goes through the corrections process. No <form>: Enter never
 * submits.
 */
type Settlement = {
    id: number;
    number: string;
    settled_on: string;
    bank: string | null;
    gross: string;
    fee: string;
    net: string;
    fee_account: string | null;
    reference: string | null;
    note: string | null;
    evidence: { id: number; name: string; url: string }[];
    reversal: { on: string | null; reason: string | null } | null;
    allow_today: boolean;
    corrections: AccountsCorrections;
};

type Bank = { id: number; name: string; type: string };

const breadcrumbs: BreadcrumbItem[] = [{ title: 'SSL settlements', href: '/accounting/ssl-settlements' }];

const money = (value: number | string) => Number(value).toLocaleString('en-BD', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function SettlementDialog({ banks, today, onClose }: { banks: Bank[]; today: string; onClose: () => void }) {
    const { t } = useTranslation();
    const { data, setData, post, errors, processing, transform } = useForm<{
        settled_on: string;
        bank_money_account_id: string;
        gross: string;
        fee: string;
        reference: string;
        note: string;
        file: File | null;
        duplicate_ack: string | null;
    }>({
        settled_on: today,
        bank_money_account_id: banks.length === 1 ? String(banks[0].id) : '',
        gross: '',
        fee: '',
        reference: '',
        note: '',
        file: null,
        duplicate_ack: null,
    });
    const select = 'border-input bg-background h-9 w-fit max-w-full rounded-md border px-2 text-sm';
    const net = Math.round((Number(data.gross || 0) - Number(data.fee || 0)) * 100) / 100;
    const errs = errors as Record<string, string | undefined>;

    const send = (ack: string | null) => {
        if (processing) return;
        transform((form) => ({ ...form, duplicate_ack: ack }));
        post(route('accounting.ssl-settlements.store'), { preserveScroll: true, forceFormData: true, onSuccess: onClose });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-lg" aria-describedby={undefined}>
                <DialogHeader>
                    <DialogTitle>{t('ipa.ssl.add')}</DialogTitle>
                </DialogHeader>
                <div className="grid gap-3 text-sm">
                    <div className="grid gap-1">
                        <Label htmlFor="ssl-date" required>
                            {t('ipa.ssl.date')}
                        </Label>
                        <Input
                            id="ssl-date"
                            type="date"
                            className="w-fit"
                            value={data.settled_on}
                            max={today}
                            onChange={(e) => setData('settled_on', e.target.value)}
                        />
                        <InputError message={errs.settled_on} />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="ssl-bank" required>
                            {t('ipa.ssl.bank')}
                        </Label>
                        <select
                            id="ssl-bank"
                            className={select}
                            value={data.bank_money_account_id}
                            onChange={(e) => setData('bank_money_account_id', e.target.value)}
                        >
                            <option value="">{t('ipa.ssl.choose')}</option>
                            {banks.map((b) => (
                                <option key={b.id} value={b.id}>
                                    {b.name}
                                </option>
                            ))}
                        </select>
                        <InputError message={errs.bank_money_account_id} />
                    </div>
                    <div className="flex flex-wrap items-end gap-3">
                        <div className="grid gap-1">
                            <Label htmlFor="ssl-gross" required>
                                {t('ipa.ssl.gross')}
                            </Label>
                            <Input
                                id="ssl-gross"
                                inputMode="decimal"
                                className="w-36"
                                value={data.gross}
                                onChange={(e) => setData('gross', e.target.value)}
                            />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="ssl-fee">{t('ipa.ssl.fee')}</Label>
                            <Input
                                id="ssl-fee"
                                inputMode="decimal"
                                className="w-28"
                                value={data.fee}
                                onChange={(e) => setData('fee', e.target.value)}
                            />
                        </div>
                        <div className="grid gap-1">
                            <span className="text-muted-foreground text-xs">{t('ipa.ssl.net')}</span>
                            <span className="font-semibold tabular-nums">{money(net)}</span>
                        </div>
                    </div>
                    <InputError message={errs.gross ?? errs.fee} />
                    <div className="grid gap-1">
                        <Label htmlFor="ssl-ref">{t('ipa.ssl.reference')}</Label>
                        <Input id="ssl-ref" className="w-60" value={data.reference} onChange={(e) => setData('reference', e.target.value)} />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="ssl-note">{t('ipa.ssl.note')}</Label>
                        <textarea
                            id="ssl-note"
                            className="border-input bg-background min-h-14 w-full rounded-md border px-3 py-2 text-sm"
                            value={data.note}
                            onChange={(e) => setData('note', e.target.value)}
                        />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="ssl-file">{t('ipa.ssl.file')}</Label>
                        <input id="ssl-file" type="file" className="text-sm" onChange={(e) => setData('file', e.target.files?.[0] ?? null)} />
                        <InputError message={errs.file} />
                    </div>
                    <DuplicateWarning errors={errs} busy={processing} onSaveAnyway={(ack) => send(ack)} />
                </div>
                <DialogFooter>
                    <Button type="button" variant="outline" onClick={onClose}>
                        {t('ipa.ssl.cancel')}
                    </Button>
                    <Button
                        type="button"
                        disabled={processing || data.bank_money_account_id === '' || !(Number(data.gross) > 0)}
                        onClick={() => send(null)}
                    >
                        {t('ipa.ssl.save')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function SslSettlementsIndex({
    settlements,
    showCorrections = false,
    today,
    clearing,
    banks,
}: {
    settlements: Paginated<Settlement>;
    showCorrections?: boolean;
    today: string;
    clearing: { id: number; name: string; balance: string } | null;
    banks: Bank[];
}) {
    const { t } = useTranslation();
    const [adding, setAdding] = useState(false);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('ipa.ssl.title')} />
            <div className="flex flex-col gap-4 p-4">
                <ReportHeading
                    title={t('ipa.ssl.title')}
                    description={t('ipa.ssl.intro')}
                    actions={
                        clearing && (
                            <Button type="button" onClick={() => setAdding(true)}>
                                {t('ipa.ssl.add')}
                            </Button>
                        )
                    }
                />
                <p className="text-sm font-medium">
                    {clearing ? t('ipa.ssl.in_clearing', { name: clearing.name, amount: money(clearing.balance) }) : t('ipa.ssl.no_clearing')}
                </p>

                <label className="flex w-fit items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        checked={showCorrections}
                        onChange={(e) =>
                            router.get(route('accounting.ssl-settlements.index'), e.target.checked ? { show_corrections: 1 } : {}, {
                                preserveState: true,
                                preserveScroll: true,
                            })
                        }
                    />
                    {t('ipa.ssl.show_corrections')}
                </label>

                <div className="w-fit max-w-full overflow-x-auto rounded-lg border">
                    <table className="text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">{t('ipa.ssl.col_number')}</th>
                                <th className="p-3">{t('ipa.ssl.col_date')}</th>
                                <th className="p-3">{t('ipa.ssl.col_bank')}</th>
                                <th className="p-3 text-right">{t('ipa.ssl.col_gross')}</th>
                                <th className="p-3 text-right">{t('ipa.ssl.col_fee')}</th>
                                <th className="p-3 text-right">{t('ipa.ssl.col_net')}</th>
                                <th className="p-3">{t('ipa.ssl.col_reference')}</th>
                                <th className="p-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {settlements.data.length === 0 && (
                                <tr>
                                    <td className="text-muted-foreground p-3" colSpan={8}>
                                        {t('ipa.ssl.none')}
                                    </td>
                                </tr>
                            )}
                            {settlements.data.map((s) => (
                                <tr key={s.id} id={`ssl-${s.id}`} className={`border-t ${s.reversal ? 'text-muted-foreground' : ''}`}>
                                    <td className="p-3">
                                        <DocNo>{s.number}</DocNo>
                                        {s.reversal && (
                                            <span className="ml-2 rounded bg-zinc-200 px-2 py-0.5 text-xs dark:bg-zinc-800">
                                                {t('ipa.ssl.reversed')}
                                            </span>
                                        )}
                                        {s.evidence.map((file) => (
                                            <a key={file.id} href={file.url} target="_blank" rel="noreferrer" className="block text-xs underline">
                                                {file.name}
                                            </a>
                                        ))}
                                    </td>
                                    <td className="p-3">
                                        <DateCell>{showDate(s.settled_on)}</DateCell>
                                    </td>
                                    <td className="p-3">{s.bank ?? '-'}</td>
                                    <td className="p-3 text-right">
                                        <Amount value={s.gross} tone="credit" />
                                    </td>
                                    <td className="p-3 text-right tabular-nums">{Number(s.fee) > 0 ? money(s.fee) : '-'}</td>
                                    <td className="p-3 text-right">
                                        <Amount value={s.net} tone="debit" />
                                    </td>
                                    <td className="text-muted-foreground p-3">{s.reference ?? '-'}</td>
                                    <td className="p-3">
                                        {!s.reversal && (
                                            <CorrectionButtons
                                                corrections={{ ...s.corrections, allow_today: s.allow_today }}
                                                recordId={s.id}
                                                recordLabel={s.number}
                                            />
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <Pagination meta={settlements} />
                {adding && <SettlementDialog banks={banks} today={today} onClose={() => setAdding(false)} />}
            </div>
        </AppLayout>
    );
}
