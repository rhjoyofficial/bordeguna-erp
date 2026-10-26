import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { useEffect, useRef, useState } from 'react';

/** One customer as GET /sales/customers/lookup answers (CustomerLookupController). */
export interface LookupCustomer {
    id: number;
    code: string | null;
    name: string;
    phone: string | null;
    type: string;
    type_label: string;
    address: string | null;
    price_group_id: number | null;
    price_group: string | null;
    branch: string | null;
    /** The place of their last delivery, whole, or null. */
    saved_place: { district: string; thana: string; area: string; address_line: string; landmark: string } | null;
}

interface LookupAnswer {
    match: LookupCustomer | null;
    matches: LookupCustomer[];
}

/**
 * The matching form of a phone, the same as PhoneIdentity on the server:
 * digits only, +880 dropped, a lost leading 0 put back.
 */
export function phoneMatchKey(typed: string): string {
    let digits = typed.replace(/\D+/g, '');
    if (digits.startsWith('880')) digits = digits.slice(3);
    if (digits.length === 10 && digits.startsWith('1')) digits = `0${digits}`;

    return digits;
}

/** Digits with + - or spaces are a phone, however few (the quick-add dialog's rule). */
const looksLikePhone = (typed: string) => /\d/.test(typed) && /^[+\d\s-]+$/.test(typed);

/** "Sangram NGO > Kuakata", "Sangram NGO", or null for the retail list. */
export const groupLabel = (customer: Pick<LookupCustomer, 'price_group' | 'branch'>): string | null =>
    customer.price_group ? (customer.branch ? `${customer.price_group} > ${customer.branch}` : customer.price_group) : null;

async function lookUp(params: Record<string, string>, signal: AbortSignal): Promise<LookupAnswer> {
    // ⚠ A GET, like every hand-written fetch here (HandWrittenFetchTest): no
    // CSRF token to forget, nothing written.
    const response = await fetch(`${route('sales.customers.lookup')}?${new URLSearchParams(params).toString()}`, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
        signal,
    });

    if (!response.ok) throw new Error(String(response.status));

    return (await response.json()) as LookupAnswer;
}

/**
 * ⭐ Joy, 26 Oct 2026: "while sell or others things, if i enter number or name
 * matches will auto fill the necessary fields." Sits beside the customer
 * picker of every selling screen (order, counter, quotation, invoice, sample).
 *
 *  - A WHOLE phone that is exactly one customer's selects that customer: the
 *    screen fills what it has (name, phone, delivery place, prices). A note
 *    names who was found, with Undo, and a way on to a new customer.
 *  - Part of a name (or of a phone) lists up to eight to pick from. ⚠ A name
 *    alone never selects anybody: two Rahims are two people.
 *  - Whoever is selected, by this box or by the ordinary list, shows their
 *    price group, branch ("Sangram NGO > Kuakata") and type, read-only.
 *
 * ⚠ The server decides who may be found (CustomerLookupController): the same
 * customers the screen's own picker lists, so this is never a back door.
 */
export function CustomerLookup({
    id,
    selectedId,
    onPick,
    onUndo,
    onNew,
    disabled = false,
    className,
}: {
    id: string;
    /** The customer the screen has selected now ('' for none). */
    selectedId: string;
    onPick: (customer: LookupCustomer) => void;
    /** Undo an automatic pick: the screen goes back to whoever was selected before ('' for none). */
    onUndo: (previousId: string) => void;
    /** Keep the typed phone or name for a new customer (the screen's own add dialog). */
    onNew?: (typed: string) => void;
    disabled?: boolean;
    className?: string;
}) {
    const { t } = useTranslation();
    const [typed, setTyped] = useState('');
    const [matches, setMatches] = useState<LookupCustomer[] | null>(null);
    const [failed, setFailed] = useState(false);
    // The customer a whole phone selected, and who was selected before it.
    const [found, setFound] = useState<{ customer: LookupCustomer; previousId: string } | null>(null);
    // A phone whose automatic pick was undone is not picked again while it stays typed.
    const [declined, setDeclined] = useState<string | null>(null);
    const [info, setInfo] = useState<LookupCustomer | null>(null);
    const selectedRef = useRef(selectedId);
    selectedRef.current = selectedId;

    // What was typed, a moment after the typing stops.
    useEffect(() => {
        const text = typed.trim();
        const phone = looksLikePhone(text);
        const key = phone ? phoneMatchKey(text) : '';

        if (text === '' || (phone ? key.length < 4 : text.length < 2)) {
            setMatches(null);
            setFailed(false);

            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(async () => {
            try {
                const whole = phone && key.length === 11;
                const answer = await lookUp(whole ? { phone: text } : { q: text }, controller.signal);
                setFailed(false);

                if (answer.match && key !== declined) {
                    setFound({ customer: answer.match, previousId: selectedRef.current });
                    setInfo(answer.match);
                    setMatches(null);
                    onPick(answer.match);

                    return;
                }

                setMatches(answer.match ? [answer.match] : answer.matches);
            } catch (error) {
                if ((error as Error).name !== 'AbortError') setFailed(true);
            }
        }, 300);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
        // ⚠ Only the typing asks: onPick is a new function on every render.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [typed, declined]);

    // The selected customer's group, branch and type, however they were picked.
    const infoRef = useRef(info);
    infoRef.current = info;
    useEffect(() => {
        if (selectedId === '' || infoRef.current?.id === Number(selectedId)) return;

        const controller = new AbortController();
        lookUp({ id: selectedId }, controller.signal)
            .then((answer) => setInfo(answer.match))
            .catch(() => undefined);

        return () => controller.abort();
    }, [selectedId]);

    // Somebody else picked from the list: the note about the found customer goes.
    useEffect(() => {
        if (found && String(found.customer.id) !== selectedId) setFound(null);
    }, [selectedId, found]);

    const pick = (customer: LookupCustomer) => {
        setMatches(null);
        setInfo(customer);
        setFound(null);
        onPick(customer);
    };

    const undo = () => {
        if (!found) return;
        setDeclined(phoneMatchKey(typed.trim()));
        onUndo(found.previousId);
        setFound(null);
    };

    const shown = info && String(info.id) === selectedId ? info : null;
    const samePhone = matches !== null && matches.length > 1 && looksLikePhone(typed.trim()) && phoneMatchKey(typed.trim()).length === 11;

    return (
        <div className={cn('grid gap-1', className)}>
            <Label htmlFor={id} className="text-xs">
                {t('clk.label')}
            </Label>
            <div className="relative w-56 max-w-full">
                <Input
                    id={id}
                    value={typed}
                    disabled={disabled}
                    autoComplete="off"
                    placeholder={t('clk.placeholder')}
                    onChange={(e) => {
                        setTyped(e.target.value);
                        if (declined !== null && phoneMatchKey(e.target.value) !== declined) setDeclined(null);
                    }}
                    onKeyDown={(e) => {
                        // ⚠ Enter here never submits the form around it.
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            if (matches?.length === 1) pick(matches[0]);
                        }
                        if (e.key === 'Escape') setMatches(null);
                    }}
                    // A moment's grace so a click on a row lands before the list goes.
                    onBlur={() => window.setTimeout(() => setMatches(null), 200)}
                    className="w-56 max-w-full"
                />
                {matches !== null && (
                    <div className="bg-popover text-popover-foreground absolute top-full left-0 z-50 mt-1 w-80 max-w-[90vw] rounded-md border p-1 shadow-md">
                        {matches.length === 0 ? (
                            <p className="text-muted-foreground px-2 py-1.5 text-xs">{t('clk.none')}</p>
                        ) : (
                            <>
                                <p className="text-muted-foreground px-2 py-1 text-xs">
                                    {samePhone ? t('clk.same_phone', { count: matches.length }) : t('clk.pick')}
                                </p>
                                {matches.map((customer) => (
                                    <button
                                        key={customer.id}
                                        type="button"
                                        className="hover:bg-accent flex w-full flex-col items-start rounded-sm px-2 py-1.5 text-left text-sm"
                                        onClick={() => pick(customer)}
                                    >
                                        <span>
                                            {customer.name}
                                            {customer.code ? <span className="text-muted-foreground"> ({customer.code})</span> : null}
                                        </span>
                                        <span className="text-muted-foreground text-xs">
                                            {[customer.phone, groupLabel(customer) ?? t('clk.retail')].filter(Boolean).join(', ')}
                                        </span>
                                    </button>
                                ))}
                            </>
                        )}
                        {onNew && (
                            <button
                                type="button"
                                className="hover:bg-accent text-primary w-full rounded-sm border-t px-2 py-1.5 text-left text-sm"
                                onClick={() => {
                                    setMatches(null);
                                    onNew(typed.trim());
                                }}
                            >
                                {t('clk.new')}
                            </button>
                        )}
                    </div>
                )}
            </div>
            {failed && <p className="text-destructive text-xs">{t('clk.failed')}</p>}
            {found && String(found.customer.id) === selectedId && (
                <p className="w-fit rounded-md border border-emerald-300/60 bg-emerald-50 px-2 py-1 text-xs text-emerald-800 dark:border-emerald-700/50 dark:bg-emerald-950/30 dark:text-emerald-300">
                    {found.customer.code
                        ? t('clk.found', { name: found.customer.name, code: found.customer.code })
                        : t('clk.found_no_code', { name: found.customer.name })}{' '}
                    <button type="button" className="font-medium underline" onClick={undo}>
                        {t('clk.undo')}
                    </button>
                    {onNew && (
                        <>
                            {' '}
                            <button
                                type="button"
                                className="font-medium underline"
                                onClick={() => {
                                    undo();
                                    onNew(typed.trim());
                                }}
                            >
                                {t('clk.new')}
                            </button>
                        </>
                    )}
                </p>
            )}
            {shown && (
                <p className="text-muted-foreground text-xs">
                    {t('clk.prices', { group: groupLabel(shown) ?? t('clk.retail') })}. {t('clk.type', { type: shown.type_label })}.
                </p>
            )}
        </div>
    );
}
