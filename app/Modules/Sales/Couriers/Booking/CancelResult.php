<?php

namespace App\Modules\Sales\Couriers\Booking;

final class CancelResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $message,
    ) {}
}
