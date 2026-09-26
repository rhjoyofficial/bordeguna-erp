<?php

namespace App\Modules\Sales\Actions;

use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Models\Customer;

/**
 * Reads the credit terms file against the customers as they are NOW and says,
 * row by row, what would change and what is refused. Writes nothing.
 *
 * ⚠ Refused, not warned: a code nobody has, a walk-in or retail (consumer)
 * customer, a limit that is not a number, days outside 0-365, the same code
 * twice. A row that changes nothing is reported as unchanged and left out of
 * the request, so the approver reads only real changes.
 */
class PlanCreditTermsRequest
{
    public const CREDIT_TYPES = [CustomerType::Shop, CustomerType::Dealer, CustomerType::Distributor, CustomerType::Corporate];

    /**
     * @param  list<array{line:int, values:array<string,string>}>  $rows
     * @return array{
     *     changes: list<array{line:int, customer_id:int, code:string, name:string, current_limit:?string, current_days:?int, new_limit:?string, new_days:?int, limit_changes:bool, days_changes:bool}>,
     *     unchanged: list<array{line:int, code:string, name:string}>,
     *     errors: list<array{line:int, code:string, message:string}>
     * }
     */
    public function handle(array $rows): array
    {
        $changes = [];
        $unchanged = [];
        $errors = [];
        $seen = [];

        $customers = Customer::query()->whereNotNull('code')->get()->keyBy(fn (Customer $c) => strtoupper((string) $c->code));

        foreach ($rows as $row) {
            $line = $row['line'];
            $values = $row['values'];
            $code = strtoupper(trim($values['code'] ?? ''));

            if ($code === '') {
                $errors[] = ['line' => $line, 'code' => '', 'message' => 'The code is blank. Every row names one customer by code.'];

                continue;
            }

            if (isset($seen[$code])) {
                $errors[] = ['line' => $line, 'code' => $code, 'message' => "Code {$code} appears twice in the file. Keep one row per customer."];

                continue;
            }

            $seen[$code] = true;
            $customer = $customers->get($code);

            if ($customer === null) {
                $errors[] = ['line' => $line, 'code' => $code, 'message' => "No customer has the code {$code}. Codes come from the Customers screen; this file does not create customers."];

                continue;
            }

            if (! in_array($customer->type, self::CREDIT_TYPES, true)) {
                $errors[] = ['line' => $line, 'code' => $code, 'message' => "{$customer->name} is a {$customer->type?->label()}: only shops, dealers, distributors and corporate buyers carry credit terms."];

                continue;
            }

            $newLimitGiven = trim($values['new_limit'] ?? '');
            $newDaysGiven = trim($values['new_days'] ?? '');

            if ($newLimitGiven === '' && $newDaysGiven === '') {
                $unchanged[] = ['line' => $line, 'code' => $code, 'name' => $customer->name];

                continue;
            }

            $newLimit = $customer->credit_limit === null ? null : (string) $customer->credit_limit;

            if ($newLimitGiven !== '') {
                $cleaned = str_replace([',', ' '], '', $newLimitGiven);

                if (! is_numeric($cleaned) || (float) $cleaned < 0) {
                    $errors[] = ['line' => $line, 'code' => $code, 'message' => 'new_limit must be an amount of zero or more, digits only.'];

                    continue;
                }

                $newLimit = number_format((float) $cleaned, 4, '.', '');
            }

            $newDays = $customer->credit_days;

            if ($newDaysGiven !== '') {
                if (! ctype_digit($newDaysGiven) || (int) $newDaysGiven > 365) {
                    $errors[] = ['line' => $line, 'code' => $code, 'message' => 'new_days must be a whole number of days from 0 to 365.'];

                    continue;
                }

                $newDays = (int) $newDaysGiven;
            }

            $limitChanges = $this->limitDiffers($customer->credit_limit, $newLimit);
            $daysChanges = (int) ($customer->credit_days ?? -1) !== (int) ($newDays ?? -1);

            if (! $limitChanges && ! $daysChanges) {
                $unchanged[] = ['line' => $line, 'code' => $code, 'name' => $customer->name];

                continue;
            }

            $changes[] = [
                'line' => $line,
                'customer_id' => $customer->id,
                'code' => $code,
                'name' => $customer->name,
                'current_limit' => $customer->credit_limit === null ? null : (string) $customer->credit_limit,
                'current_days' => $customer->credit_days,
                'new_limit' => $newLimit,
                'new_days' => $newDays,
                'limit_changes' => $limitChanges,
                'days_changes' => $daysChanges,
            ];
        }

        return ['changes' => $changes, 'unchanged' => $unchanged, 'errors' => $errors];
    }

    private function limitDiffers(mixed $current, mixed $proposed): bool
    {
        if ($current === null || $proposed === null) {
            return $current !== $proposed;
        }

        return abs((float) $current - (float) $proposed) >= 0.00005;
    }
}
