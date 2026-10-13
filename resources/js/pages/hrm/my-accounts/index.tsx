import { LoginsCard, type LoginsCardData } from '@/components/assets/logins-card';
import SelfServiceTabs from '@/components/self-service-tabs';
import { Badge } from '@/components/ui/badge';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

interface MyAccount {
    id: number;
    kind_label: string;
    name: string;
    provider: string | null;
    identifier: string | null;
    url: string | null;
    responsible: string | null;
    business: string | null;
    role_label: string;
    since: string;
    status_label: string;
    credentials: LoginsCardData | null;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'My profile', href: '/hrm/my-profile' },
    { title: 'My accounts', href: '/hrm/my-accounts' },
];

/**
 * The accounts the signed-in employee has access to (slice 4), with their
 * logins under the reveal policy. Nothing is changed here; access is granted
 * and revoked in the hub.
 */
export default function MyAccounts({ accounts }: { accounts: MyAccount[] }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="My accounts" />
            <div className="px-4 pt-4">
                <SelfServiceTabs active="/hrm/my-accounts" />
            </div>
            <div className="flex flex-col gap-4 p-4">
                <div>
                    <h2 className="text-lg font-medium">Accounts I have access to</h2>
                </div>

                {accounts.length === 0 ? (
                    <p className="text-muted-foreground rounded-lg border p-6 text-center text-sm">No account access is recorded for you.</p>
                ) : (
                    <ul className="grid gap-3">
                        {accounts.map((account) => (
                            <li key={account.id} className="grid gap-3 rounded-lg border p-3 text-sm">
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <div className="min-w-0">
                                        <p className="font-medium">{account.name}</p>
                                        <p className="text-muted-foreground text-xs break-all">
                                            {account.kind_label}
                                            {account.provider && ` - ${account.provider}`}
                                            {account.identifier && ` - ${account.identifier}`}
                                            {account.business && ` - ${account.business}`}
                                        </p>
                                        <p className="text-muted-foreground text-xs">
                                            {account.role_label} since {account.since}
                                            {account.responsible && ` - responsible: ${account.responsible}`}
                                        </p>
                                        {account.url && (
                                            <a href={account.url} target="_blank" rel="noreferrer" className="text-xs underline underline-offset-2">
                                                {account.url}
                                            </a>
                                        )}
                                    </div>
                                    <Badge variant="outline">{account.status_label}</Badge>
                                </div>
                                {account.credentials && (account.credentials.rows.length > 0 || account.credentials.can_manage) && (
                                    <div className="border-t pt-3">
                                        <LoginsCard
                                            data={account.credentials}
                                            storeUrl={`/assets/accounts/${account.id}/credentials`}
                                            title="Its logins"
                                        />
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </AppLayout>
    );
}
