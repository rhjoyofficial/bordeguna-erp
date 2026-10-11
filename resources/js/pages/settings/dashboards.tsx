import { Head, useForm } from '@inertiajs/react';

import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { useTranslation } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';

interface RoleRow {
    id: number;
    name: string;
    users: number;
    dashboard: string | null;
    automatic: string;
}

interface Option {
    value: string;
    label: string;
    description: string;
}

/**
 * Settings > Dashboards (Owner only): which dashboard each role of this
 * business lands on. A person with several roles lands on the highest one;
 * everyone can always open their own employee dashboard.
 */
export default function DashboardSettings({ roles, dashboards }: { roles: RoleRow[]; dashboards: Option[] }) {
    const { t, tr } = useTranslation();
    const breadcrumbs: BreadcrumbItem[] = [{ title: t('dash.settings.title'), href: '/settings/dashboards' }];
    const form = useForm({
        assignments: roles.map((role) => ({ role_id: role.id, dashboard: role.dashboard as string | null })),
    });

    const labelOf = (value: string) => tr(`dash.name.${value}`, dashboards.find((d) => d.value === value)?.label ?? value);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('dash.settings.title')} />
            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title={t('dash.settings.title')} description={t('dash.settings.description')} />

                    <ol className="text-muted-foreground grid gap-1 text-sm sm:grid-cols-2">
                        {dashboards.map((d, index) => (
                            <li key={d.value}>
                                <span className="text-foreground font-medium">
                                    {index + 1}. {labelOf(d.value)}
                                </span>{' '}
                                {tr(`dash.description.${d.value}`, d.description)}
                            </li>
                        ))}
                    </ol>

                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.put('/settings/dashboards', { preserveScroll: true });
                        }}
                        className="space-y-4"
                    >
                        <div className="overflow-x-auto rounded-lg border">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="text-muted-foreground border-b text-left text-xs">
                                        <th className="p-3 font-medium">{t('dash.settings.role')}</th>
                                        <th className="p-3 font-medium">{t('dash.settings.people')}</th>
                                        <th className="p-3 font-medium">{t('dash.settings.lands_on')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {roles.map((role, index) => (
                                        <tr key={role.id} className="border-b last:border-0">
                                            <td className="p-3 font-medium">{role.name}</td>
                                            <td className="text-muted-foreground p-3">{role.users}</td>
                                            <td className="p-3">
                                                <select
                                                    className="border-input bg-background h-9 w-full max-w-md rounded-md border px-2 text-sm"
                                                    value={form.data.assignments[index].dashboard ?? ''}
                                                    aria-label={t('dash.settings.dashboard_for', { name: role.name })}
                                                    onChange={(e) => {
                                                        const next = [...form.data.assignments];
                                                        next[index] = { ...next[index], dashboard: e.target.value === '' ? null : e.target.value };
                                                        form.setData('assignments', next);
                                                    }}
                                                >
                                                    <option value="">{t('dash.settings.automatic', { name: labelOf(role.automatic) })}</option>
                                                    {dashboards.map((d) => (
                                                        <option key={d.value} value={d.value}>
                                                            {labelOf(d.value)}
                                                        </option>
                                                    ))}
                                                </select>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <Button type="submit" disabled={form.processing}>
                            {t('dash.save')}
                        </Button>
                    </form>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
