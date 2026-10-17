<?php

namespace App\Modules\Foundation\Corrections;

/** What the person asked for, built from the HTTP request (PHASE-0-CONTRACT 1.3). */
final readonly class CorrectionInput
{
    public function __construct(
        public string $type,
        public int $documentId,
        public DocumentAction $action,
        public ActionReason $reason,
        public ?array $replacement,
        public ReverseDateChoice $dateChoice,
        public string $idempotencyKey,
    ) {}

    /**
     * sha256 of everything the person decided, in canonical JSON (keys sorted at
     * every depth), so the same intent always hashes the same and any change to
     * it, however small, does not.
     */
    public function payloadFingerprint(): string
    {
        return hash('sha256', self::canonical([
            'type' => $this->type,
            'id' => $this->documentId,
            'action' => $this->action->value,
            'reason' => $this->reason->toArray(),
            'replacement' => $this->replacement,
            'date' => $this->dateChoice->value,
        ]));
    }

    public static function canonical(mixed $value): string
    {
        return (string) json_encode(self::sortKeys($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return is_int($value) || is_float($value) ? (string) $value : $value;
        }

        if (array_is_list($value)) {
            return array_map(self::sortKeys(...), $value);
        }

        ksort($value);

        return array_map(self::sortKeys(...), $value);
    }
}
