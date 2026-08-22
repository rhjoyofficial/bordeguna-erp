import { useEffect, useState } from 'react';

export type Appearance = 'light' | 'dark' | 'system';

export type Accent = 'deep-green' | 'forest' | 'emerald' | 'slate' | 'black' | 'white';

export type SidebarColor = 'default' | 'light' | 'dark' | 'primary';

export type TopbarColor = 'default' | 'light' | 'dark' | 'primary';

/**
 * Accent presets the user can toggle in Settings → Appearance or the theme
 * customizer. Each maps to a [data-accent] CSS-variable block in app.css
 * ('deep-green' is the brand default and has no block). Swatches are for the
 * picker UI only - light/dark variants live in the CSS.
 */
export const accents: { value: Accent; label: string; swatch: string }[] = [
    { value: 'deep-green', label: 'Deep Green', swatch: 'hsl(103, 72%, 26%)' },
    { value: 'forest', label: 'Forest', swatch: 'hsl(152, 60%, 22%)' },
    { value: 'emerald', label: 'Emerald', swatch: 'hsl(161, 84%, 26%)' },
    { value: 'slate', label: 'Slate', swatch: 'hsl(215, 28%, 25%)' },
    { value: 'black', label: 'Black', swatch: 'hsl(224, 15%, 12%)' },
    { value: 'white', label: 'White', swatch: 'hsl(0, 0%, 100%)' },
];

/**
 * Sidebar/topbar surface presets, independent of accent and of light/dark
 * mode - each maps to a [data-sidebar-color] / [data-topbar-color] block in
 * app.css. 'default' has no block (the tinted-green surface, which already
 * switches with light/dark, applies untouched). 'primary' recolors the
 * surface from whatever accent is active, so its swatch below is only an
 * approximation shown in the picker.
 */
export const sidebarColors: { value: SidebarColor; label: string; swatch: string }[] = [
    { value: 'default', label: 'Default', swatch: 'hsl(108, 30%, 94.5%)' },
    { value: 'light', label: 'Light', swatch: 'hsl(0, 0%, 100%)' },
    { value: 'dark', label: 'Dark', swatch: 'hsl(222, 22%, 11%)' },
    { value: 'primary', label: 'Colored', swatch: 'hsl(103, 72%, 26%)' },
];

export const topbarColors: { value: TopbarColor; label: string; swatch: string }[] = [
    { value: 'default', label: 'Default', swatch: 'hsl(110, 32%, 96.5%)' },
    { value: 'light', label: 'Light', swatch: 'hsl(0, 0%, 100%)' },
    { value: 'dark', label: 'Dark', swatch: 'hsl(222, 22%, 11%)' },
    { value: 'primary', label: 'Colored', swatch: 'hsl(103, 72%, 26%)' },
];

const prefersDark = () => window.matchMedia('(prefers-color-scheme: dark)').matches;

const applyTheme = (appearance: Appearance) => {
    const isDark = appearance === 'dark' || (appearance === 'system' && prefersDark());

    document.documentElement.classList.toggle('dark', isDark);
};

// Shared shape for the three independent [data-*] overrides below - each
// preset either sets or removes one attribute on <html>, 'default'/the
// brand accent always meaning "remove it, let the base variables apply".
function makeDatasetApplier<T extends string>(key: string, defaultValue: T) {
    return (value: T) => {
        if (value === defaultValue) {
            delete document.documentElement.dataset[key];
        } else {
            document.documentElement.dataset[key] = value;
        }
    };
}

const applyAccent = makeDatasetApplier<Accent>('accent', 'deep-green');
const applySidebarColor = makeDatasetApplier<SidebarColor>('sidebarColor', 'default');
const applyTopbarColor = makeDatasetApplier<TopbarColor>('topbarColor', 'default');

function savedValue<T extends string>(storageKey: string, valid: readonly { value: T }[], fallback: T): T {
    const stored = localStorage.getItem(storageKey);

    return valid.some((option) => option.value === stored) ? (stored as T) : fallback;
}

const savedAccent = () => savedValue('accent', accents, 'deep-green' as Accent);
const savedSidebarColor = () => savedValue('sidebarColor', sidebarColors, 'default' as SidebarColor);
const savedTopbarColor = () => savedValue('topbarColor', topbarColors, 'default' as TopbarColor);

const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');

const handleSystemThemeChange = () => {
    const currentAppearance = localStorage.getItem('appearance') as Appearance;
    applyTheme(currentAppearance || 'system');
};

export function initializeTheme() {
    const savedAppearance = (localStorage.getItem('appearance') as Appearance) || 'system';

    applyTheme(savedAppearance);
    applyAccent(savedAccent());
    applySidebarColor(savedSidebarColor());
    applyTopbarColor(savedTopbarColor());

    // Add the event listener for system theme changes...
    mediaQuery.addEventListener('change', handleSystemThemeChange);
}

export function resetThemePreferences() {
    localStorage.removeItem('appearance');
    localStorage.removeItem('accent');
    localStorage.removeItem('sidebarColor');
    localStorage.removeItem('topbarColor');
    applyTheme('system');
    applyAccent('deep-green');
    applySidebarColor('default');
    applyTopbarColor('default');
}

export function useAppearance() {
    const [appearance, setAppearance] = useState<Appearance>('system');
    const [accent, setAccent] = useState<Accent>('deep-green');
    const [sidebarColor, setSidebarColor] = useState<SidebarColor>('default');
    const [topbarColor, setTopbarColor] = useState<TopbarColor>('default');

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

    const updateSidebarColor = (value: SidebarColor) => {
        setSidebarColor(value);
        localStorage.setItem('sidebarColor', value);
        applySidebarColor(value);
    };

    const updateTopbarColor = (value: TopbarColor) => {
        setTopbarColor(value);
        localStorage.setItem('topbarColor', value);
        applyTopbarColor(value);
    };

    const resetAll = () => {
        updateAppearance('system');
        updateAccent('deep-green');
        updateSidebarColor('default');
        updateTopbarColor('default');
    };

    useEffect(() => {
        const storedAppearance = localStorage.getItem('appearance') as Appearance | null;
        updateAppearance(storedAppearance || 'system');
        updateAccent(savedAccent());
        updateSidebarColor(savedSidebarColor());
        updateTopbarColor(savedTopbarColor());

        return () => mediaQuery.removeEventListener('change', handleSystemThemeChange);
    }, []);

    return {
        appearance,
        updateAppearance,
        accent,
        updateAccent,
        sidebarColor,
        updateSidebarColor,
        topbarColor,
        updateTopbarColor,
        resetAll,
    };
}
