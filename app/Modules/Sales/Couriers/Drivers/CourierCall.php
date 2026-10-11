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
    ) {}

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
