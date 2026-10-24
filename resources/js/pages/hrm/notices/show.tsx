import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { my, showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

interface Props {
    notice: {
        id: number;
        employee: string | null;
        employee_code: string | null;
        subject: string;
        body: string;
        context: { window_label?: string; occurrence?: number; month?: string; dates?: string[] } | null;
        issued_by: string | null;
        issued_at: string;
        withdrawn_on?: string | null;
        withdraw_reason?: string | null;
    };
    business_name: string | null;
    back_url: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Show cause notice', href: '#' }];

/**
 * The printable copy - the browser's own print dialog is the download button
 * (save as PDF), so both HR and the employee keep an identical document with
 * no PDF machinery. Everything that is chrome disappears under print:hidden.
 */
export default function NoticeShow({ notice, business_name, back_url }: Props) {
    const { t } = useTranslation();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Show cause notice ${notice.id}`} />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex items-center justify-between print:hidden">
                    <Button variant="outline" size="sm" asChild>
                        <Link href={back_url}>Back</Link>
                    </Button>
                    <Button size="sm" onClick={() => window.print()}>
                        Print / save as PDF
                    </Button>
                </div>

                {/* ⭐ Corrections wave 3 (24 Oct 2026): a withdrawn notice says so, on screen and on paper. */}
                {notice.withdrawn_on && (
                    <div className="border-destructive/40 bg-destructive/5 mx-auto w-full max-w-2xl rounded-xl border p-3 text-sm">
                        <p className="font-medium">{t('corr.hrm.notice_withdrawn_banner', { date: notice.withdrawn_on })}</p>
                        {notice.withdraw_reason && <p className="text-muted-foreground mt-1">{notice.withdraw_reason}</p>}
                    </div>
                )}

                <div className="border-sidebar-border/70 dark:border-sidebar-border mx-auto w-full max-w-2xl rounded-xl border bg-white p-8 text-black dark:bg-white print:max-w-none print:border-0 print:p-0">
                    <div className="border-b border-black/20 pb-4 text-center">
                        <p className="text-xl font-semibold">{business_name}</p>
                        <p className="mt-1 text-sm tracking-widest uppercase">Show cause notice</p>
                    </div>

                    <div className="mt-6 flex justify-between text-sm">
                        <div>
                            <p>
                                To: <span className="font-medium">{notice.employee}</span>
                            </p>
                            <p className="text-black/60">{notice.employee_code}</p>
                        </div>
                        <p>Date: {showDate(notice.issued_at)}</p>
                    </div>

                    <p className="mt-6 text-sm font-semibold">Subject: {notice.subject}</p>

                    {notice.context?.window_label && (
                        <p className="mt-2 text-xs text-black/60">
                            Regarding: {notice.context.window_label}
                            {notice.context.occurrence ? `, occurrence ${notice.context.occurrence}` : ''}
                            {notice.context.month ? `, ${my(notice.context.month)}` : ''}
                            {notice.context.dates && notice.context.dates.length > 0 ? ` (${notice.context.dates.join(', ')})` : ''}
                        </p>
                    )}

                    <p className="mt-4 text-sm leading-relaxed whitespace-pre-wrap">{notice.body}</p>

                    <div className="mt-16 flex justify-between text-sm">
                        <div className="text-center">
                            <div className="w-48 border-t border-black/40 pt-1">Employee signature</div>
                        </div>
                        <div className="text-center">
                            <div className="w-48 border-t border-black/40 pt-1">
                                {notice.issued_by ?? 'Authorised signatory'}
                                <p className="text-xs text-black/60">For {business_name}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
