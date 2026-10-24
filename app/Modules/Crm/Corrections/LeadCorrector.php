<?php

namespace App\Modules\Crm\Corrections;

use App\Modules\Crm\Models\Lead;
use App\Modules\Foundation\Corrections\Contracts\DocumentActionPolicy;
use Illuminate\Database\Eloquent\Model;

/** ⭐ A lead in the registry (wave 3, 24 Oct 2026). See CrmRecordCorrector. */
class LeadCorrector extends CrmRecordCorrector
{
    public function __construct(private readonly LeadActionPolicy $policy) {}

    public function policy(): DocumentActionPolicy
    {
        return $this->policy;
    }

    public function makerAndLastEditorIds(Model $document): array
    {
        /** @var Lead $document */
        return array_values(array_filter([(int) $document->created_by_user_id]));
    }

    protected function useInstead(): string
    {
        return 'A lead is changed from its own page: Reverse conversion, Reopen or Delete.';
    }
}
