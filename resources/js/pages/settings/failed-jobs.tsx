import { Head, router } from '@inertiajs/react';

import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { useTranslation } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';

interface FailedJob {
    id: number;
    uuid: string;
    queue: string;
    connection: string;
    job: string;
    exception_class: string;
    /** The first lines only, with anything shaped like a secret already hidden on the server. */
    exception: string;
    failed_at: string | null;
}

/**
 * Settings > Failed jobs (Owner, system.jobs.manage): background jobs that
 * failed, what they were and why, with Retry, Forget and Retry all.
 */
export default function FailedJobs({ jobs, total }: { jobs: FailedJob[]; total: number }) {
    const { t } = useTranslation();
    const breadcrumbs: BreadcrumbItem[] = [{ title: t('failed_jobs.title'), href: '/settings/failed-jobs' }];
    const options = { preserveScroll: true };

    const retry = (job: FailedJob) => {
        if (window.confirm(t('failed_jobs.retry_confirm'))) {
            router.post(`/settings/failed-jobs/${job.uuid}/retry`, {}, options);
        }
    };

    const forget = (job: FailedJob) => {
        if (window.confirm(t('attention.forget_confirm'))) {
            router.delete(`/settings/failed-jobs/${job.uuid}`, options);
        }
    };

    const retryAll = () => {
        if (window.confirm(t('failed_jobs.retry_all_confirm'))) {
            router.post('/settings/failed-jobs/retry-all', {}, options);
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('failed_jobs.title')} />
            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title={t('failed_jobs.title')} description={t('failed_jobs.description')} />
                    <p className="text-muted-foreground text-xs">{t('failed_jobs.system_wide')}</p>

                    {jobs.length === 0 ? (
                        <div className="rounded-lg border border-emerald-500/40 bg-emerald-500/5 p-6 text-center text-sm">
                            {t('failed_jobs.none')}
                        </div>
                    ) : (
                        <>
                            <div className="flex items-center justify-between gap-3">
                                <p className="text-sm font-medium">{t('failed_jobs.count', { count: total })}</p>
                                <Button size="sm" onClick={retryAll}>
                                    {t('failed_jobs.retry_all')}
                                </Button>
                            </div>
                            <ul className="divide-y rounded-lg border">
                                {jobs.map((job) => (
                                    <li key={job.uuid} className="space-y-2 p-3">
                                        <div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                            <div className="min-w-0 space-y-0.5 text-sm">
                                                <p className="font-medium break-all">{job.job}</p>
                                                <p className="text-muted-foreground text-xs">
                                                    {t('failed_jobs.col.failed_at')}:{' '}
                                                    {job.failed_at ? new Date(job.failed_at).toLocaleString('en-GB') : '-'} ·{' '}
                                                    {t('failed_jobs.col.queue')}: {job.queue}
                                                </p>
                                                <p className="text-xs break-all text-red-700 dark:text-red-300">
                                                    {t('failed_jobs.col.error')}: {job.exception_class}
                                                </p>
                                            </div>
                                            <div className="flex shrink-0 gap-2">
                                                <Button size="sm" variant="outline" onClick={() => retry(job)}>
                                                    {t('attention.retry')}
                                                </Button>
                                                <Button size="sm" variant="ghost" onClick={() => forget(job)}>
                                                    {t('attention.forget')}
                                                </Button>
                                            </div>
                                        </div>
                                        <details className="text-xs">
                                            <summary className="text-muted-foreground cursor-pointer">{t('failed_jobs.show_details')}</summary>
                                            <pre className="bg-muted mt-2 overflow-x-auto rounded p-2 whitespace-pre-wrap">{job.exception}</pre>
                                        </details>
                                    </li>
                                ))}
                            </ul>
                        </>
                    )}
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
