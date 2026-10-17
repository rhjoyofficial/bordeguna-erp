<?php

namespace App\Modules\Foundation\Corrections;

use App\Modules\Foundation\Corrections\Exceptions\PreviewExpiredException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * What the person saw, sealed (PHASE-0-CONTRACT v2, 1.4 step 2).
 *
 * Encrypted and signed with the app key. It binds the user, the business, the
 * document, the action, the payload fingerprint, the document fingerprint, the
 * dates and the route, and it lives 15 minutes. ⚠ It only ever authorises an
 * immediate submit; a request approved later is revalidated from scratch (C9).
 */
final class PreviewToken
{
    public const LIFETIME_SECONDS = 900;

    public static function issue(array $claims): string
    {
        return Crypt::encryptString((string) json_encode($claims + ['iat' => now()->getTimestamp()]));
    }

    /**
     * @throws PreviewExpiredException
     */
    public static function open(?string $token, int $userId, int $businessId, CorrectionInput $input): array
    {
        if ($token === null || $token === '') {
            throw new PreviewExpiredException('missing');
        }

        try {
            $claims = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw new PreviewExpiredException('tampered');
        }

        if (($claims['uid'] ?? null) !== $userId || ($claims['bid'] ?? null) !== $businessId) {
            throw new PreviewExpiredException('other_user');
        }

        if (($claims['type'] ?? null) !== $input->type || ($claims['id'] ?? null) !== $input->documentId || ($claims['action'] ?? null) !== $input->action->value) {
            throw new PreviewExpiredException('other_document');
        }

        if (! hash_equals((string) ($claims['pfp'] ?? ''), $input->payloadFingerprint())) {
            throw new PreviewExpiredException('other_payload');
        }

        if (now()->getTimestamp() - (int) ($claims['iat'] ?? 0) > self::LIFETIME_SECONDS) {
            throw new PreviewExpiredException('expired');
        }

        return $claims;
    }
}
