<?php

namespace App\Modules\Foundation\Corrections;

use App\Models\User;
use App\Modules\Foundation\Corrections\Contracts\DuplicateRule;
use App\Modules\Foundation\Corrections\Exceptions\PossibleDuplicateException;
use App\Modules\Foundation\Models\DuplicateAcknowledgement;
use Illuminate\Database\Eloquent\Model;

/**
 * "Possible duplicate. Is this a different record?" (PHASE-0-CONTRACT 1.11).
 *
 * ⚠⚠ THE ACK IS BOUND, not a checkbox. The ack token is an HMAC (app key) of the
 * user, the business, the rule, the hash of the normalised payload, the sorted
 * candidate ids and the time. It is valid for 15 minutes, for exactly this
 * person and this payload, and only while no NEW candidate has appeared. Change
 * the amount and you are asked again; somebody saves a third similar record in
 * between and you are asked again.
 *
 * ⚠ v2 (C15): an ack is never an approval. The second person and the Owner gate
 * run independently, and the guard runs again at the final save.
 */
class DuplicateGuard
{
    public const LIFETIME_SECONDS = 900;

    /**
     * @param  list<string>  $exclude  "type:id" of the record being corrected and its chain
     *
     * @throws PossibleDuplicateException
     */
    public function check(DuplicateRule $rule, array $payload, User $user, int $businessId, ?string $ackToken, array $exclude = []): ?DuplicateAck
    {
        $candidates = $rule->candidates($payload, $businessId, $user, $exclude);

        if ($candidates === []) {
            return null;
        }

        $payloadHash = hash('sha256', CorrectionInput::canonical($rule->fingerprintOf($payload, $businessId)));
        $ids = $this->ids($candidates);

        if ($ackToken !== null && $ackToken !== '' && $this->accepts($ackToken, $user->id, $businessId, $rule->key(), $payloadHash, $ids)) {
            return new DuplicateAck($user->id, $businessId, $rule->key(), $payloadHash, array_map(
                fn (DuplicateCandidate $c) => ['type' => $c->type, 'id' => $c->id, 'number' => $c->number],
                $candidates,
            ));
        }

        throw new PossibleDuplicateException(new DuplicateWarning(
            $candidates,
            $this->token($user->id, $businessId, $rule->key(), $payloadHash, $ids),
        ));
    }

    /** The warning without throwing, for a preview. */
    public function warning(DuplicateRule $rule, array $payload, User $user, int $businessId, array $exclude = []): ?DuplicateWarning
    {
        try {
            $this->check($rule, $payload, $user, $businessId, null, $exclude);

            return null;
        } catch (PossibleDuplicateException $e) {
            return $e->warning;
        }
    }

    /** Written after the record is saved, in the same transaction. */
    public function record(DuplicateAck $ack, Model $saved): DuplicateAcknowledgement
    {
        $row = new DuplicateAcknowledgement;
        $row->forceFill([
            'business_id' => $ack->businessId,
            'user_id' => $ack->userId,
            'rule_key' => $ack->ruleKey,
            'subject_type' => $saved->getMorphClass(),
            'subject_id' => $saved->getKey(),
            'payload_hash' => $ack->payloadHash,
            'candidates' => $ack->candidates,
            'created_at' => now(),
        ])->save();

        return $row;
    }

    /** @param list<string> $ids */
    public function token(int $userId, int $businessId, string $ruleKey, string $payloadHash, array $ids): string
    {
        $claims = base64_encode((string) json_encode(['u' => $userId, 'b' => $businessId, 'r' => $ruleKey, 'p' => $payloadHash, 'c' => $ids, 't' => now()->getTimestamp()]));

        return $claims.'.'.hash_hmac('sha256', $claims, (string) config('app.key'));
    }

    /** @param list<string> $currentIds */
    private function accepts(string $token, int $userId, int $businessId, string $ruleKey, string $payloadHash, array $currentIds): bool
    {
        [$claims, $mac] = array_pad(explode('.', $token, 2), 2, '');

        if (! hash_equals(hash_hmac('sha256', $claims, (string) config('app.key')), $mac)) {
            return false;
        }

        $data = json_decode((string) base64_decode($claims, true), true);

        if (! is_array($data)) {
            return false;
        }

        if (($data['u'] ?? null) !== $userId || ($data['b'] ?? null) !== $businessId || ($data['r'] ?? null) !== $ruleKey || ($data['p'] ?? null) !== $payloadHash) {
            return false;
        }

        if (now()->getTimestamp() - (int) ($data['t'] ?? 0) > self::LIFETIME_SECONDS) {
            return false;
        }

        // A NEW candidate since the warning means: ask again.
        return array_diff($currentIds, (array) ($data['c'] ?? [])) === [];
    }

    /**
     * @param  list<DuplicateCandidate>  $candidates
     * @return list<string>
     */
    private function ids(array $candidates): array
    {
        $ids = array_map(fn (DuplicateCandidate $c) => $c->type.':'.$c->id, $candidates);
        sort($ids);

        return $ids;
    }
}
