import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

import AccentPicker from '@/components/accent-picker';
import AppearanceTabs from '@/components/appearance-tabs';
import HeadingSmall from '@/components/heading-small';
import SidebarColorPicker from '@/components/sidebar-color-picker';
import TopbarColorPicker from '@/components/topbar-color-picker';
import { Button } from '@/components/ui/button';
import { resetThemePreferences } from '@/hooks/use-appearance';
import { useTranslation } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';

import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Appearance settings',
        href: '/settings/appearance',
    },
];

export default function Appearance() {
    // Bumped on reset to remount the pickers below. Each owns its own
    // useAppearance() instance and local state, so a reset that only touches
    // localStorage and the DOM would not otherwise be reflected in their
    // active swatch until the next navigation.
    const [resetKey, setResetKey] = useState(0);
    const { t, locale } = useTranslation();

    const handleReset = () => {
        resetThemePreferences();
        setResetKey((count) => count + 1);
    };

    // C-I18N-5: account-level, unlike the theme - it follows the person to
    // any device. The page reloads through Inertia so every label switches at
    // once.
    const setLocale = (next: 'en' | 'bn') => router.patch('/settings/locale', { locale: next }, { preserveScroll: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Appearance settings" />

            <SettingsLayout narrow>
                <div key={resetKey} className="space-y-6">
                    <HeadingSmall title={t('language.heading')} description={t('language.description')} />
                    <div className="flex gap-2">
                        <Button variant={locale === 'en' ? 'default' : 'outline'} size="sm" onClick={() => setLocale('en')}>
                            English
                        </Button>
                        <Button variant={locale === 'bn' ? 'default' : 'outline'} size="sm" onClick={() => setLocale('bn')}>
                            বাংলা
                        </Button>
                    </div>

                    <HeadingSmall title="Appearance settings" description="Personal to your account, saved to this browser" />
                    <AppearanceTabs />

                    <HeadingSmall title="Accent color" description="Pick the highlight color used for buttons, links and active menus" />
                    <AccentPicker />

                    <HeadingSmall title="Sidebar color" description="The navigation panel's background" />
                    <SidebarColorPicker />

                    <HeadingSmall title="Topbar color" description="The header bar's background" />
                    <TopbarColorPicker />

                    <div className="border-t pt-6">
                        <Button variant="outline" onClick={handleReset}>
                            Reset to defaults
                        </Button>
                    </div>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
