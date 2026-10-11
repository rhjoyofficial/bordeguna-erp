import { Head, useForm } from '@inertiajs/react';

import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboards', href: '/settings/dashboards' }];

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
    const form = useForm({
        assignments: roles.map((role) => ({ role_id: role.id, dashboard: role.dashboard as string | null })),
    });

    const labelOf = (value: string) => dashboards.find((d) => d.value === value)?.label ?? value;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboards" />
            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="Dashboards"
                        description="Which dashboard each role lands on in this business. Somebody with several roles lands on the highest one; holders of see-all access can open every dashboard, and those views are logged."
                    />

                    <ol className="text-muted-foreground grid gap-1 text-sm sm:grid-cols-2">
                        {dashboards.map((d, index) => (
                            <li key={d.value}>
                                <span className="text-foreground font-medium">
                                    {index + 1}. {d.label}
                                </span>{' '}
                                {d.description}
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
                                        <th className="p-3 font-medium">Role</th>
                                        <th className="p-3 font-medium">People</th>
                                        <th className="p-3 font-medium">Lands on</th>
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
                                                    aria-label={`Dashboard for ${role.name}`}
                                                    onChange={(e) => {
                                                        const next = [...form.data.assignments];
                                                        next[index] = { ...next[index], dashboard: e.target.value === '' ? null : e.target.value };
                                                        form.setData('assignments', next);
                                                    }}
                                                >
                                                    <option value="">Automatic (from its permissions: {labelOf(role.automatic)})</option>
                                                    {dashboards.map((d) => (
                                                        <option key={d.value} value={d.value}>
                                                            {d.label}
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
                            Save
                        </Button>
                    </form>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
