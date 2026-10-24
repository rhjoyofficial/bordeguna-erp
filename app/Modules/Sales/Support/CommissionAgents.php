<?php

namespace App\Modules\Sales\Support;

use App\Modules\Sales\Models\CommissionAgreement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ The "Sold by / agent" picker on orders, counter bills and invoices (Joy's
 * Q18, 25 Oct 2026). It lists only the people with a live agreement (active
 * or waiting for approval, not yet ended): crediting a sale to anybody else
 * would count towards nothing. The value is "employee:12", "customer:4" or
 * "supplier:9", kept on the document as agent_type + agent_id.
 */
final class CommissionAgents
{
    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return CommissionAgreement::query()
            ->whereIn('status', [CommissionAgreement::ACTIVE, CommissionAgreement::PENDING])
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', Carbon::today()->subDays(60)->toDateString()))
            ->with(['employee', 'customer', 'supplier'])
            ->get()
            ->map(fn (CommissionAgreement $agreement) => [
                'value' => $agreement->party_type.':'.$agreement->partyId(),
                'label' => $agreement->partyName().' ('.$agreement->party_type.')',
            ])
            ->unique('value')
            ->sortBy('label')
            ->values()
            ->all();
    }

    /**
     * "supplier:9" as the two columns, or both null when empty. Refuses a
     * person who has no agreement here.
     *
     * @return array{agent_type: ?string, agent_id: ?int}
     */
    public static function parse(?string $value, string $field = 'agent'): array
    {
        if ($value === null || trim($value) === '') {
            return ['agent_type' => null, 'agent_id' => null];
        }

        [$type, $id] = array_pad(explode(':', $value, 2), 2, null);
        $column = in_array($type, CommissionAgreement::PARTY_TYPES, true) ? "{$type}_id" : null;

        if ($column === null || ! ctype_digit((string) $id) || ! CommissionAgreement::query()->where('party_type', $type)->where($column, (int) $id)->exists()) {
            throw ValidationException::withMessages([$field => 'Choose a person who has a commission agreement.']);
        }

        return ['agent_type' => $type, 'agent_id' => (int) $id];
    }

    /**
     * Writes the agent onto the order or invoice just made, inside the
     * caller's transaction, and hands it back. Nothing chosen = nothing written.
     *
     * @template T of Model
     *
     * @param  T  $document
     * @param  array{agent_type: ?string, agent_id: ?int}  $agent
     * @return T
     */
    public static function stamp(Model $document, array $agent, bool $clear = false): Model
    {
        if ($agent['agent_type'] !== null || $clear) {
            $document->forceFill($agent)->save();
        }

        return $document;
    }

    /** The picker value of a document's agent columns. */
    public static function valueOf(?string $type, mixed $id): ?string
    {
        return $type === null || $id === null ? null : "{$type}:{$id}";
    }
}
