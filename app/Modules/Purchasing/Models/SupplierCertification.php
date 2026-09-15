<?php

namespace App\Modules\Purchasing\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Concerns\HasAttachments;
use App\Modules\Foundation\Contracts\ControlsAttachmentAccess;
use App\Modules\Purchasing\Enums\CertificationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A certification a supplier holds (Joy's answer 18, CRM lead slice 2e).
 *
 * ⚠ Unlike an inspection or a sample, this is NOT evidence of something that
 * happened: it is a fact about the supplier that changes when a certificate is
 * renewed or was typed wrong. So it is editable and removable by whoever manages
 * suppliers, and the activity log keeps what it said before.
 *
 * ⚠ Its scan is readable by anyone who may open the supplier screen: a
 * certificate is shown to buyers on purpose, unlike an NID.
 */
class SupplierCertification extends Model implements ControlsAttachmentAccess
{
    use BelongsToBusiness, HasAttachments, LogsActivity;

    public const SCAN = 'scan';

    protected $fillable = ['business_id', 'supplier_id', 'type', 'name', 'number', 'expires_on', 'recorded_by'];

    protected function casts(): array
    {
        return [
            'type' => CertificationType::class,
            'expires_on' => 'date',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * ⚠ Compared as DATE STRINGS in the app's own Asia/Dhaka calendar, the
     * recurring trap: a certificate that expires today is still valid today.
     */
    public function isExpired(): bool
    {
        return $this->expires_on !== null && $this->expires_on->toDateString() < Carbon::today()->toDateString();
    }

    /** "BSTI CM", or the name typed for an Other. */
    public function label(): string
    {
        return $this->type === CertificationType::Other && filled($this->name)
            ? (string) $this->name
            : $this->type->label();
    }

    public function canAccessAttachments(User $user): bool
    {
        return $user->can('purchasing.reports.view');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['type', 'name', 'number', 'expires_on'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
