import SelfServiceTabs from '@/components/self-service-tabs';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

interface NoticeRow {
    id: number;
    subject: string;
    issued_by: string | null;
    issued_at: string;
}

interface Props {
    notices: NoticeRow[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'My notices', href: '/hrm/my-notices' }];

export default function MyNotices({ notices }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="My notices" />
            <div className="px-4 pt-4">
                <SelfServiceTabs active="/hrm/my-notices" />
            </div>
            <div className="flex flex-col gap-4 p-4">
                <div>
                    <h2 className="text-lg font-medium">My notices</h2>
                </div>

                <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-3">
                    {notices.length === 0 ? (
                        <p className="text-muted-foreground p-2 text-sm">No notices - nothing on record.</p>
                    ) : (
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-b text-left">
                                    <th className="py-1.5">Issued</th>
                                    <th className="py-1.5">Subject</th>
                                    <th className="py-1.5">Issued by</th>
                                    <th className="py-1.5"></th>
                                </tr>
                            </thead>
                            <tbody>
                                {notices.map((notice) => (
                                    <tr key={notice.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                        <td className="py-1.5 whitespace-nowrap">{notice.issued_at}</td>
                                        <td className="py-1.5">{notice.subject}</td>
                                        <td className="text-muted-foreground py-1.5">{notice.issued_by}</td>
                                        <td className="py-1.5 text-right">
                                            <Button size="sm" variant="outline" asChild>
                                                <Link href={route('hrm.my-notices.show', notice.id)}>View / print</Link>
                                            </Button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
