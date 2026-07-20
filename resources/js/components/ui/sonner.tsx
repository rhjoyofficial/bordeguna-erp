import { useEffect, useState } from 'react';
import { Toaster as Sonner, type ToasterProps } from 'sonner';

// Mirror the app's explicit light/dark toggle (the `.dark` class on <html>,
// managed by use-appearance) rather than sonner's own prefers-color-scheme
// detection, so toasts match whatever theme the user actually selected.
function useHtmlTheme(): 'light' | 'dark' {
    const [theme, setTheme] = useState<'light' | 'dark'>(() =>
        typeof document !== 'undefined' && document.documentElement.classList.contains('dark') ? 'dark' : 'light',
    );

    useEffect(() => {
        const el = document.documentElement;
        const observer = new MutationObserver(() => {
            setTheme(el.classList.contains('dark') ? 'dark' : 'light');
        });
        observer.observe(el, { attributes: true, attributeFilter: ['class'] });
        return () => observer.disconnect();
    }, []);

    return theme;
}

export function Toaster(props: ToasterProps) {
    const theme = useHtmlTheme();

    return (
        <Sonner
            theme={theme}
            position="bottom-right"
            richColors
            closeButton
            style={
                {
                    '--normal-bg': 'var(--popover)',
                    '--normal-text': 'var(--popover-foreground)',
                    '--normal-border': 'var(--border)',
                } as React.CSSProperties
            }
            {...props}
        />
    );
}
