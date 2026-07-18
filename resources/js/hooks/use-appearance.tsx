import { useEffect, useState } from 'react';

export type Appearance = 'light' | 'dark' | 'system';

export type Accent = 'deep-green' | 'forest' | 'emerald' | 'slate';

/**
 * Accent presets the user can toggle in Settings → Appearance. Each maps to a
 * [data-accent] CSS-variable block in app.css ('deep-green' is the brand
 * default and has no block). Swatches are for the picker UI only — light/dark
 * variants live in the CSS.
 */
export const accents: { value: Accent; label: string; swatch: string }[] = [
    { value: 'deep-green', label: 'Deep Green', swatch: 'hsl(103, 72%, 26%)' },
    { value: 'forest', label: 'Forest', swatch: 'hsl(152, 60%, 22%)' },
    { value: 'emerald', label: 'Emerald', swatch: 'hsl(161, 84%, 26%)' },
    { value: 'slate', label: 'Slate', swatch: 'hsl(215, 28%, 25%)' },
];

const prefersDark = () => window.matchMedia('(prefers-color-scheme: dark)').matches;

const applyTheme = (appearance: Appearance) => {
    const isDark = appearance === 'dark' || (appearance === 'system' && prefersDark());

    document.documentElement.classList.toggle('dark', isDark);
};

const applyAccent = (accent: Accent) => {
    // The default accent has no CSS block — drop the attribute entirely so the
    // base :root/.dark variables apply untouched.
    if (accent === 'deep-green') {
        delete document.documentElement.dataset.accent;
    } else {
        document.documentElement.dataset.accent = accent;
    }
};

const savedAccent = (): Accent => {
    const stored = localStorage.getItem('accent');

    return accents.some((a) => a.value === stored) ? (stored as Accent) : 'deep-green';
};

const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');

const handleSystemThemeChange = () => {
    const currentAppearance = localStorage.getItem('appearance') as Appearance;
    applyTheme(currentAppearance || 'system');
};

export function initializeTheme() {
    const savedAppearance = (localStorage.getItem('appearance') as Appearance) || 'system';

    applyTheme(savedAppearance);
    applyAccent(savedAccent());

    // Add the event listener for system theme changes...
    mediaQuery.addEventListener('change', handleSystemThemeChange);
}

export function useAppearance() {
    const [appearance, setAppearance] = useState<Appearance>('system');
    const [accent, setAccent] = useState<Accent>('deep-green');

    const updateAppearance = (mode: Appearance) => {
        setAppearance(mode);
        localStorage.setItem('appearance', mode);
        applyTheme(mode);
    };

    const updateAccent = (value: Accent) => {
        setAccent(value);
        localStorage.setItem('accent', value);
        applyAccent(value);
    };

    useEffect(() => {
        const storedAppearance = localStorage.getItem('appearance') as Appearance | null;
        updateAppearance(storedAppearance || 'system');
        updateAccent(savedAccent());

        return () => mediaQuery.removeEventListener('change', handleSystemThemeChange);
    }, []);

    return { appearance, updateAppearance, accent, updateAccent };
}
