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
 * ⚠⚠ THE OUTPUT IS NOT UNIQUE AND MUST NEVER BE MADE UNIQUE FOR A PERSON.
 * Households share a number, a shop's landline is the owner's mobile, and a
 * family buys under one phone. For a person, matching WARNS; it does not block.
 * C-CRM-12, C-CRM-13.
 *
 * ⚠⚠ NARROWED, NOT OVERTURNED, 12 Sep 2026 - read this before "restoring" the
 * rule above. Joy's instruction of 10 Sep was that a lead's phone must be
 * unique, because a Dokandar's shop number IS the shop, and two rows carrying it
 * are two field officers each believing they own one relationship. That directly
 * contradicted the paragraph above, which was written about consumers.
 *
 * Both are right about different things, so uniqueness is scoped BY LEAD TYPE
 * and lives on a SEPARATE column, `leads.shop_phone_key`, which holds this
 * output for a place of business and NULL for a person. `phone_normalized`
 * itself is untouched and still carries no unique index anywhere, so everything
 * the paragraph above protects is still protected. See LeadType::isShop().
 *
 * ⚠ `customers.phone_normalized` is also untouched: a customer is a person here
 * and the original rule applies to it in full.
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
