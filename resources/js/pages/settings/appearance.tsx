import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import AccentPicker from '@/components/accent-picker';
import AppearanceTabs from '@/components/appearance-tabs';
import HeadingSmall from '@/components/heading-small';
import SidebarColorPicker from '@/components/sidebar-color-picker';
import TopbarColorPicker from '@/components/topbar-color-picker';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { resetThemePreferences } from '@/hooks/use-appearance';
import { useTranslation } from '@/lib/i18n';
import { type BreadcrumbItem, type SharedData } from '@/types';

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

    // The Bismillah greeting: account-level like the language, saved at once.
    const greetingOn = usePage<SharedData>().props.bismillah?.enabled ?? true;
    const [greetingBusy, setGreetingBusy] = useState(false);
    const setGreeting = (enabled: boolean) => {
        setGreetingBusy(true);
        router.patch('/settings/greeting', { enabled }, { preserveScroll: true, onFinish: () => setGreetingBusy(false) });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Appearance settings" />

            <SettingsLayout narrow>
                <div key={resetKey} className="space-y-6">
                    <HeadingSmall title={t('language.heading')} />
                    <div className="flex gap-2">
                        <Button variant={locale === 'en' ? 'default' : 'outline'} size="sm" onClick={() => setLocale('en')}>
                            English
                        </Button>
                        <Button variant={locale === 'bn' ? 'default' : 'outline'} size="sm" onClick={() => setLocale('bn')}>
                            বাংলা
                        </Button>
                    </div>

                    <HeadingSmall title={t('bismillah.setting_heading')} />
                    <div className="flex items-start gap-3">
                        <Checkbox
                            id="bismillah-greeting"
                            className="mt-0.5"
                            checked={greetingOn}
                            disabled={greetingBusy}
                            onCheckedChange={(value) => setGreeting(value === true)}
                            aria-describedby="bismillah-greeting-hint"
                        />
                        <div className="grid gap-1">
                            <Label htmlFor="bismillah-greeting">{t('bismillah.setting_label')}</Label>
                            <p id="bismillah-greeting-hint" className="text-muted-foreground text-sm">
                                {t('bismillah.setting_hint')}
                            </p>
                        </div>
                    </div>

                    <HeadingSmall title="Appearance settings" />
                    <AppearanceTabs />

                    <HeadingSmall title="Accent color" />
                    <AccentPicker />

                    <HeadingSmall title="Sidebar color" />
                    <SidebarColorPicker />

                    <HeadingSmall title="Topbar color" />
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
