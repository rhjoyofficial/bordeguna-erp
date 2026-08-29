<?php

namespace App\Modules\Crm\Messaging;

use App\Modules\Crm\Models\MessageProvider;
use Illuminate\Support\Facades\Log;

/**
 * An SMS provider that records instead of sending.
 *
 * ⚠⚠ THIS EXISTS BECAUSE THERE IS NO REAL SMS PROVIDER YET. Joy has said the
 * account details come later, so the engine, the audience, the preview, the
 * opt-out register, the approval and the per-recipient log are all real and
 * finished, and only the last inch is missing. When the credentials arrive, a
 * real driver is one class and one settings row: nothing else changes. That is
 * the whole reason MessageDriver has two methods.
 *
 * ⚠ It reports ACCEPTED, on purpose. From the engine's point of view the
 * message was handed over successfully, and a driver that reported failure would
 * make every test of the surrounding machinery test the wrong thing. What it
 * must never do is pretend a message was DELIVERED - nothing here writes that.
 *
 * ⚠⚠ SAFE BY DEFAULT: a provider row is created inactive, so this cannot start
 * quietly standing in for a real one.
 */
class RecordingSmsDriver implements MessageDriver
{
    public function label(): string
    {
        return 'Record only (no SMS is sent)';
    }

    public function send(MessageProvider $provider, string $to, string $body, ?string $subject = null): SendResult
    {
        Log::info('SMS recorded, not sent', [
            'provider' => $provider->name,
            'to' => $to,
            'characters' => mb_strlen($body),
        ]);

        return SendResult::accepted('recorded-'.uniqid());
    }
}
