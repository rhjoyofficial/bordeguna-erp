<?php

namespace App\Modules\Sales\Exceptions;

use RuntimeException;

/**
 * Why an HQ request about an order was not taken, as the ERP says it (HQ docs/83 C.3, docs/88 A point 3).
 *
 * The name is the draft's because the draft came first; quotes, the order made from a draft and HQ's
 * cancellation use the same refusals so HQ reads one shape. The kinds, each with its own status: a
 * request the ERP will not take as it stands (422 `refused`), figures HQ proposed that no longer match
 * the ERP's own (422 `quote_mismatch`, every field named with both figures), a key already used for a
 * different request (409 `idempotency_key_reused`), nothing to act on (404 `not_found`), and a state
 * that forbids the act (409 `draft_already_confirmed`, `already_cancelled`, `has_deliveries`). None
 * of them writes anything.
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

    /** The same key, a different request, for an order or a cancellation rather than a draft. */
    public static function keyReusedFor(string $what): self
    {
        return new self('idempotency_key_reused', "This idempotency key was already used for a different {$what}. Nothing was written.", 409);
    }

    public static function notFound(string $sentence): self
    {
        return new self('not_found', $sentence, 404);
    }

    public static function draftAlreadyConfirmed(string $orderNumber): self
    {
        return new self('draft_already_confirmed', "This draft is already order {$orderNumber}; a draft becomes one order, once. Nothing was written.", 409);
    }

    public static function alreadyCancelled(string $orderNumber): self
    {
        return new self('already_cancelled', "Order {$orderNumber} is already cancelled. Nothing was written.", 409);
    }

    public static function hasDeliveries(string $sentence): self
    {
        return new self('has_deliveries', $sentence, 409);
    }
}
