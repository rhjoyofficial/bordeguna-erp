<?php

namespace App\Modules\Sales\Support;

use App\Modules\Foundation\Support\StagedImportFile;
use App\Modules\Sales\Models\SalesOrderImport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A shop order sheet parked between preview and apply.
 *
 * ⚠ StagedImportFile's rules (C-IMP-2: what you approved is what applies; the
 * hash is checked against the bytes on disk; scoped to the business in the path
 * and in the check), with two differences that sheet needs: the file may be an
 * .xlsx, so the extension travels in the token, and the file is KEPT once
 * applied (moved to its permanent place by `keep()`), not deleted.
 */
final class StagedShopOrderFile
{
    private const DISK = SalesOrderImport::DISK;

    public function __construct(
        public readonly string $token,
        public readonly string $relativePath,
        public readonly string $path,
        public readonly string $extension,
        public readonly string $hash,
        public readonly int $size,
    ) {}

    public static function stage(UploadedFile $file, int $businessId): self
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $extension = in_array($extension, [...ShopOrderSheet::SPREADSHEET_EXTENSIONS, ...ShopOrderSheet::TEXT_EXTENSIONS], true) ? $extension : 'csv';
        $token = Str::uuid().'.'.$extension;
        $relative = self::directory($businessId).'/'.$token;

        Storage::disk(self::DISK)->put($relative, $file->get());

        return self::describe($token, $relative, $extension);
    }

    /**
     * @throws RuntimeException when the token is unknown, or the bytes moved
     */
    public static function reopen(string $token, string $expectedHash, int $businessId): self
    {
        if (preg_match('/^[0-9a-f-]{36}\.(csv|txt|xlsx)$/i', $token, $match) !== 1) {
            throw new RuntimeException('That upload cannot be found any more. Upload the file again.');
        }

        $relative = self::directory($businessId).'/'.$token;

        if (! Storage::disk(self::DISK)->exists($relative)) {
            throw new RuntimeException('That upload cannot be found any more. Upload the file again.');
        }

        $staged = self::describe($token, $relative, strtolower($match[1]));

        if (! hash_equals($staged->hash, $expectedHash)) {
            throw new RuntimeException('This is not the file you reviewed, so nothing has been imported. Upload it again and read the preview.');
        }

        return $staged;
    }

    /** Moves the file to where imported sheets are kept; returns the new relative path. */
    public function keep(int $businessId): string
    {
        $target = 'sales-order-imports/'.$businessId.'/'.now()->format('Ymd-His').'-'.Str::uuid().'.'.$this->extension;
        Storage::disk(self::DISK)->move($this->relativePath, $target);

        return $target;
    }

    public function discard(): void
    {
        Storage::disk(self::DISK)->delete($this->relativePath);
    }

    public function isTooBig(): bool
    {
        return $this->size > StagedImportFile::MAX_BYTES;
    }

    private static function describe(string $token, string $relative, string $extension): self
    {
        $full = Storage::disk(self::DISK)->path($relative);

        return new self(
            token: $token,
            relativePath: $relative,
            path: $full,
            extension: $extension,
            hash: (string) hash_file('sha256', $full),
            size: (int) filesize($full),
        );
    }

    private static function directory(int $businessId): string
    {
        return 'imports/'.$businessId.'/orders';
    }
}
