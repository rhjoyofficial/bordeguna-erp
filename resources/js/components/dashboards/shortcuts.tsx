import { useTranslation } from '@/lib/i18n';
import { Link } from '@inertiajs/react';

export interface Shortcut {
    key: string;
    label: string;
    url: string;
}

/**
 * The Shortcuts row (redesign round 3, Joy, 16 Oct 2026): links only, built
 * and gated on the server (App\Support\Dashboards\DashboardShortcuts), so a
 * shortcut the person may not open never reaches the page. Each button is as
 * wide as its words.
 */
export function Shortcuts({ shortcuts }: { shortcuts: Shortcut[] }) {
    const { t, tr } = useTranslation();

    if (shortcuts.length === 0) {
        return null;
    }

    return (
        <section aria-labelledby="dash-shortcuts">
            <h2 id="dash-shortcuts" className="text-muted-foreground mb-2 text-[13px] font-semibold tracking-[0.05em] uppercase">
                {t('dash.shortcuts.title')}
            </h2>
            <div className="flex flex-wrap gap-2">
                {shortcuts.map((shortcut) => (
                    <Link
                        key={shortcut.key}
                        href={shortcut.url}
                        className="bg-card hover:bg-muted inline-flex min-h-9 items-center rounded-lg border px-3 text-sm font-medium transition-colors"
                    >
                        {tr(`dash.shortcut.${shortcut.key}`, shortcut.label)}
                    </Link>
                ))}
            </div>
        </section>
    );
}
