<?php

namespace App\Modules\Crm\Support;

use App\Models\User;
use App\Modules\Crm\Models\Contact;
use App\Modules\Foundation\Support\DuplicateKeys;
use App\Modules\Foundation\Support\ListDuplicateRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ B13 (24 Oct 2026; FINAL-PLAN 5 "contact: same phone or same name"): the
 * phone book's quick add asks "Possible duplicate" before saving. Until now the
 * popup saved first and only said afterwards that the number was filed twice.
 * The bulk popup and the file import keep their own flag-in-the-preview way.
 */
class ContactDuplicateRule extends ListDuplicateRule
{
    public function key(): string
    {
        return 'crm.contact';
    }

    protected function keysOf(array $payload): array
    {
        return ['phone' => DuplicateKeys::phone($payload['phone'] ?? null), 'name' => DuplicateKeys::name($payload['name'] ?? null)];
    }

    protected function rows(int $businessId): Builder
    {
        return Contact::query()->where('business_id', $businessId)
            ->select(['id', 'business_id', 'name', 'company', 'phone', 'is_active', 'created_at']);
    }

    protected function rowKeys(Model $row): array
    {
        /** @var Contact $row */
        return ['phone' => DuplicateKeys::phone($row->phone), 'name' => DuplicateKeys::name($row->name)];
    }

    protected function describe(Model $row, string $matched): string
    {
        /** @var Contact $row */
        $company = $row->company ? " ({$row->company})" : '';

        return $matched === 'phone'
            ? "The phone {$row->phone} is already in the phone book: {$row->name}{$company}"
            : "Somebody with the same name is already in the phone book: {$row->name}{$company}";
    }

    protected function numberOf(Model $row): string
    {
        return (string) $row->name;
    }

    protected function urlOf(Model $row): ?string
    {
        return route('crm.contacts.index', ['search' => $row->name]);
    }

    protected function visibleTo(User $viewer): bool
    {
        return $viewer->can('crm.contacts.view');
    }
}
