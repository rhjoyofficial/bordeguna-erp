<?php

namespace App\Modules\Foundation\Support;

use App\Modules\Accounting\Support\CounterpartyIdentity;
use App\Modules\Crm\Support\PhoneIdentity;

/**
 * ⭐ B13 (24 Oct 2026; FINAL-PLAN 5, "Customer, supplier, lead, contact: same
 * phone (Bangla digits converted, +88 removed) or same name ignoring case and
 * spaces"). The matching forms the duplicate warnings on lists compare.
 *
 * ⚠ Never written back: what somebody typed stays as typed. These keys only
 * decide whether to ASK "is this a different one?".
 */
final class DuplicateKeys
{
    private const BANGLA_DIGITS = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];

    /** 01711000000 for "০১৭১১-০০০০০০", "+880 1711 000000" or "1711000000"; null when empty. */
    public static function phone(?string $phone): ?string
    {
        $latin = str_replace(self::BANGLA_DIGITS, ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], (string) $phone);
        $key = PhoneIdentity::normalize($latin);

        // A key too short to be a real number would match every other typo.
        return $key === null || strlen($key) < 6 ? null : $key;
    }

    /** "rahimtraders" for "Rahim  Traders Ltd."; '' when there is nothing to compare. */
    public static function name(?string $name): string
    {
        return CounterpartyIdentity::nameKey($name);
    }

    /** Case, spaces, dashes and dots ignored: "HONEY-500" and "honey 500" are one SKU. */
    public static function code(?string $code): string
    {
        return mb_strtolower(preg_replace('/[\s\-_.\/]+/u', '', (string) $code) ?? '');
    }

    /** An ID number (NID, birth certificate) with every non-digit gone; null when empty. */
    public static function idNumber(?string $number): ?string
    {
        $latin = str_replace(self::BANGLA_DIGITS, ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], (string) $number);
        $digits = preg_replace('/\D+/', '', $latin) ?? '';

        return strlen($digits) < 6 ? null : $digits;
    }
}
