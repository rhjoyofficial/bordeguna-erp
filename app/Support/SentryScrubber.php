<?php

namespace App\Support;

use Sentry\Event;
use Sentry\EventHint;

/**
 * What leaves this server when Sentry reports an error.
 *
 * ⚠⚠ THIS ERP HOLDS SALARIES, BANK BALANCES AND CUSTOMER DETAILS. Sentry is a
 * third party in the United States, so an error report must say WHERE the
 * code broke, never WHAT the money or the person was. send_default_pii is off
 * (no cookies, no request body, no user IP), and this runs on every event as
 * a second net: anything left that looks like a secret or a figure is
 * replaced before it is sent.
 */
final class SentryScrubber
{
    /** Keys whose value is never sent, matched anywhere in the name. */
    private const SENSITIVE = [
        'password', 'passwd', 'secret', 'token', 'authorization', 'cookie', 'api_key', 'apikey',
        'account_number', 'routing', 'bank', 'salary', 'amount', 'balance', 'nid', 'phone',
        'mobile', 'email', 'address', 'card', 'cvv', 'otp',
    ];

    public static function beforeSend(Event $event, ?EventHint $hint = null): ?Event
    {
        // The request body and cookies are already absent with PII off;
        // drop them anyway so a future config change cannot start sending them.
        $request = $event->getRequest();
        unset($request['data'], $request['cookies'], $request['query_string']);
        $event->setRequest(self::redact($request));

        $event->setExtra(self::redact($event->getExtra()));
        foreach (self::redact($event->getContexts()) as $name => $context) {
            $event->setContext((string) $name, (array) $context);
        }

        // ⚠ A database error message carries the bound VALUES (a duplicate
        // e-mail, an amount in an insert), so for those only the SQLSTATE code
        // is kept. Every other exception message keeps its words but loses any
        // e-mail address or long digit run (phone, account, NID).
        $exceptions = $event->getExceptions();
        foreach ($exceptions as $exception) {
            $exception->setValue(self::scrubMessage((string) $exception->getType(), (string) $exception->getValue()));
        }
        $event->setExceptions($exceptions);

        $event->setBreadcrumb([]);

        return $event;
    }

    /** @param array<mixed> $data @return array<mixed> */
    public static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSensitive($key)) {
                $data[$key] = '[redacted]';

                continue;
            }

            if (is_array($value)) {
                $data[$key] = self::redact($value);
            }
        }

        return $data;
    }

    public static function scrubMessage(string $type, string $message): string
    {
        if (str_contains($type, 'QueryException') || str_contains($type, 'PDOException') || str_contains($message, 'SQLSTATE[')) {
            return preg_match('/SQLSTATE\[(\w+)\]/', $message, $m) ? 'Database error (SQLSTATE '.$m[1].')' : 'Database error';
        }

        $message = preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', '[email]', $message) ?? $message;

        return preg_replace('/\d[\d\s-]{7,}\d/', '[number]', $message) ?? $message;
    }

    private static function isSensitive(string $key): bool
    {
        $key = strtolower($key);

        foreach (self::SENSITIVE as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }
}
