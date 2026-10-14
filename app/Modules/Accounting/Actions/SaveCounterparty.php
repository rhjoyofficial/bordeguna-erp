<?php

namespace App\Modules\Accounting\Actions;

use App\Models\User;
use App\Modules\Accounting\Enums\CounterpartyKind;
use App\Modules\Accounting\Models\Counterparty;
use App\Modules\Accounting\Support\CounterpartyIdentity;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Creates or changes a counterparty: the Counterparties screen and the quick
 * "New counterparty" dialog inside a voucher both come through here, so the
 * two can never accept different things.
 *
 * ⚠ Refused, each with a sentence that says what to do instead:
 *  - one of the group's own businesses (use Inter-business transfers);
 *  - the same TIN, or the same name and phone, as another counterparty
 *    (open that one instead);
 *  - a customer, supplier or employee already linked to another counterparty
 *    (one identity, one row).
 */
class SaveCounterparty
{
    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function handle(array $input, User $by, ?Counterparty $counterparty = null): Counterparty
    {
        $data = $this->validated($input);

        if (($business = CounterpartyIdentity::ownBusinessNamed($data['legal_name'])) !== null) {
            throw ValidationException::withMessages(['legal_name' => CounterpartyIdentity::ownBusinessRefusal($data['legal_name'], $business)]);
        }

        if (($duplicate = CounterpartyIdentity::duplicateOf($data['legal_name'], $data['phone'], $data['tin'], $counterparty?->id)) !== null) {
            [$matched, $existing] = $duplicate;
            $off = $existing->is_active ? '' : ' (deactivated; reactivate it instead)';

            throw ValidationException::withMessages($matched === 'tin'
                ? ['tin' => "TIN {$data['tin']} already belongs to {$existing->legal_name}{$off}. Open that counterparty instead of adding the same party twice."]
                : ['legal_name' => "{$existing->legal_name} with phone {$existing->phone} is already a counterparty{$off}. Open it instead of adding the same party twice."]);
        }

        foreach (['customer_id' => Customer::class, 'supplier_id' => Supplier::class, 'employee_id' => Employee::class] as $field => $class) {
            $data[$field] = $this->link($field, $class, $data[$field], $counterparty);
        }

        if (! $data['related_party']) {
            $data['relationship'] = null;
        }

        return DB::transaction(function () use ($data, $by, $counterparty) {
            $counterparty ??= new Counterparty;
            $counterparty->fill($data);

            if (! $counterparty->exists) {
                $counterparty->created_by = $by->id;
            }

            $counterparty->updated_by = $by->id;
            $counterparty->save();

            return $counterparty->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function validated(array $input): array
    {
        $input = array_map(fn ($value) => is_string($value) && trim($value) === '' ? null : (is_string($value) ? trim($value) : $value), $input);

        $data = Validator::make($input, [
            'legal_name' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::enum(CounterpartyKind::class)],
            'related_party' => ['nullable', 'boolean'],
            'relationship' => ['nullable', 'string', 'max:1000', 'required_if_accepted:related_party'],
            'tin' => ['nullable', 'string', 'max:40'],
            'bin' => ['nullable', 'string', 'max:40'],
            'registration_no' => ['nullable', 'string', 'max:60'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
            'bank_details' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'customer_id' => ['nullable', 'integer'],
            'supplier_id' => ['nullable', 'integer'],
            'employee_id' => ['nullable', 'integer'],
        ], [
            'relationship.required_if_accepted' => 'Say how this party is related to the business, for example "the owner\'s brother" or "the owner holds 60%".',
        ])->validate();

        $data['related_party'] = (bool) ($data['related_party'] ?? false);

        foreach (['relationship', 'tin', 'bin', 'registration_no', 'phone', 'email', 'address', 'bank_details', 'notes', 'customer_id', 'supplier_id', 'employee_id'] as $optional) {
            $data[$optional] ??= null;
        }

        return $data;
    }

    /**
     * The customer, supplier or employee this counterparty also is.
     *
     * ⚠ A NEW link must be someone in THIS business's list (customers and
     * suppliers are per business). A link made from another business is kept
     * as it is when the form sends it back unchanged.
     *
     * @param  class-string<Model>  $class
     */
    private function link(string $field, string $class, mixed $id, ?Counterparty $counterparty): ?int
    {
        if ($id === null) {
            return null;
        }

        $id = (int) $id;
        $noun = strtolower(class_basename($class));

        if ($counterparty === null || (int) $counterparty->{$field} !== $id) {
            if ($class::query()->find($id) === null) {
                throw ValidationException::withMessages([$field => "That {$noun} is not in this business's list."]);
            }
        }

        $taken = Counterparty::query()->where($field, $id)->when($counterparty !== null, fn ($query) => $query->whereKeyNot($counterparty->id))->first();

        if ($taken !== null) {
            throw ValidationException::withMessages([$field => "That {$noun} is already linked to {$taken->legal_name}. One {$noun}, one counterparty."]);
        }

        return $id;
    }
}
