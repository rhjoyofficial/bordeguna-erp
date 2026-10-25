import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation, type TranslationKey } from '@/lib/i18n';
import { formatMoney } from '@/lib/utils';
import { useForm } from '@inertiajs/react';
import type { ReactNode } from 'react';

/** ⭐ B18 (25 Oct 2026): how an order came, the confirmation call, how it travels and how it is paid. */
export type ChannelInfo = {
    channel: string | null;
    channel_label: string | null;
    site: string | null;
    external_reference: string | null;
    confirmation: 'waiting' | 'confirmed' | 'no_answer' | 'customer_cancelled' | null;
    confirmation_label: string | null;
    confirmation_attempts: number;
    confirmation_last_try_at: string | null;
    confirmation_note: string | null;
    confirmed_by: string | null;
    delivery_method: string | null;
    delivery_method_label: string | null;
    delivery_zone: string | null;
    charge_set_by_hand: boolean;
    rider: string | null;
    cod_account: string | null;
    /** IP1 meets IP16: preselected in the order's Record payment box. */
    cod_account_id?: number | null;
    payment: string | null;
    payment_label: string | null;
    advance_open: string | null;
    order_discount: string | null;
    gift_lines: number;
};

const STAGE_TONE: Record<string, 'default' | 'secondary' | 'outline' | 'destructive'> = {
    cancelled: 'destructive',
    returned: 'destructive',
    delivered: 'default',
    closed: 'default',
    waiting_confirmation: 'secondary',
    backorder: 'secondary',
};

export function OrderStageBadge({ stage }: { stage: string }) {
    const { t } = useTranslation();

    return <Badge variant={STAGE_TONE[stage] ?? 'outline'}>{t(`och.stage.${stage}` as TranslationKey)}</Badge>;
}

/**
 * The panel on the order page. The call buttons are for whoever may take
 * orders; the server counts each try and refuses a stale second click.
 *
 * IP21 (25 Oct 2026): once the call says "Customer cancelled", the order's own
 * Cancel button (`cancelAction`, the same SalesOrderActions row and reason
 * dialog as the button at the foot of the page) is offered right here. It
 * never cancels by itself: the person still presses Cancel and gives a reason.
 */
export function OrderChannelPanel({
    orderId,
    info,
    canCall,
    finished,
    cancelAction = null,
}: {
    orderId: number;
    info: ChannelInfo;
    canCall: boolean;
    finished: boolean;
    cancelAction?: ReactNode;
}) {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors, reset, transform } = useForm({ outcome: '', tries_seen: info.confirmation_attempts, note: '' });

    // The button pressed is the outcome; the tries this page saw guard a stale click.
    const mark = (outcome: string) => {
        transform((form) => ({ ...form, outcome, tries_seen: info.confirmation_attempts }));
        post(route('sales.orders.confirmation', orderId), { preserveScroll: true, onSuccess: () => reset('note') });
    };

    const open = info.confirmation === 'waiting' || info.confirmation === 'no_answer';

    return (
        <div className="border-sidebar-border/70 dark:border-sidebar-border flex flex-col gap-3 rounded-xl border p-4 text-sm">
            <h3 className="font-medium">{t('och.panel_title')}</h3>
            <dl className="grid w-fit grid-cols-[auto_auto] gap-x-6 gap-y-1">
                {info.channel && info.channel !== 'internal' && (
                    <>
                        <dt className="text-muted-foreground">{t('och.channel')}</dt>
                        <dd>
                            {t(`och.channel.${info.channel}` as TranslationKey)}
                            {info.site ? ` (${info.site})` : ''}
                            {info.external_reference ? `, ${t('och.reference_short')} ${info.external_reference}` : ''}
                        </dd>
                    </>
                )}
                {info.delivery_method && (
                    <>
                        <dt className="text-muted-foreground">{t('och.delivery_method')}</dt>
                        <dd>
                            {t(`och.method.${info.delivery_method}` as TranslationKey)}
                            {info.delivery_zone ? `, ${t('och.zone')} ${info.delivery_zone}` : ''}
                            {info.charge_set_by_hand ? ` (${t('och.charge_by_hand')})` : ''}
                            {info.rider ? `, ${info.rider}` : ''}
                            {info.cod_account ? `, ${t('och.cod_account')} ${info.cod_account}` : ''}
                        </dd>
                    </>
                )}
                {info.payment && (
                    <>
                        <dt className="text-muted-foreground">{t('och.payment')}</dt>
                        <dd>
                            {['cod', 'ssl_paid', 'advance'].includes(info.payment)
                                ? t(`och.pay.${info.payment}` as TranslationKey)
                                : info.payment_label}
                            {info.advance_open !== null ? `, ${t('och.advance_open', { amount: formatMoney(info.advance_open) })}` : ''}
                        </dd>
                    </>
                )}
                {info.order_discount !== null && (
                    <>
                        <dt className="text-muted-foreground">{t('och.summary_order_discount')}</dt>
                        <dd className="tabular-nums">{formatMoney(info.order_discount)}</dd>
                    </>
                )}
                {info.gift_lines > 0 && (
                    <>
                        <dt className="text-muted-foreground">{t('och.gift')}</dt>
                        <dd>{t('och.gift_lines', { count: info.gift_lines })}</dd>
                    </>
                )}
                {info.confirmation && (
                    <>
                        <dt className="text-muted-foreground">{t('och.call')}</dt>
                        <dd>
                            <Badge
                                variant={
                                    info.confirmation === 'confirmed'
                                        ? 'default'
                                        : info.confirmation === 'customer_cancelled'
                                          ? 'destructive'
                                          : 'secondary'
                                }
                            >
                                {t(`och.confirm.${info.confirmation}` as TranslationKey)}
                            </Badge>
                            {info.confirmation_attempts > 0 && (
                                <span className="text-muted-foreground ml-2 text-xs">
                                    {t('och.tries', { count: info.confirmation_attempts, when: info.confirmation_last_try_at ?? '' })}
                                    {info.confirmed_by ? `, ${info.confirmed_by}` : ''}
                                </span>
                            )}
                            {info.confirmation_note && <p className="text-muted-foreground text-xs">{info.confirmation_note}</p>}
                        </dd>
                    </>
                )}
            </dl>
            {info.confirmation === 'customer_cancelled' && !finished && (
                <div className="flex flex-col gap-2">
                    <p className="text-xs text-amber-700 dark:text-amber-400">{t('och.cancel_next')}</p>
                    {cancelAction}
                </div>
            )}
            {canCall && !finished && info.confirmation !== null && (open || info.confirmation === 'confirmed') && (
                <div className="flex flex-wrap items-end gap-2">
                    <div className="grid w-full gap-1 sm:w-72">
                        <Label htmlFor="call-note">{t('och.call_note')}</Label>
                        <Input id="call-note" className="h-8" maxLength={255} value={data.note} onChange={(e) => setData('note', e.target.value)} />
                    </div>
                    {open && (
                        <>
                            <Button type="button" size="sm" disabled={processing} onClick={() => mark('confirmed')}>
                                {t('och.mark_confirmed')}
                            </Button>
                            <Button type="button" size="sm" variant="outline" disabled={processing} onClick={() => mark('no_answer')}>
                                {t('och.mark_no_answer')}
                            </Button>
                        </>
                    )}
                    <Button type="button" size="sm" variant="outline" disabled={processing} onClick={() => mark('customer_cancelled')}>
                        {t('och.mark_cancelled')}
                    </Button>
                </div>
            )}
            <InputError message={errors.outcome ?? errors.note} />
        </div>
    );
}
