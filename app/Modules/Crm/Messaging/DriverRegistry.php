<?php

namespace App\Modules\Crm\Messaging;

use RuntimeException;

/**
 * Which driver key means which class.
 *
 * ⚠ A registry rather than a class name stored in the database. A stored class
 * name is an instruction to instantiate whatever a row says, and a settings
 * table that can name any class is a settings table that can name the wrong one.
 */
class DriverRegistry
{
    /** @var array<string,class-string<MessageDriver>> */
    private const DRIVERS = [
        // ⚠⚠ ONE DRIVER FOR EVERY HTTP GATEWAY, not one per vendor. Joy has not
        // chosen a provider, so the class that sends must not know which one it
        // is: BulkSMSBD, Alpha SMS and whatever he signs up to next are all
        // settings rows against this same key. See ProviderPresets.
        'http_sms' => HttpSmsDriver::class,
        'recording_sms' => RecordingSmsDriver::class,
        'smtp_mail' => SmtpMailDriver::class,
    ];

    /** @var array<string,string> driver key => channel it belongs to */
    private const CHANNELS = [
        'http_sms' => 'sms',
        'recording_sms' => 'sms',
        'smtp_mail' => 'email',
    ];

    public function make(string $key): MessageDriver
    {
        $class = self::DRIVERS[$key] ?? throw new RuntimeException("There is no messaging driver called '{$key}'.");

        return app($class);
    }

    public function channelFor(string $key): ?string
    {
        return self::CHANNELS[$key] ?? null;
    }

    /**
     * @return list<array{value:string, label:string, channel:string}>
     */
    public function options(): array
    {
        $options = [];

        foreach (self::DRIVERS as $key => $class) {
            $options[] = ['value' => $key, 'label' => app($class)->label(), 'channel' => self::CHANNELS[$key]];
        }

        return $options;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys(self::DRIVERS);
    }
}
