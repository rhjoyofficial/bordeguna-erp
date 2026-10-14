import { animate, useReducedMotion } from 'motion/react';
import { useEffect, useRef, useState } from 'react';
import { formatValue } from './format';
import type { Unit } from './types';

/**
 * A figure that counts to its new value when it CHANGES.
 *
 * ui-motion: Value Change (continuity) - the reader sees the number move from
 * what it was to what it is, so a change is noticed rather than missed.
 * ⚠ It never delays reading a number: the first render shows the real value at
 * once (no count up from zero), an update takes 0.6 s from the old value, and
 * with reduced motion it simply swaps.
 *
 * 14 Oct 2026, privacy: a masked figure (privacy.tsx) does not render this at
 * all, so nothing counts behind أمان; on reveal it mounts fresh and shows the
 * final value at once, then counts only on later live changes.
 */
export function AnimatedNumber({
    value,
    unit,
    className,
    compact = false,
    flash: flashColours = true,
}: {
    value: number;
    unit: Unit;
    className?: string;
    compact?: boolean;
    /** Off on a coloured surface (the hero), where the green and amber flash would lose contrast. */
    flash?: boolean;
}) {
    const reduce = useReducedMotion();
    const [shown, setShown] = useState(value);
    const previous = useRef(value);
    const [flash, setFlash] = useState<'up' | 'down' | null>(null);

    useEffect(() => {
        const from = previous.current;
        previous.current = value;

        if (from === value) {
            return;
        }

        setFlash(value > from ? 'up' : 'down');
        const clear = window.setTimeout(() => setFlash(null), 1200);

        if (reduce) {
            setShown(value);

            return () => window.clearTimeout(clear);
        }

        const controls = animate(from, value, {
            duration: 0.6,
            ease: [0.22, 1, 0.36, 1],
            onUpdate: (latest) => setShown(latest),
        });

        return () => {
            controls.stop();
            window.clearTimeout(clear);
            setShown(value);
        };
    }, [value, reduce]);

    return (
        <span
            className={[
                'tabular-nums transition-colors duration-700',
                !flashColours
                    ? ''
                    : flash === 'up'
                      ? 'text-emerald-700 dark:text-emerald-400'
                      : flash === 'down'
                        ? 'text-amber-700 dark:text-amber-400'
                        : '',
                className ?? '',
            ].join(' ')}
        >
            {formatValue(unit === 'count' ? Math.round(shown) : shown, unit, compact)}
        </span>
    );
}
