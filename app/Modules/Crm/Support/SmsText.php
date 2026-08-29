<?php

namespace App\Modules\Crm\Support;

/**
 * How many message parts a piece of text costs, which is how much it costs.
 *
 * ⚠⚠ BANGLA IS TWO TO THREE TIMES THE PRICE OF THE SAME MESSAGE IN ENGLISH, and
 * nothing on a compose screen makes that obvious. GSM-7 fits 160 characters in
 * one part; anything outside that alphabet forces UCS-2, which fits 70. A Bangla
 * greeting that reads as one short line is three parts, and at a few thousand
 * recipients that difference is the whole bill.
 *
 * ⚠ ONE non-GSM character converts the WHOLE message. A single Bangla word, a
 * curly quote pasted from Word, or an emoji in an otherwise English message
 * triples its cost. That is why this returns the alphabet as well as the count:
 * the screen can say WHY it suddenly costs more.
 *
 * ⚠ Concatenated messages lose characters to the joining header: 153 per part
 * in GSM-7 and 67 in UCS-2, not 160 and 70. Counting the single-part figures for
 * a long message under-estimates every long send.
 */
final class SmsText
{
    private const GSM_SINGLE = 160;

    private const GSM_CONCATENATED = 153;

    private const UNICODE_SINGLE = 70;

    private const UNICODE_CONCATENATED = 67;

    /**
     * ⚠ The basic GSM 03.38 alphabet plus its extension table. Characters in the
     * extension set (the second string) take TWO GSM septets each, which is why
     * a message full of square brackets can cost a part more than its character
     * count suggests.
     */
    private const GSM_BASIC = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?"
        ."¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    private const GSM_EXTENDED = "^{}\\[~]|€";

    /**
     * @return array{alphabet:string, characters:int, parts:int, per_part:int}
     */
    public static function measure(string $body): array
    {
        $unicode = ! self::isGsm($body);

        $characters = $unicode
            ? mb_strlen($body, 'UTF-8')
            : self::gsmLength($body);

        $single = $unicode ? self::UNICODE_SINGLE : self::GSM_SINGLE;
        $concatenated = $unicode ? self::UNICODE_CONCATENATED : self::GSM_CONCATENATED;

        // ⚠ An empty body is ZERO parts, not one. A campaign with nothing in it
        // must not report a cost, or a preview of a mistake looks like a plan.
        if ($characters === 0) {
            return ['alphabet' => $unicode ? 'unicode' : 'gsm', 'characters' => 0, 'parts' => 0, 'per_part' => $single];
        }

        $parts = $characters <= $single
            ? 1
            : (int) ceil($characters / $concatenated);

        return [
            'alphabet' => $unicode ? 'unicode' : 'gsm',
            'characters' => $characters,
            'parts' => $parts,
            'per_part' => $parts > 1 ? $concatenated : $single,
        ];
    }

    /**
     * Whether every character fits the GSM alphabet.
     */
    public static function isGsm(string $body): bool
    {
        $length = mb_strlen($body, 'UTF-8');

        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($body, $i, 1, 'UTF-8');

            if (! str_contains(self::GSM_BASIC, $char) && ! str_contains(self::GSM_EXTENDED, $char)) {
                return false;
            }
        }

        return true;
    }

    /**
     * GSM length in septets, counting extension characters as two.
     */
    private static function gsmLength(string $body): int
    {
        $length = mb_strlen($body, 'UTF-8');
        $septets = 0;

        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($body, $i, 1, 'UTF-8');
            $septets += str_contains(self::GSM_EXTENDED, $char) ? 2 : 1;
        }

        return $septets;
    }
}
