<?php

namespace App\Modules\Foundation\Support;

/**
 * Holds the "current business" for the duration of a request, artisan command,
 * or queued job. Deliberately not session-only: HTTP middleware sets this from
 * the session, but console commands and queued jobs must set it explicitly
 * (session doesn't exist there) via set()/forget() around the work they do.
 */
class BusinessContext
{
    private ?int $businessId = null;

    public function set(int $businessId): void
    {
        $this->businessId = $businessId;
    }

    public function forget(): void
    {
        $this->businessId = null;
    }

    public function id(): ?int
    {
        return $this->businessId;
    }

    public function hasBusiness(): bool
    {
        return $this->businessId !== null;
    }
}
