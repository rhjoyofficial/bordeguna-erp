<?php

namespace App\Support\Attention\Sources;

use App\Models\User;
use App\Support\Attention\AttentionItem;
use App\Support\FailedJobs;

/**
 * Background jobs that failed and will not run again on their own: a
 * notification never sent, a sync never finished. Owner only
 * (system.jobs.manage), with Retry and Forget for the latest one right here.
 */
class FailedJobsSource extends Source
{
    public function key(): string
    {
        return 'failed_jobs';
    }

    public function area(): string
    {
        return 'system';
    }

    protected function permissions(): array
    {
        return ['system.jobs.manage'];
    }

    public function items(User $user, int $businessId): array
    {
        $count = FailedJobs::count();
        $latest = $count > 0 ? FailedJobs::latest() : null;

        if ($latest === null) {
            return [];
        }

        $oldest = FailedJobs::query()->min('failed_at');

        return [$this->item(
            'all',
            AttentionItem::CRITICAL,
            'failed_jobs',
            '{count} background jobs failed. The latest, {job}, stopped on {error}.',
            ['count' => $count, 'job' => class_basename($latest['job']), 'error' => class_basename($latest['exception_class'])],
            '/settings/failed-jobs',
            [
                'since' => $oldest,
                'ownerRole' => 'Owner',
                'count' => $count,
                'actions' => [
                    ['label' => 'Retry', 'label_key' => 'attention.retry', 'url' => "/settings/failed-jobs/{$latest['uuid']}/retry", 'method' => 'post'],
                    ['label' => 'Forget', 'label_key' => 'attention.forget', 'url' => "/settings/failed-jobs/{$latest['uuid']}", 'method' => 'delete', 'confirm' => 'attention.forget_confirm'],
                ],
            ],
        )];
    }
}
