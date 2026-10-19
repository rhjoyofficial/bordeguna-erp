import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { banglaToLatin, completeDate, daysInMonth, dhakaToday, dmy, maskDate, maskMonth, my, parseMy } from '@/lib/dates';
import { cn } from '@/lib/utils';
import { CalendarDays, ChevronLeft, ChevronRight, ChevronsLeft, ChevronsRight } from 'lucide-react';
import * as React from 'react';

/**
 * ⚠⚠ DD/MM/YYYY DATE BOXES (Joy, 18 Oct 2026: "date format DD/MM/YYYY in my
 * ENTIRE ERP ... in our country we always write Day Month Year").
 *
 * The browser's own `<input type="date">` draws the date in the COMPUTER's
 * order (mm/dd/yyyy on an American-set Windows) and a page cannot change that,
 * so every date, date-and-time and month box in the ERP is one of these
 * instead. `components/ui/input.tsx` sends `type="date"`, `"datetime-local"`
 * and `"month"` here, so `<Input type="date">` needs no change at a call site.
 *
 * ⚠ The VALUE CONTRACT IS THE NATIVE ONE, unchanged: `value` and the
 * `e.target.value` handed to `onChange` are `YYYY-MM-DD`, `YYYY-MM-DDTHH:mm`
 * and `YYYY-MM`, exactly what the native box produced, so no form, server
 * rule or test changed. A plain `<form>` posts the same value through a hidden
 * input carrying `name`.
 *
 * Typing: digits get their slashes added (17092026 -> 17/09/2026), one-digit
 * day and month are fine (5/9/2026), a pasted 2026-09-17 is understood, and
 * Bangla digits are read. Half-typed text keeps the last good value (a filter
 * that reloads on change is not fired with an empty date per keystroke); the
 * box shows a small red hint and blocks the form's own submit until fixed.
 */

type EventLike = React.ChangeEvent<HTMLInputElement>;

type NativeProps = Omit<React.ComponentProps<'input'>, 'type' | 'value' | 'defaultValue' | 'onChange' | 'ref'>;

export interface DateLikeInputProps extends NativeProps {
    value?: string | number | readonly string[] | null;
    defaultValue?: string | number | readonly string[] | null;
    onChange?: React.ChangeEventHandler<HTMLInputElement>;
}


const pad = (n: number) => String(n).padStart(2, '0');

/** A width class belongs on the wrapper; everything else (height, border, text) on the box itself. */
function splitClasses(className: string | undefined): { outer: string; inner: string } {
    const outer: string[] = [];
    const inner: string[] = [];
    for (const token of (className ?? '').split(/\s+/).filter(Boolean)) {
        const bare = token.replace(/^([a-z0-9]+:)+/, '');
        if (bare === 'w-auto' || bare === 'w-fit') continue;
        if (/^(w-|min-w-|max-w-|flex-|grow|shrink|basis-|self-|col-|row-|order-|m[trblxy]?-|-m[trblxy]?-)/.test(bare)) outer.push(token);
        else inner.push(token);
    }

    return { outer: outer.join(' '), inner: inner.join(' ') };
}

function fireChange(onChange: DateLikeInputProps['onChange'], value: string, name: string | undefined) {
    if (!onChange) return;
    const target = { value, name: name ?? '' };
    onChange({ target, currentTarget: target, type: 'change', preventDefault() {}, stopPropagation() {} } as unknown as EventLike);
}



const toText = (value: DateLikeInputProps['value']) => (value === null || value === undefined ? '' : String(value));

/* ---------------------------------------------------------------- calendar */

const MONTHS_LONG = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
const MONTHS_SHORT = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const WEEK = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];

function inRange(iso: string, min?: string, max?: string): boolean {
    return (!min || iso >= min) && (!max || iso <= max);
}

function CalendarGrid({ value, min, max, onPick }: { value: string; min?: string; max?: string; onPick: (iso: string) => void }) {
    const start = (value || dhakaToday()).slice(0, 7);
    const [view, setView] = React.useState(() => ({ y: Number(start.slice(0, 4)), m: Number(start.slice(5, 7)) }));
    const today = dhakaToday();

    const move = (months: number) =>
        setView(({ y, m }) => {
            const index = y * 12 + (m - 1) + months;

            return { y: Math.floor(index / 12), m: (index % 12) + 1 };
        });

    const first = new Date(Date.UTC(view.y, view.m - 1, 1)).getUTCDay(); // 0 = Sunday
    const lead = (first + 6) % 7; // Monday first
    const total = daysInMonth(view.y, view.m);
    const cells: (number | null)[] = [...Array(lead).fill(null), ...Array.from({ length: total }, (_, i) => i + 1)];

    const nav = 'text-muted-foreground hover:bg-accent hover:text-foreground inline-flex size-7 items-center justify-center rounded-md';

    return (
        <div className="w-64 select-none">
            <div className="mb-2 flex items-center justify-between">
                <div className="flex">
                    <button type="button" className={nav} onClick={() => move(-12)} aria-label="Previous year">
                        <ChevronsLeft className="size-4" />
                    </button>
                    <button type="button" className={nav} onClick={() => move(-1)} aria-label="Previous month">
                        <ChevronLeft className="size-4" />
                    </button>
                </div>
                <div className="text-sm font-medium">
                    {MONTHS_LONG[view.m - 1]} {view.y}
                </div>
                <div className="flex">
                    <button type="button" className={nav} onClick={() => move(1)} aria-label="Next month">
                        <ChevronRight className="size-4" />
                    </button>
                    <button type="button" className={nav} onClick={() => move(12)} aria-label="Next year">
                        <ChevronsRight className="size-4" />
                    </button>
                </div>
            </div>
            <div className="text-muted-foreground grid grid-cols-7 text-center text-[11px]">
                {WEEK.map((d) => (
                    <div key={d} className="py-1">
                        {d}
                    </div>
                ))}
            </div>
            <div role="listbox" aria-label="Days" className="grid grid-cols-7 gap-0.5 text-center text-sm">
                {cells.map((day, i) => {
                    if (day === null) return <div key={`b${i}`} />;
                    const iso = `${view.y}-${pad(view.m)}-${pad(day)}`;
                    const allowed = inRange(iso, min, max);

                    return (
                        <button
                            key={iso}
                            type="button"
                            disabled={!allowed}
                            onClick={() => onPick(iso)}
                            // role="option": a pick inside a popup marks its form edited (dialog.tsx).
                            role="option"
                            aria-label={dmy(iso)}
                            aria-selected={iso === value}
                            className={cn(
                                'h-8 rounded-md tabular-nums',
                                iso === value
                                    ? 'bg-primary text-primary-foreground'
                                    : iso === today
                                      ? 'text-foreground ring-border font-semibold ring-1'
                                      : 'hover:bg-accent',
                                !allowed && 'cursor-not-allowed opacity-30 hover:bg-transparent',
                            )}
                        >
                            {day}
                        </button>
                    );
                })}
            </div>
            <div className="mt-2 flex justify-between border-t pt-2">
                <button
                    type="button"
                    role="option"
                    aria-selected={value === today}
                    className="text-primary text-xs font-medium hover:underline disabled:opacity-40"
                    disabled={!inRange(today, min, max)}
                    onClick={() => onPick(today)}
                >
                    Today ({dmy(today)})
                </button>
            </div>
        </div>
    );
}

function MonthGrid({ value, min, max, onPick }: { value: string; min?: string; max?: string; onPick: (ym: string) => void }) {
    const [year, setYear] = React.useState(() => Number((value || dhakaToday()).slice(0, 4)));
    const nav = 'text-muted-foreground hover:bg-accent hover:text-foreground inline-flex size-7 items-center justify-center rounded-md';

    return (
        <div className="w-56 select-none">
            <div className="mb-2 flex items-center justify-between">
                <button type="button" className={nav} onClick={() => setYear((y) => y - 1)} aria-label="Previous year">
                    <ChevronLeft className="size-4" />
                </button>
                <div className="text-sm font-medium tabular-nums">{year}</div>
                <button type="button" className={nav} onClick={() => setYear((y) => y + 1)} aria-label="Next year">
                    <ChevronRight className="size-4" />
                </button>
            </div>
            <div role="listbox" aria-label="Months" className="grid grid-cols-3 gap-1 text-sm">
                {MONTHS_SHORT.map((label, i) => {
                    const ym = `${year}-${pad(i + 1)}`;
                    const allowed = (!min || ym >= min.slice(0, 7)) && (!max || ym <= max.slice(0, 7));

                    return (
                        <button
                            key={ym}
                            type="button"
                            disabled={!allowed}
                            role="option"
                            aria-selected={ym === value}
                            onClick={() => onPick(ym)}
                            className={cn(
                                'h-8 rounded-md',
                                ym === value ? 'bg-primary text-primary-foreground' : 'hover:bg-accent',
                                !allowed && 'cursor-not-allowed opacity-30 hover:bg-transparent',
                            )}
                        >
                            {label}
                        </button>
                    );
                })}
            </div>
        </div>
    );
}

/* ---------------------------------------------------------------- shared box */

const BOX =
    'border-input bg-background ring-offset-background placeholder:text-muted-foreground focus-visible:ring-ring flex h-10 w-full rounded-md border px-3 py-2 text-base tabular-nums focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-hidden disabled:cursor-not-allowed disabled:opacity-50 md:text-sm';

/**
 * One typed box with a picker button. Knows nothing about events: it gets the
 * current good value and reports a new good value ('' = cleared).
 */
interface FieldProps extends Omit<NativeProps, 'onChange' | 'value' | 'defaultValue' | 'min' | 'max' | 'name'> {
    kind: 'date' | 'month';
    current: string;
    onValue: (value: string) => void;
    min?: string;
    max?: string;
    inputRef?: React.Ref<HTMLInputElement>;
    onProblem?: (problem: string | null) => void;
}

function TypedField({ kind, current, onValue, min, max, inputRef, onProblem, className, disabled, readOnly, onBlur, placeholder, ...rest }: FieldProps) {
    const show = React.useCallback((v: string) => (kind === 'date' ? dmy(v) : my(v)), [kind]);
    const [text, setText] = React.useState(() => show(current));
    const [problem, setProblem] = React.useState<string | null>(null);
    const [open, setOpen] = React.useState(false);
    const last = React.useRef(current);
    const own = React.useRef<HTMLInputElement | null>(null);

    // A value set from outside (a reset, a preset button) replaces the text.
    React.useEffect(() => {
        if (current !== last.current) {
            last.current = current;
            setText(show(current));
            setProblem(null);
        }
    }, [current, show]);

    const report = React.useCallback(
        (next: string | null) => {
            setProblem(next);
            onProblem?.(next);
            own.current?.setCustomValidity(next ?? '');
        },
        [onProblem],
    );

    const rangeProblem = (value: string): string | null => {
        const lo = kind === 'date' ? min : min?.slice(0, 7);
        const hi = kind === 'date' ? max : max?.slice(0, 7);
        if (lo && value < lo) return `On or after ${show(lo)}`;
        if (hi && value > hi) return `On or before ${show(hi)}`;

        return null;
    };

    const commit = (value: string) => {
        if (value !== last.current) {
            last.current = value;
            onValue(value);
        }
    };

    const accept = (value: string) => {
        setText(show(value));
        report(rangeProblem(value));
        commit(value);
    };

    const handleChange = (raw: string) => {
        const masked = kind === 'date' ? maskDate(raw, text) : maskMonth(raw, text);
        setText(masked);
        if (masked === '') {
            report(null);
            commit('');

            return;
        }
        const value = kind === 'date' ? completeDate(masked) : /^\d{1,2}\/\d{4}$/.test(masked) ? parseMy(masked) : null;
        if (value) {
            report(rangeProblem(value));
            commit(value);
        } else {
            const finished = kind === 'date' ? /^\d{1,2}\/\d{1,2}\/\d{4}$/.test(masked) : /^\d{1,2}\/\d{4}$/.test(masked);
            report(finished ? 'Not a real date' : kind === 'date' ? 'Type DD/MM/YYYY' : 'Type MM/YYYY');
        }
    };

    const handleBlur = (event: React.FocusEvent<HTMLInputElement>) => {
        if (kind === 'date' && text) {
            const value = completeDate(text, true);
            if (value) accept(value);
            else if (last.current) accept(last.current);
        } else if (kind === 'month' && text) {
            const value = parseMy(text);
            if (value) accept(value);
            else if (last.current) accept(last.current);
        }
        onBlur?.(event);
    };

    const setRefs = (node: HTMLInputElement | null) => {
        own.current = node;
        if (typeof inputRef === 'function') inputRef(node);
        else if (inputRef) (inputRef as React.MutableRefObject<HTMLInputElement | null>).current = node;
    };

    return (
        <div className="relative w-full min-w-0">
            <input
                {...rest}
                ref={setRefs}
                type="text"
                inputMode="numeric"
                autoComplete="off"
                value={text}
                disabled={disabled}
                readOnly={readOnly}
                placeholder={placeholder ?? (kind === 'date' ? 'DD/MM/YYYY' : 'MM/YYYY')}
                maxLength={kind === 'date' ? 10 : 7}
                aria-invalid={problem ? true : rest['aria-invalid']}
                onChange={(e) => handleChange(e.target.value)}
                onBlur={handleBlur}
                onKeyDown={rest.onKeyDown}
                className={cn(BOX, 'pr-9', problem && 'border-destructive', className)}
            />
            <Popover open={open} onOpenChange={setOpen}>
                <PopoverTrigger asChild>
                    <button
                        type="button"
                        tabIndex={-1}
                        disabled={disabled || readOnly}
                        aria-label={kind === 'date' ? 'Pick a date' : 'Pick a month'}
                        className="text-muted-foreground hover:text-foreground absolute top-1/2 right-1 inline-flex size-7 -translate-y-1/2 items-center justify-center rounded-md disabled:pointer-events-none disabled:opacity-40"
                    >
                        <CalendarDays className="size-4" />
                    </button>
                </PopoverTrigger>
                <PopoverContent align="start" className="w-auto p-3">
                    {kind === 'date' ? (
                        <CalendarGrid
                            value={last.current}
                            min={min}
                            max={max}
                            onPick={(iso) => {
                                accept(iso);
                                setOpen(false);
                                own.current?.focus();
                            }}
                        />
                    ) : (
                        <MonthGrid
                            value={last.current}
                            min={min}
                            max={max}
                            onPick={(ym) => {
                                accept(ym);
                                setOpen(false);
                                own.current?.focus();
                            }}
                        />
                    )}
                </PopoverContent>
            </Popover>
            {problem && <p className="text-destructive absolute top-full left-0 z-10 mt-0.5 text-[11px] leading-tight whitespace-nowrap">{problem}</p>}
        </div>
    );
}


/** Controlled when `value` is given, else it keeps its own (a `defaultValue` form). */
function useValue(value: DateLikeInputProps['value'], defaultValue: DateLikeInputProps['defaultValue']) {
    const controlled = value !== undefined;
    const [own, setOwn] = React.useState(() => toText(defaultValue));

    return [controlled ? toText(value) : own, controlled ? undefined : setOwn] as const;
}

/* ---------------------------------------------------------------- public */

// ⚠ min(..., 100%): as wide as its content, but never wider than a narrow grid
// cell it sits in (a fixed w-64 hung out of a two-column dialog row).
const DEFAULT_WIDTH = { date: 'w-[min(10rem,100%)]', month: 'w-[min(8rem,100%)]', datetime: 'w-[min(16rem,100%)]' } as const;

export const DateInput = React.forwardRef<HTMLInputElement, DateLikeInputProps>(
    ({ value, defaultValue, onChange, name, min, max, className, form, required, ...rest }, ref) => {
        const [current, setOwn] = useValue(value, defaultValue);
        const { outer, inner } = splitClasses(className);

        return (
            <div className={cn('relative inline-flex max-w-full', DEFAULT_WIDTH.date, outer)} data-slot="date-input">
                <TypedField
                    {...rest}
                    kind="date"
                    required={required}
                    current={current}
                    min={min === undefined ? undefined : String(min)}
                    max={max === undefined ? undefined : String(max)}
                    inputRef={ref}
                    className={inner}
                    onValue={(next) => {
                        setOwn?.(next);
                        fireChange(onChange, next, name);
                    }}
                />
                {name && <input type="hidden" name={name} form={form} value={current} />}
            </div>
        );
    },
);
DateInput.displayName = 'DateInput';

export const MonthInput = React.forwardRef<HTMLInputElement, DateLikeInputProps>(
    ({ value, defaultValue, onChange, name, min, max, className, form, required, ...rest }, ref) => {
        const [current, setOwn] = useValue(value, defaultValue);
        const { outer, inner } = splitClasses(className);

        return (
            <div className={cn('relative inline-flex max-w-full', DEFAULT_WIDTH.month, outer)} data-slot="month-input">
                <TypedField
                    {...rest}
                    kind="month"
                    required={required}
                    current={current}
                    min={min === undefined ? undefined : String(min)}
                    max={max === undefined ? undefined : String(max)}
                    inputRef={ref}
                    className={inner}
                    onValue={(next) => {
                        setOwn?.(next);
                        fireChange(onChange, next, name);
                    }}
                />
                {name && <input type="hidden" name={name} form={form} value={current} />}
            </div>
        );
    },
);
MonthInput.displayName = 'MonthInput';

/** "1405", "14:5", "9:05" as "14:05" / "09:05"; null when not a real time. */
function parseTime(text: string): string | null {
    const match = /^(\d{1,2}):?(\d{2})$/.exec(banglaToLatin(text).trim());
    if (!match) return null;
    const [h, m] = [Number(match[1]), Number(match[2])];

    return h < 24 && m < 60 ? `${pad(h)}:${pad(m)}` : null;
}

/**
 * Date and time of day: DD/MM/YYYY plus HH:mm on a 24-hour clock. Value
 * contract = the native datetime-local one, `YYYY-MM-DDTHH:mm`, read as Dhaka
 * wall-clock time like the server reads it.
 */
export const DateTimeInput = React.forwardRef<HTMLInputElement, DateLikeInputProps>(
    ({ value, defaultValue, onChange, name, min, max, className, form, required, disabled, readOnly, id, onBlur, ...rest }, ref) => {
        const [current, setOwn] = useValue(value, defaultValue);
        const { outer, inner } = splitClasses(className);
        const datePart = current.slice(0, 10);
        const timePart = current.length >= 16 ? current.slice(11, 16) : '';
        const [timeText, setTimeText] = React.useState(timePart);
        const [pendingDate, setPendingDate] = React.useState(datePart);
        const [timeProblem, setTimeProblem] = React.useState<string | null>(null);
        const lastTime = React.useRef(timePart);

        React.useEffect(() => {
            setPendingDate(datePart);
            if (timePart !== lastTime.current) {
                lastTime.current = timePart;
                setTimeText(timePart);
            }
        }, [datePart, timePart]);

        const emit = (date: string, time: string) => {
            // Both halves make a value; no date means cleared; a date with no time yet waits.
            const next = date && time ? `${date}T${time}` : !date ? '' : null;
            if (next === null || next === current) return;
            setOwn?.(next);
            fireChange(onChange, next, name);
        };

        const minDate = min ? String(min).slice(0, 10) : undefined;
        const maxDate = max ? String(max).slice(0, 10) : undefined;

        return (
            <div className={cn('relative inline-flex max-w-full gap-1.5', DEFAULT_WIDTH.datetime, outer)} data-slot="datetime-input">
                <div className="min-w-0 flex-[3]">
                    <TypedField
                        {...rest}
                        kind="date"
                        id={id}
                        required={required}
                        disabled={disabled}
                        readOnly={readOnly}
                        current={pendingDate}
                        min={minDate}
                        max={maxDate}
                        inputRef={ref}
                        className={inner}
                        onBlur={onBlur}
                        onValue={(date) => {
                            setPendingDate(date);
                            emit(date, lastTime.current);
                        }}
                    />
                </div>
                <div className="relative min-w-0 flex-[2]">
                    <input
                        type="text"
                        inputMode="numeric"
                        autoComplete="off"
                        aria-label="Time (24 hour)"
                        placeholder="HH:MM"
                        maxLength={5}
                        required={required}
                        disabled={disabled}
                        readOnly={readOnly}
                        value={timeText}
                        onChange={(e) => {
                            let text = banglaToLatin(e.target.value).replace(/[^\d:]/g, '');
                            if (text.length > timeText.length && /^\d{2}$/.test(text)) text += ':';
                            if (/^\d{3,4}$/.test(text)) text = `${text.slice(0, 2)}:${text.slice(2)}`;
                            setTimeText(text);
                            const time = text === '' ? '' : /^\d{1,2}:\d{2}$/.test(text) ? parseTime(text) : null;
                            if (time === null) {
                                setTimeProblem('Type HH:MM (24 h)');
                                e.target.setCustomValidity('Type the time as HH:MM');

                                return;
                            }
                            setTimeProblem(null);
                            e.target.setCustomValidity('');
                            lastTime.current = time;
                            emit(pendingDate, time);
                        }}
                        onBlur={(e) => {
                            const time = parseTime(timeText);
                            if (time) {
                                setTimeText(time);
                                setTimeProblem(null);
                                e.target.setCustomValidity('');
                                lastTime.current = time;
                                emit(pendingDate, time);
                            } else if (timeText !== '' && lastTime.current) {
                                setTimeText(lastTime.current);
                                setTimeProblem(null);
                                e.target.setCustomValidity('');
                            }
                        }}
                        className={cn(BOX, 'px-2', timeProblem && 'border-destructive', inner)}
                    />
                    {timeProblem && (
                        <p className="text-destructive absolute top-full left-0 z-10 mt-0.5 text-[11px] leading-tight whitespace-nowrap">{timeProblem}</p>
                    )}
                </div>
                {name && <input type="hidden" name={name} form={form} value={current} />}
            </div>
        );
    },
);
DateTimeInput.displayName = 'DateTimeInput';
