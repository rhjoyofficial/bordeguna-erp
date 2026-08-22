import { Head } from '@inertiajs/react';

import AccentPicker from '@/components/accent-picker';
import AppearanceTabs from '@/components/appearance-tabs';
import HeadingSmall from '@/components/heading-small';
import SidebarColorPicker from '@/components/sidebar-color-picker';
import TopbarColorPicker from '@/components/topbar-color-picker';
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
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Appearance settings" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title="Appearance settings" description="Update your account's appearance settings" />
                    <AppearanceTabs />

                    <HeadingSmall title="Accent color" description="Pick the highlight color used for buttons, links and active menus" />
                    <AccentPicker />

                    <HeadingSmall title="Sidebar color" description="The navigation panel's background" />
                    <SidebarColorPicker />

                    <HeadingSmall title="Topbar color" description="The header bar's background" />
                    <TopbarColorPicker />
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
