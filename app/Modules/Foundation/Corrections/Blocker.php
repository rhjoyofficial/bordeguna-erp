<?php

namespace App\Modules\Foundation\Corrections;

/**
 * Why an action cannot run, in ONE plain sentence (PHASE-0-CONTRACT 1.2).
 *
 * ⚠ The message is what the person reads: no codes, no class names. The code is
 * for tests and for the Corrections report's "blocked attempt" rows.
 */
final readonly class Blocker
{
    public function __construct(
        public string $code,
        public string $message,
        public ?string $url = null,
        public ?string $resolvableBy = null,
        public bool $requestable = false,
    ) {}

    public static function missingCheck(): self
    {
        return new self('missing_dependency_check', 'This record cannot be corrected yet: a safety check is missing. Ask the administrator.', resolvableBy: 'administrator');
    }

    public static function noSecondPerson(): self
    {
        return new self('no_second_person', 'This needs a second person with approval rights, and nobody else here has them.', resolvableBy: 'owner');
    }

    public static function noPermission(DocumentAction $action): self
    {
        return new self('no_permission', "You are not allowed to {$action->value} this record.");
    }

    /** @return array{code: string, message: string, url: ?string, requestable: bool} */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'message' => $this->message,
            'url' => $this->url,
            'requestable' => $this->requestable,
        ];
    }
}
