<?php

namespace App\Modules\Crm\Support;

/**
 * Turns a phone number as a human typed it into a value that can be MATCHED.
 *
 * ⚠⚠ THE ORIGINAL IS NEVER REWRITTEN. `leads.phone` and `customers.phone` keep
 * exactly what was entered; this produces a separate `phone_normalized` used for
 * nothing except finding the same person twice. Joy's rule from the supplier
 * decision stands: we do not silently correct somebody's data.
 *
 * ⚠ Why this exists here when it was explicitly REFUSED for suppliers. Three
 * suppliers were typed by hand by one person, and normalisation would have been
 * machinery for nothing. CRM receives thousands of records from Meta forms,
 * websites, events and a call centre - channels nobody controls - and identity
 * matching is the difference between one customer with a history and two
 * disconnected rows. The contradiction is deliberate and was flagged to Joy
 * rather than quietly reversed.
 *
 * ⚠⚠ THE OUTPUT IS NOT UNIQUE AND MUST NEVER BE MADE UNIQUE. Households share a
 * number, a shop's landline is the owner's mobile, and a family buys under one
 * phone. Matching WARNS; it does not block. C-CRM-12, C-CRM-13.
 *
 * Bangladeshi mobile numbers are 11 digits beginning `01`. The forms seen in
 * practice: `01711000000`, `+8801711000000`, `8801711000000`, and - the one that
 * already bit this project on three real supplier rows - `1711000000` with the
 * leading zero lost to a spreadsheet.
 */
final class PhoneIdentity
{
    /**
     * The matching form, or null when there is nothing usable to match on.
     *
     * ⚠ Returns null rather than '' so the column stays NULL. An empty string
     * would match every other empty string, and a table of leads with no phone
     * would all look like the same person.
     */
    public static function normalize(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        if ($digits === '') {
            return null;
        }

        // +880 1711 000000 / 8801711000000 → 01711000000
        if (str_starts_with($digits, '880')) {
            $digits = substr($digits, 3);
        }

        // ⚠ The spreadsheet case: a 10-digit number starting `1` lost its zero.
        // Restoring it here means the two spellings match each other, WITHOUT
        // touching what the user typed.
        if (strlen($digits) === 10 && str_starts_with($digits, '1')) {
            $digits = '0'.$digits;
        }

        return $digits;
    }
}
