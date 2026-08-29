<?php

namespace App\Modules\Crm\Messaging;

use App\Modules\Crm\Models\MessageProvider;

/**
 * What a provider has to be able to do. Two things, and no more.
 *
 * ⚠⚠ DELIBERATELY TINY. Adding a provider must be a new class and a settings
 * row, and must not require touching the campaign engine, the preview, the
 * opt-out register or any screen. Every method added to this interface is a
 * method every future provider has to implement, including the ones that cannot.
 */
interface MessageDriver
{
    /**
     * Send one message.
     *
     * ⚠ Returns a RESULT rather than throwing on a rejected message. One bad
     * number in five hundred must mark that one delivery failed and let the
     * other four hundred and ninety nine go; an exception would abort the batch.
     * A genuine transport failure - no network, bad credentials - may still
     * throw, and the job's retry handles it.
     */
    public function send(MessageProvider $provider, string $to, string $body, ?string $subject = null): SendResult;

    /**
     * A human-readable name for the settings screen.
     */
    public function label(): string;
}
