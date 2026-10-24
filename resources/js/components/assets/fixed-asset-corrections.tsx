import { ActionDialog } from '@/components/action-dialog';
import { PlainActionButton } from '@/components/corrections/plain-action-button';
import { type Availability, BlockedAction, type ReasonOption } from '@/components/purchasing/correction-actions';
import { RecordHistory } from '@/components/record-history';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/lib/i18n';
import { formatMoney } from '@/lib/utils';
import { useState } from 'react';

export type AssetFigures = { cost: string; accumulated: string; book_value: string; book_value_minor: number };

type Head = { id: number; label: string; type: string };

/**
 * ⭐ Corrections wave 2, B8 (24 Oct 2026): a fixed asset's two buttons
 * (FINAL-PLAN 3.5). Reverse (entered by mistake) goes through ActionDialog and
 * the corrections process, which previews the entries and their dates; Dispose
 * (it left the business) is the asset's own dialog: the date first, how it
 * left, what it fetched and where the gain or loss goes.
 *
 * ⚠ The gain or loss is worked out here only to show it; the server works it
 * out again from the books and refuses a head of the wrong kind.
 */
export function FixedAssetCorrections({
    asset,
    reasons,
    moneyAccounts,
    heads,
    today,
}: {
    asset: { id: number; asset_code: string; acquired_on: string | null; actions: Availability[]; figures: AssetFigures };
    reasons: Record<string, ReasonOption[]>;
    moneyAccounts: { id: number; name: string }[];
    heads: Head[];
    today: string;
}) {
    const { t } = useTranslation();
    const reverse = asset.actions.find((a) => a.action === 'reverse');
    const dispose = asset.actions.find((a) => a.action === 'dispose');
    const base = `/records/assets.fixed_asset/${asset.id}`;

    const [on, setOn] = useState(today);
    const [method, setMethod] = useState('sold');
    const [proceeds, setProceeds] = useState('');
    const [account, setAccount] = useState('');
    const [head, setHead] = useState('');

    const receivedMinor = Math.round(Number(proceeds || '0') * 100);
    const difference = (method === 'sold' ? receivedMinor : 0) - asset.figures.book_value_minor;
    const wanted = difference > 0 ? 'income' : 'expense';
    const amount = formatMoney((Math.abs(difference) / 100).toFixed(2));

    return (
        <div className="flex flex-wrap items-start justify-end gap-2">
            {dispose && (
                <PlainActionButton
                    availability={dispose}
                    label={t('corr.people.dispose')}
                    title={t('corr.people.asset.dispose_title', { number: asset.asset_code })}
                    description={t('corr.people.asset.dispose_explain')}
                    submitUrl={`/assets/fixed/${asset.id}/dispose`}
                    reasons={reasons.disposal ?? []}
                    destructive
                    data={() => ({
                        disposed_on: on,
                        method,
                        proceeds: method === 'sold' ? proceeds || '0' : '0',
                        money_account_id: method === 'sold' && receivedMinor > 0 && account !== '' ? Number(account) : null,
                        gain_loss_account_id: difference !== 0 && head !== '' ? Number(head) : null,
                    })}
                >
                    {/* Dates first (Joy, 19 Oct 2026). */}
                    <div className="grid gap-1">
                        <Label htmlFor={`dispose-on-${asset.id}`} required>
                            {t('corr.people.asset.disposed_on')}
                        </Label>
                        <Input
                            id={`dispose-on-${asset.id}`}
                            type="date"
                            className="w-44"
                            value={on}
                            min={asset.acquired_on ?? undefined}
                            max={today}
                            onChange={(e) => setOn(e.target.value)}
                        />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor={`dispose-method-${asset.id}`} required>
                            {t('corr.people.asset.method')}
                        </Label>
                        <select
                            id={`dispose-method-${asset.id}`}
                            value={method}
                            onChange={(e) => setMethod(e.target.value)}
                            className="border-input bg-background h-9 w-fit rounded-md border px-2 text-sm"
                        >
                            <option value="sold">{t('corr.people.asset.method_sold')}</option>
                            <option value="scrapped">{t('corr.people.asset.method_scrapped')}</option>
                            <option value="donated">{t('corr.people.asset.method_donated')}</option>
                            <option value="lost">{t('corr.people.asset.method_lost')}</option>
                        </select>
                    </div>
                    {method === 'sold' && (
                        <div className="flex flex-wrap gap-3">
                            <div className="grid gap-1">
                                <Label htmlFor={`dispose-proceeds-${asset.id}`}>{t('corr.people.asset.proceeds')}</Label>
                                <Input
                                    id={`dispose-proceeds-${asset.id}`}
                                    inputMode="decimal"
                                    className="w-36"
                                    value={proceeds}
                                    onChange={(e) => setProceeds(e.target.value)}
                                />
                            </div>
                            {receivedMinor > 0 && (
                                <div className="grid gap-1">
                                    <Label htmlFor={`dispose-account-${asset.id}`} required>
                                        {t('corr.people.asset.money_account')}
                                    </Label>
                                    <select
                                        id={`dispose-account-${asset.id}`}
                                        value={account}
                                        onChange={(e) => setAccount(e.target.value)}
                                        className="border-input bg-background h-9 w-fit max-w-full rounded-md border px-2 text-sm"
                                    >
                                        <option value="">{t('corr.people.asset.pick_account')}</option>
                                        {moneyAccounts.map((m) => (
                                            <option key={m.id} value={m.id}>
                                                {m.name}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                            )}
                        </div>
                    )}
                    <p className="text-muted-foreground">{t('corr.people.asset.book_value', { amount: formatMoney(asset.figures.book_value) })}</p>
                    {difference === 0 ? (
                        <p>{t('corr.people.asset.no_gain_loss')}</p>
                    ) : (
                        <div className="grid gap-1">
                            <Label htmlFor={`dispose-head-${asset.id}`} required>
                                {difference > 0 ? t('corr.people.asset.gain', { amount }) : t('corr.people.asset.loss', { amount })}
                            </Label>
                            <select
                                id={`dispose-head-${asset.id}`}
                                value={head}
                                onChange={(e) => setHead(e.target.value)}
                                className="border-input bg-background h-9 w-fit max-w-full rounded-md border px-2 text-sm"
                            >
                                <option value="">{t('corr.people.asset.pick_head')}</option>
                                {heads
                                    .filter((h) => h.type === wanted)
                                    .map((h) => (
                                        <option key={h.id} value={h.id}>
                                            {h.label}
                                        </option>
                                    ))}
                            </select>
                        </div>
                    )}
                </PlainActionButton>
            )}

            {reverse &&
                (reverse.allowed || reverse.requestable ? (
                    <ActionDialog
                        action="reverse"
                        recordLabel={asset.asset_code}
                        previewUrl={`${base}/reverse/preview`}
                        submitUrl={`${base}/reverse`}
                        reasons={reasons.reversal ?? []}
                        trigger={
                            <Button size="sm" variant="outline" title={t('corr.people.asset.reverse_hint')}>
                                {t('corr.people.reverse')}
                            </Button>
                        }
                    />
                ) : (
                    <BlockedAction availability={reverse} label={t('corr.people.reverse')} />
                ))}

            <RecordHistory type="assets.fixed_asset" id={asset.id} label={asset.asset_code} />
        </div>
    );
}
