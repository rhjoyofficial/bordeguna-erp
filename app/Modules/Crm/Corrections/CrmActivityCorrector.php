<?php

namespace App\Modules\Crm\Corrections;

use App\Modules\Crm\Models\CrmActivity;
use App\Modules\Foundation\Corrections\Contracts\DocumentActionPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** ⭐ A call, visit or note in the registry (wave 3, 24 Oct 2026). See CrmRecordCorrector. */
class CrmActivityCorrector extends CrmRecordCorrector
{
    public function __construct(private readonly CrmActivityActionPolicy $policy) {}

    public function policy(): DocumentActionPolicy
    {
        return $this->policy;
    }

    public function originalDate(Model $document): Carbon
    {
        /** @var CrmActivity $document */
        return Carbon::parse($document->occurred_at ?? $document->created_at)->startOfDay();
    }

    public function makerAndLastEditorIds(Model $document): array
    {
        /** @var CrmActivity $document */
        return array_values(array_unique(array_filter([(int) $document->recorded_by, (int) $document->edited_by])));
    }

    protected function useInstead(): string
    {
        return 'A call, visit or note is edited or corrected from the lead or customer history.';
    }
}
