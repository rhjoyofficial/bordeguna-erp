import { ReasonDialog, type ReasonOption } from '@/components/sales/correction-actions';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/lib/i18n';
import { Link } from '@inertiajs/react';

export type OrderAction = { action: 'edit' | 'cancel' | 'close' | 'copy'; label: string; allowed: boolean; primary: boolean; reason: string | null };

/**
 * ⭐ Corrections wave 1 (20 Oct 2026, FINAL-PLAN 3.2): an order's buttons, as
 * the server allows them for this person and this stage (SalesOrderActions).
 * Edit and Copy open the SAME order form as a new order; Cancel and Close
 * short ask for a reason. A blocked button stays, greyed, with its sentence.
 */
export function OrderActions({
    order,
    actions,
    reasons,
    cancelNote,
}: {
    order: { id: number; order_number: string };
    actions: OrderAction[];
    reasons: ReasonOption[];
    cancelNote: string | null;
}) {
    const { t } = useTranslation();

    if (actions.length === 0) {
        return null;
    }

    const blocked = actions.filter((a) => !a.allowed && a.reason).map((a) => a.reason as string);

    return (
        <div className="flex flex-col gap-1">
            <div className="flex flex-wrap gap-2">
                {actions.map((option) => {
                    const label =
                        option.action === 'edit'
                            ? option.label === 'Edit'
                                ? t('corr.sales.order.edit')
                                : t('corr.sales.order.edit_undelivered')
                            : option.action === 'cancel'
                              ? t('corr.sales.order.cancel')
                              : option.action === 'close'
                                ? t('corr.sales.order.close')
                                : t('corr.sales.order.copy');
                    const variant = option.primary ? 'outline' : 'ghost';

                    if (!option.allowed) {
                        return (
                            <Button key={option.action} size="sm" variant={variant} disabled title={option.reason ?? undefined}>
                                {label}
                            </Button>
                        );
                    }

                    if (option.action === 'edit' || option.action === 'copy') {
                        return (
                            <Button key={option.action} size="sm" variant={variant} asChild>
                                <Link href={`/sales/orders?${option.action}=${order.id}`}>{label}</Link>
                            </Button>
                        );
                    }

                    return (
                        <ReasonDialog
                            key={option.action}
                            trigger={
                                <Button size="sm" variant={variant}>
                                    {label}
                                </Button>
                            }
                            title={
                                option.action === 'cancel'
                                    ? t('corr.sales.order.cancel_title', { number: order.order_number })
                                    : t('corr.sales.order.close_title', { number: order.order_number })
                            }
                            description={
                                (option.action === 'cancel' ? t('corr.sales.order.cancel_text') : t('corr.sales.order.close_text')) +
                                (option.action === 'cancel' && cancelNote ? ` ${cancelNote}` : '')
                            }
                            url={`/sales/orders/${order.id}/${option.action}`}
                            reasons={reasons}
                            reasonKey="reason"
                            confirmLabel={label}
                            destructive
                        />
                    );
                })}
            </div>
            {blocked.map((message) => (
                <p key={message} className="text-muted-foreground text-xs">
                    {message}
                </p>
            ))}
        </div>
    );
}
