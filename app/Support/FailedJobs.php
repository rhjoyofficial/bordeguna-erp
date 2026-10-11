<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * The queue's failed jobs, readable by a person (Owner, system.jobs.manage).
 *
 * ⚠⚠ NO SECRETS LEAVE THIS CLASS. A job's payload is never shown, only its
 * class; its exception is cut to its first lines and anything shaped like a
 * token, key, password, bearer header, JWT or connection string is replaced by
 * [hidden] before it reaches the browser. An exception from an HTTP client
 * happily quotes the request it failed on, headers and all.
 *
 * ⚠ failed_jobs has no business column: what is here is system-wide, which is
 * why only the Owner role holds the permission by default.
 */
final class FailedJobs
{
    public const LINES = 6;

    public const LINE_WIDTH = 300;

    public const HIDDEN = '[hidden]';

    public static function query(): Builder
    {
        return DB::connection(config('queue.failed.database'))->table(config('queue.failed.table', 'failed_jobs'));
    }

    public static function count(): int
    {
        return self::query()->count();
    }

    /**
     * @return list<array{id: int, uuid: string, queue: string, connection: string, job: string, exception_class: string, exception: string, failed_at: ?string}>
     */
    public static function list(int $limit = 200): array
    {
        return self::query()
            ->orderByDesc('failed_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => self::present($row))
            ->values()
            ->all();
    }

    /** @return array<string, mixed>|null */
    public static function latest(): ?array
    {
        $row = self::query()->orderByDesc('failed_at')->orderByDesc('id')->first();

        return $row === null ? null : self::present($row);
    }

    /** @return array{id: int, uuid: string, queue: string, connection: string, job: string, exception_class: string, exception: string, failed_at: ?string} */
    public static function present(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'uuid' => (string) $row->uuid,
            'queue' => (string) $row->queue,
            'connection' => (string) $row->connection,
            'job' => self::jobClass((string) $row->payload),
            'exception_class' => self::exceptionClass((string) $row->exception),
            'exception' => self::firstLines((string) $row->exception),
            'failed_at' => $row->failed_at === null ? null : Carbon::parse($row->failed_at)->toIso8601String(),
        ];
    }

    /** The job's class from its payload, and nothing else of it. */
    public static function jobClass(string $payload): string
    {
        $decoded = json_decode($payload, true);

        $name = is_array($decoded) ? ($decoded['displayName'] ?? $decoded['data']['commandName'] ?? $decoded['job'] ?? null) : null;

        return is_string($name) && $name !== '' ? self::scrub($name) : 'Unknown job';
    }

    /** "Illuminate\Http\Client\ConnectionException: cURL error ..." -> the class. */
    public static function exceptionClass(string $exception): string
    {
        $first = trim(strtok($exception, "\n") ?: '');

        if (preg_match('/^([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)(?::|\s|$)/', $first, $match) === 1) {
            return $match[1];
        }

        return 'Unknown error';
    }

    /** The first LINES lines, each at most LINE_WIDTH characters, scrubbed. */
    public static function firstLines(string $exception): string
    {
        $lines = array_slice(preg_split('/\R/', trim($exception)) ?: [], 0, self::LINES);

        return self::scrub(implode("\n", array_map(fn (string $line) => mb_strimwidth($line, 0, self::LINE_WIDTH, '...'), $lines)));
    }

    /**
     * Replace anything that looks like a secret. Deliberately greedy: a hidden
     * word in an error message costs nothing, a leaked token costs the account.
     */
    public static function scrub(string $text): string
    {
        $patterns = [
            // Authorization: Bearer abc / Basic abc
            '/\b(bearer|basic)\s+[A-Za-z0-9\-._~+\/]+=*/i' => '$1 '.self::HIDDEN,
            // JSON Web Tokens
            '/\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/' => self::HIDDEN,
            // scheme://user:password@host
            '#([a-z][a-z0-9+.\-]*://[^\s:/@]+:)[^\s@/]+@#i' => '$1'.self::HIDDEN.'@',
            // key=value, "key": "value", key: value, for any key naming a secret
            '/((?:api[_-]?key|apikey|access[_-]?token|refresh[_-]?token|token|secret|client[_-]?secret|password|passwd|pwd|authorization|signature|private[_-]?key|store[_-]?password|x-api-key)["\']?\s*[:=]\s*["\']?)[^\s"\'&,;}\]]+/i' => '$1'.self::HIDDEN,
            // Long opaque strings: a run of 24+ letters and digits holding at
            // least one digit and one letter (keys, tokens, hashes). Class names
            // and words have no digits; paths are broken up by slashes.
            '/(?<![A-Za-z0-9_\-])(?=[A-Za-z0-9_\-]*\d)(?=[A-Za-z0-9_\-]*[A-Za-z])[A-Za-z0-9_\-]{24,}(?![A-Za-z0-9_\-])/' => self::HIDDEN,
        ];

        return (string) preg_replace(array_keys($patterns), array_values($patterns), $text);
    }

    public static function exists(string $uuid): bool
    {
        return self::query()->where('uuid', $uuid)->exists();
    }

    /** Put it back on its queue (queue:retry), which also removes it from the list. */
    public static function retry(string $uuid): void
    {
        Artisan::call('queue:retry', ['id' => [$uuid]]);
    }

    public static function retryAll(): void
    {
        Artisan::call('queue:retry', ['id' => ['all']]);
    }

    /** Remove it without running it again (queue:forget). */
    public static function forget(string $uuid): void
    {
        Artisan::call('queue:forget', ['id' => $uuid]);
    }
}
