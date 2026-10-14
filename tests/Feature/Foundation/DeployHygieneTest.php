<?php

use App\Modules\Sales\Couriers\Jobs\BookParcelsInBulk;
use Symfony\Component\Yaml\Yaml;

/**
 * Deploy hygiene (audit, 13 Oct 2026): two settings whose failure only shows
 * on the server, so they are pinned here where a change has to argue with a test.
 */
it('keeps every .env file, at any depth, out of the docker build context', function () {
    $ignored = array_map('trim', file(base_path('.dockerignore')));

    // ⚠⚠ The Dockerfile does `COPY . .`, and a bare `.env` matches only the root.
    expect($ignored)->toContain('.env', '**/.env', '**/.env.*', 'deploy/.env');
});

it('waits longer than the longest job before a database-queue job is retried', function () {
    // ⚠ The SHIPPED default, read from the file: a local .env may override it.
    preg_match("/env\('DB_QUEUE_RETRY_AFTER',\s*(\d+)\)/", file_get_contents(base_path('config/queue.php')), $match);

    expect((int) ($match[1] ?? 0))
        ->toBeGreaterThan((new ReflectionClass(BookParcelsInBulk::class))->getDefaultProperties()['timeout']);
});

it('gives the queue worker time to finish a job when a deploy stops it (audit #28)', function () {
    $worker = Yaml::parseFile(base_path('deploy/docker-compose.yml'))['services']['worker'];

    preg_match('/--timeout=(\d+)/', $worker['command'], $timeout);
    preg_match('/^(\d+)s$/', (string) ($worker['stop_grace_period'] ?? ''), $grace);
    preg_match("/env\('DB_QUEUE_RETRY_AFTER',\s*(\d+)\)/", file_get_contents(base_path('config/queue.php')), $retry);

    // --timeout < stop_grace_period < retry_after, or a deploy SIGKILLs a job
    // mid-way (Docker's default grace is 10 s) or a second worker takes it.
    expect((int) ($timeout[1] ?? 0))->toBeGreaterThan(0)
        ->and((int) $timeout[1])->toBeLessThan((int) ($grace[1] ?? 0))
        ->and((int) $grace[1])->toBeLessThan((int) ($retry[1] ?? 0));
});
