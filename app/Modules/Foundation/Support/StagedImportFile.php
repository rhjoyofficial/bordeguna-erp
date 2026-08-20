<?php

namespace App\Modules\Foundation\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * An uploaded CSV parked between "show me what this would do" and "do it".
 *
 * ⚠⚠ C-IMP-2: WHAT YOU APPROVED IS WHAT APPLIES. The preview hands back the
 * file's SHA-256 and the apply step must hand it straight back. Without that,
 * two people previewing two different files in two tabs can apply the wrong
 * one - and the screen would have shown a perfectly truthful preview of a file
 * that is not the one that landed. The hash is checked against the bytes on
 * disk at apply time, not against anything remembered.
 *
 * ⚠ Scoped to the business that uploaded it, in the path AND in the check, so a
 * token from one tenant cannot name a file staged by another.
 */
class StagedImportFile
{
    /** C-IMP-6. Stated on screen, refused politely above. */
    public const MAX_BYTES = 10 * 1024 * 1024;

    public const MAX_ROWS = 5000;

    private const DISK = 'local';

    public function __construct(
        public readonly string $token,
        public readonly string $path,
        public readonly string $hash,
        public readonly string $originalName,
        public readonly int $rows,
    ) {}

    /**
     * Park an upload and describe it. Nothing is parsed here: each importer has
     * its own reader, and a header this class did not understand would produce
     * a worse error than the reader's own.
     */
    public static function stage(UploadedFile $file, int $businessId): self
    {
        $token = (string) Str::uuid();
        $path = self::directory($businessId).'/'.$token.'.csv';

        Storage::disk(self::DISK)->put($path, $file->get());

        $full = Storage::disk(self::DISK)->path($path);

        return new self(
            token: $token,
            path: $full,
            hash: hash_file('sha256', $full),
            originalName: $file->getClientOriginalName(),
            rows: self::countRows($full),
        );
    }

    /**
     * Find a staged file again and prove it is byte-for-byte the one that was
     * previewed.
     *
     * @throws RuntimeException when the token is unknown, or the bytes moved
     */
    public static function reopen(string $token, string $expectedHash, int $businessId): self
    {
        // ⚠ The token comes from a form. Anything but a plain UUID is refused
        // before it is ever concatenated into a path.
        if (preg_match('/^[0-9a-f-]{36}$/i', $token) !== 1) {
            throw new RuntimeException('That upload cannot be found any more. Upload the file again.');
        }

        $path = self::directory($businessId).'/'.$token.'.csv';

        if (! Storage::disk(self::DISK)->exists($path)) {
            throw new RuntimeException('That upload cannot be found any more. Upload the file again.');
        }

        $full = Storage::disk(self::DISK)->path($path);
        $hash = hash_file('sha256', $full);

        if (! hash_equals($hash, $expectedHash)) {
            throw new RuntimeException(
                'This is not the file you reviewed, so nothing has been imported. Upload it again and read the preview.'
            );
        }

        return new self(
            token: $token,
            path: $full,
            hash: $hash,
            originalName: basename($path),
            rows: self::countRows($full),
        );
    }

    /**
     * ⚠ Deleted once applied. A staged file has served its purpose, and leaving
     * tenant data lying in storage for ever is a liability, not a convenience.
     */
    public function discard(int $businessId): void
    {
        Storage::disk(self::DISK)->delete(self::directory($businessId).'/'.$this->token.'.csv');
    }

    public function isTooBig(): bool
    {
        return $this->rows > self::MAX_ROWS;
    }

    private static function directory(int $businessId): string
    {
        return 'imports/'.$businessId;
    }

    /**
     * Data rows, excluding the header.
     *
     * ⚠ Counted by reading lines rather than by loading the file, because the
     * whole point of the cap is to refuse a file too big to hold.
     */
    private static function countRows(string $full): int
    {
        $handle = fopen($full, 'r');

        if ($handle === false) {
            return 0;
        }

        try {
            $lines = 0;

            while (fgets($handle) !== false) {
                $lines++;
            }

            return max(0, $lines - 1);
        } finally {
            fclose($handle);
        }
    }
}
