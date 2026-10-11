<?php

namespace App\Modules\Sales\Couriers\Drivers;

use Illuminate\Http\Client\Response;

/**
 * One logged courier request: the response if one came back, and the reason it
 * failed if it did. `error` null means it worked.
 */
final class CourierCall
{
    public function __construct(
        public readonly ?Response $response,
        public readonly ?string $error,
        // False when the request never left the ERP (the address failed the
        // allow-list): then nothing can have happened at the courier.
        public readonly bool $sent = true,
    ) {}

    /**
     * Nobody can say whether the courier acted on it: the connection broke or
     * timed out after the request left, or the courier answered with a server
     * error. ⚠ A booking that ends here must NOT be retried blindly (ruling 13).
     */
    public function outcomeUnknown(): bool
    {
        if (! $this->sent) {
            return false;
        }

        if ($this->response === null) {
            return true;
        }

        $status = $this->response->status();

        return $status >= 500 || $status === 408;
    }

    public function status(): ?int
    {
        return $this->response?->status();
    }

    public function ok(): bool
    {
        return $this->error === null && $this->response !== null;
    }

    /**
     * @return array<mixed>
     */
    public function json(): array
    {
        $json = $this->response?->json();

        return is_array($json) ? $json : [];
    }
}
