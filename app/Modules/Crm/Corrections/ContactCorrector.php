<?php

namespace App\Modules\Crm\Corrections;

use App\Modules\Crm\Models\Contact;
use App\Modules\Foundation\Corrections\Contracts\DocumentActionPolicy;
use Illuminate\Database\Eloquent\Model;

/** ⭐ A phone book contact in the registry (wave 3, 24 Oct 2026). See CrmRecordCorrector. */
class ContactCorrector extends CrmRecordCorrector
{
    public function __construct(private readonly ContactActionPolicy $policy) {}

    public function policy(): DocumentActionPolicy
    {
        return $this->policy;
    }

    public function makerAndLastEditorIds(Model $document): array
    {
        /** @var Contact $document */
        return array_values(array_filter([(int) $document->created_by]));
    }

    protected function useInstead(): string
    {
        return 'A phone book contact is changed from the phone book.';
    }
}
