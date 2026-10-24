import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

interface NoticeRow {
    id: number;
    subject: string;
    issued_by: string | null;
    issued_at: string;
    withdrawn_on?: string | null;
}

interface Props {
    notices: NoticeRow[];
}

export default function MyNotices({ notices }: Props) {
    // HRM-007 (14 Oct 2026): staff read this page in their own language.
    const { t } = useTranslation();
    const breadcrumbs: BreadcrumbItem[] = [{ title: t('my_notices.title'), href: '/hrm/my-notices' }];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('my_notices.title')} />
            <div className="flex flex-col gap-4 p-4">
                <div>
                    <h2 className="text-lg font-medium">{t('my_notices.title')}</h2>
                </div>

                <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-3">
                    {notices.length === 0 ? (
                        <p className="text-muted-foreground p-2 text-sm">{t('my_notices.empty')}</p>
                    ) : (
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-b text-left">
                                    <th className="py-1.5">{t('my_notices.issued')}</th>
                                    <th className="py-1.5">{t('my_notices.subject')}</th>
                                    <th className="py-1.5">{t('my_notices.issued_by')}</th>
                                    <th className="py-1.5"></th>
                                </tr>
                            </thead>
                            <tbody>
                                {notices.map((notice) => (
                                    <tr key={notice.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                        <td className="py-1.5 whitespace-nowrap">{showDate(notice.issued_at)}</td>
                                        <td className="py-1.5">
                                            {notice.subject}
                                            {notice.withdrawn_on && (
                                                <span className="text-muted-foreground ml-2 text-xs">
                                                    {t('corr.hrm.withdrawn_on_date', { date: notice.withdrawn_on })}
                                                </span>
                                            )}
                                        </td>
                                        <td className="text-muted-foreground py-1.5">{notice.issued_by}</td>
                                        <td className="py-1.5 text-right">
                                            <Button size="sm" variant="outline" asChild>
                                                <Link href={route('hrm.my-notices.show', notice.id)}>{t('my_notices.view_print')}</Link>
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
