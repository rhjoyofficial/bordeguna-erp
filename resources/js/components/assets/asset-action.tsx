import { StockReasonDialog, type StockAvailability } from '@/components/inventory/stock-reason-dialog';
import { Button } from '@/components/ui/button';
import { type FormDataConvertible } from '@inertiajs/core';
import { type ReactNode } from 'react';

export type AssetAvailability = StockAvailability;

export type ReasonOption = { id: number; name: string };

/**
 * ⭐ Corrections wave 3, B11 (24 Oct 2026): one button for an action the
 * things register runs itself (Delete, Dispose, Undo disposal, Reverse,
 * Cancel, Reopen, Reverse import), drawn from the record's policy.
 *
 *   allowed   the reason dialog (StockReasonDialog: a pick list plus the
 *             person's own words, 5 characters, no <form>, so Enter never
 *             sends it), with the action's own boxes first (date first)
 *   refused   the button stays, greyed, with its one sentence underneath
 *             (FINAL-PLAN 2, screen rules)
 *
 * The server asks the same policy again; this decides nothing.
 */
export function AssetAction({
    availability,
    label,
    title,
    description,
    url,
    reasons,
    method = 'post',
    data,
    destructive = false,
    ready = true,
    children,
}: {
    availability: AssetAvailability | undefined;
    label: string;
    title: string;
    description?: string;
    url: string;
    reasons: ReasonOption[];
    method?: 'post' | 'delete';
    data?: Record<string, FormDataConvertible>;
    destructive?: boolean;
    ready?: boolean;
    children?: ReactNode;
}) {
    if (!availability) {
        return null;
    }

    if (!availability.allowed) {
        return (
            <div className="grid max-w-xs gap-1">
                <Button type="button" size="sm" variant="outline" disabled>
                    {label}
                </Button>
                {availability.blockers[0] && <p className="text-muted-foreground text-xs whitespace-normal">{availability.blockers[0].message}</p>}
            </div>
        );
    }

    return (
        <StockReasonDialog
            trigger={
                <Button type="button" size="sm" variant={destructive ? 'destructive' : 'outline'}>
                    {label}
                </Button>
            }
            title={title}
            description={description}
            confirmLabel={label}
            reasons={reasons}
            url={url}
            method={method}
            data={data}
            destructive={destructive}
            ready={ready}
        >
            {children}
        </StockReasonDialog>
    );
}

/** The availability of one action out of a record's list, by its value. */
export function pick(actions: AssetAvailability[] | undefined, action: string): AssetAvailability | undefined {
    return actions?.find((a) => a.action === action);
}
