<?php

namespace App\Modules\Crm\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message to one person.
 *
 * ⚠⚠ `delivered` IS ONLY EVER WRITTEN WHEN A PROVIDER SAYS SO. Several
 * Bangladeshi providers send no receipt at all, and for those the honest final
 * state is `sent`. A system that promoted acceptance to delivery would be
 * reporting a fact it does not have, on every message, for ever.
 */
class MessageDelivery extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id', 'message_campaign_id', 'contact_id', 'to_address',
        'status', 'provider_message_id', 'error', 'parts', 'cost',
        'sent_at', 'delivered_at',
    ];

    protected $attributes = ['status' => 'queued', 'parts' => 1];

    protected function casts(): array
    {
        return ['cost' => 'decimal:4', 'sent_at' => 'datetime', 'delivered_at' => 'datetime'];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(MessageCampaign::class, 'message_campaign_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
