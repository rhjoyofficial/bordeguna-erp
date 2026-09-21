<?php

namespace App\Modules\Sales\Exceptions;

use RuntimeException;

/**
 * Why a draft sales order was not written, as the ERP says it (HQ docs/83 C.3).
 *
 * Three kinds, each with its own status: a request the ERP will not take as it stands (422
 * `refused`), figures HQ proposed that no longer match the ERP's own (422 `quote_mismatch`, every
 * field named with both figures), and a key already used for a different request (409
 * `idempotency_key_reused`). None of them writes anything.
 */
final class SalesOrderDraftRefused extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $errors  field => sentences
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $errors = [],
    ) {
        parent::__construct($message);
    }

    /** @param array<string, list<string>> $errors */
    public static function refused(array $errors): self
    {
        return new self('refused', 'The ERP will not take this draft as it stands.', 422, $errors);
    }

    /** @param array<string, list<string>> $errors */
    public static function quoteMismatch(array $errors): self
    {
        return new self('quote_mismatch', 'Figures in the proposal no longer match the ERP\'s own. Nothing was written; quote again.', 422, $errors);
    }

    public static function keyReused(): self
    {
        return new self('idempotency_key_reused', 'This idempotency key was already used for a different draft. Nothing was written.', 409);
    }
}
