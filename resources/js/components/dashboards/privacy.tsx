import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { Eye, EyeOff } from 'lucide-react';
import { AnimatePresence, motion, useReducedMotion, type HTMLMotionProps } from 'motion/react';
import { createContext, Fragment, useCallback, useContext, useMemo, useState, type ReactNode } from 'react';
import type { Unit } from './types';

/**
 * The privacy mask on the role dashboards (Joy, 14 Oct 2026: "on my dashboard
 * many private and important data shows, so there needs some privacy").
 *
 * A card the server marks `sensitive` (Widget::sensitive, every money widget)
 * shows the word أمان (Aman: safety, protection) in place of each money and
 * percent figure. Its eye button reveals that card; the toolbar eye reveals or
 * hides every card at once. Counts (tasks, approvals, parcels) stay readable.
 *
 * ⚠ Presentation only, against a glance over the shoulder: the person is
 * allowed to see the figure and the browser already holds it. What matters is
 * that a masked figure is in no visible text, title or aria-label (a screen
 * reader hears "hidden figure"), and is not rendered at all (no count-up).
 *
 * ⚠ The reveal state lives in sessionStorage, per person: it survives a
 * refresh in the same tab, and a fresh login or a new tab starts masked again,
 * which is the point. Every read and write is in try/catch (private windows).
 *
 * ui-motion: Value Change. Word and number swap with a quick blur-to-sharp,
 * a touch of scale and a crossfade (about 0.3 s in all, mode "wait" so the
 * two never overlap); under reduced motion they simply swap. The figure sits
 * in a one-line box (h-[1lh]) in both states, so the row never jumps.
 */

/** The mask word. Fixed text, never a dictionary string. */
export const AMAN = 'أمان';

/** The units that are private on a sensitive card. */
export const maskable = (unit: Unit | undefined) => unit === 'money' || unit === 'percent';

const storageKey = (userId: number | string) => `dash.privacy.revealed.${userId}`;

function readRevealed(userId: number | string): string[] {
    try {
        const raw = window.sessionStorage.getItem(storageKey(userId));
        const parsed: unknown = raw ? JSON.parse(raw) : [];

        return Array.isArray(parsed) ? parsed.filter((k): k is string => typeof k === 'string') : [];
    } catch {
        return [];
    }
}

function writeRevealed(userId: number | string, keys: string[]): void {
    try {
        window.sessionStorage.setItem(storageKey(userId), JSON.stringify(keys));
    } catch {
        // Storage refused: the choice simply lasts until the page is left.
    }
}

interface Privacy {
    /** The sensitive widget keys on this page. */
    sensitive: string[];
    isMasked: (key: string) => boolean;
    toggle: (key: string) => void;
    /** True when every sensitive card is revealed. */
    allRevealed: boolean;
    toggleAll: () => void;
}

const PrivacyContext = createContext<Privacy>({
    sensitive: [],
    isMasked: () => false,
    toggle: () => {},
    allRevealed: true,
    toggleAll: () => {},
});

export function PrivacyProvider({ userId, sensitive, children }: { userId: number | string; sensitive: string[]; children: ReactNode }) {
    const [revealed, setRevealed] = useState<string[]>(() => (typeof window === 'undefined' ? [] : readRevealed(userId)));

    const save = useCallback(
        (next: string[]) => {
            setRevealed(next);
            writeRevealed(userId, next);
        },
        [userId],
    );

    const value = useMemo<Privacy>(() => {
        const allRevealed = sensitive.every((key) => revealed.includes(key));

        return {
            sensitive,
            isMasked: (key) => sensitive.includes(key) && !revealed.includes(key),
            toggle: (key) => save(revealed.includes(key) ? revealed.filter((k) => k !== key) : [...revealed, key]),
            allRevealed,
            toggleAll: () => save(allRevealed ? revealed.filter((k) => !sensitive.includes(k)) : [...new Set([...revealed, ...sensitive])]),
        };
    }, [sensitive, revealed, save]);

    return <PrivacyContext.Provider value={value}>{children}</PrivacyContext.Provider>;
}

export const usePrivacy = () => useContext(PrivacyContext);

/** Whether the card being drawn is masked right now (set by WidgetCard and the hero tile). */
const CardMask = createContext(false);

export function CardMaskProvider({ masked, children }: { masked: boolean; children: ReactNode }) {
    return <CardMask.Provider value={masked}>{children}</CardMask.Provider>;
}

/** Whether a figure in this unit is hidden on the current card. */
export function useFigureMasked(unit: Unit | undefined): boolean {
    return useContext(CardMask) && maskable(unit);
}

const EASE = [0.22, 1, 0.36, 1] as const;

/** Word and number swap: blur to sharp with a slight scale (Value Change). */
function swapMotion(reduce: boolean | null): HTMLMotionProps<'span'> {
    return reduce
        ? { initial: false, animate: { opacity: 1 }, exit: { opacity: 0, transition: { duration: 0 } } }
        : {
              initial: { opacity: 0, scale: 0.92, filter: 'blur(8px)' },
              animate: { opacity: 1, scale: 1, filter: 'blur(0px)', transition: { duration: 0.22, ease: EASE } },
              exit: { opacity: 0, scale: 1.05, filter: 'blur(6px)', transition: { duration: 0.11, ease: 'easeIn' } },
          };
}

/** أمان itself, with what a screen reader says instead. */
export function AmanWord({ className }: { className?: string }) {
    const { t } = useTranslation();

    return (
        <>
            <span lang="ar" dir="rtl" aria-hidden className={cn('aman-mask opacity-80', className)}>
                {AMAN}
            </span>
            <span className="sr-only">{t('dash.privacy.hidden_figure')}</span>
        </>
    );
}

/** Shows `children` or أمان, swapping with the reveal motion. */
export function MaskSwap({ hidden, children, className }: { hidden: boolean; children: ReactNode; className?: string }) {
    const reduce = useReducedMotion();
    const swap = swapMotion(reduce);

    return (
        <span className={cn('inline-flex h-[1lh] items-center align-top', className)}>
            <AnimatePresence mode="wait" initial={false}>
                {hidden ? (
                    <motion.span key="aman" className="inline-block origin-center" {...swap}>
                        <AmanWord />
                    </motion.span>
                ) : (
                    <motion.span key="figure" className="inline-block origin-center" {...swap}>
                        {children}
                    </motion.span>
                )}
            </AnimatePresence>
        </span>
    );
}

/** A figure in `unit`: masked when the card is masked and the unit is private. */
export function Masked({ unit, children, className }: { unit: Unit | undefined; children: ReactNode; className?: string }) {
    return (
        <MaskSwap hidden={useFigureMasked(unit)} className={className}>
            {children}
        </MaskSwap>
    );
}

/**
 * A translated sentence with a figure in it ("of Tk 5,000 target"): the
 * figure is drawn as `figure`, so it can be masked inside the words.
 */
export function WithFigure({ text, figure }: { text: string; figure: ReactNode }) {
    const parts = text.split(FIGURE_SLOT);

    return (
        <>
            {parts.map((part, index) => (
                <Fragment key={index}>
                    {part}
                    {index < parts.length - 1 && figure}
                </Fragment>
            ))}
        </>
    );
}

/** Put this in a translation's placeholder, then hand the text to WithFigure. */
export const FIGURE_SLOT = '\u0000';

/** The card's eye: reveals or hides this card's figures. */
export function PrivacyToggle({ widgetKey, className, title }: { widgetKey: string; className?: string; title?: string }) {
    const { t } = useTranslation();
    const { isMasked, toggle } = usePrivacy();
    const masked = isMasked(widgetKey);
    const label = masked ? t('dash.privacy.show') : t('dash.privacy.hide');

    return (
        <button
            type="button"
            onClick={(event) => {
                event.preventDefault();
                event.stopPropagation();
                toggle(widgetKey);
            }}
            aria-pressed={!masked}
            aria-label={title ? `${label}: ${title}` : label}
            title={label}
            className={className}
        >
            <EyeIcon open={!masked} />
        </button>
    );
}

/** The eye, turning quickly between open and shut. */
export function EyeIcon({ open, className = 'size-3.5' }: { open: boolean; className?: string }) {
    const reduce = useReducedMotion();

    return (
        <AnimatePresence mode="wait" initial={false}>
            <motion.span
                key={open ? 'open' : 'shut'}
                className="inline-flex"
                initial={reduce ? false : { opacity: 0, scale: 0.6, rotate: -20 }}
                animate={{ opacity: 1, scale: 1, rotate: 0 }}
                exit={reduce ? { opacity: 0, transition: { duration: 0 } } : { opacity: 0, scale: 0.6, rotate: 20 }}
                transition={{ duration: 0.14, ease: EASE }}
            >
                {open ? <Eye className={className} aria-hidden /> : <EyeOff className={className} aria-hidden />}
            </motion.span>
        </AnimatePresence>
    );
}
