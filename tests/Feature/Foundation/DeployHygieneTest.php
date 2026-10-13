<?php

use App\Modules\Sales\Couriers\Jobs\BookParcelsInBulk;

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
