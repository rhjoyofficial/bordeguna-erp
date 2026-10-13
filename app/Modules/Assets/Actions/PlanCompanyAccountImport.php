<?php

namespace App\Modules\Assets\Actions;

use App\Modules\Assets\Enums\CompanyAccountKind;
use App\Modules\Assets\Enums\CompanyAccountStatus;
use App\Modules\Assets\Models\CompanyAccount;
use App\Modules\Assets\Support\CompanyAccountImportPlan;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Inventory\Support\CatalogueText;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Works out what an accounts sheet would do, and writes nothing (slice 4).
 *
 * - An account is matched by (kind + identifier) when the identifier is set,
 *   else by (kind + name); a row with an identifier also matches by name an
 *   account that has none yet. A match is an update; no match is a create.
 * - `owner`, `users` and `two_factor_holder` name employees of THIS business
 *   by code or exact full name. An unknown or ambiguous name stops the row:
 *   the import never invents people.
 * - `users` is `;`-separated and becomes access rows with role user for
 *   anyone who does not already hold access. The import never revokes.
 * - Unknown kind or status stops the row. One error and nothing is applied.
 *
 * ⚠ Re-derived from the live database every time, the importers' rule.
 */
class PlanCompanyAccountImport
{
    /** @var Collection<int, Employee> */
    private Collection $employees;

    /**
     * @param  list<array{line:int, values:array<string,string>}>  $rows
     */
    public function handle(array $rows): CompanyAccountImportPlan
    {
        $plan = new CompanyAccountImportPlan;

        $this->employees = Employee::query()->where('status', '!=', 'separated')->whereHas('assignments')
            ->get(['id', 'employee_code', 'first_name', 'last_name']);

        $existing = CompanyAccount::query()->with('activeAccess:id,company_account_id,employee_id')->get();
        $byIdentifier = $existing->filter(fn (CompanyAccount $a) => $a->identifier !== null)
            ->keyBy(fn (CompanyAccount $a) => $a->kind->value.'|'.mb_strtolower($a->identifier));
        $byName = $existing->keyBy(fn (CompanyAccount $a) => $a->kind->value.'|'.mb_strtolower($a->name));
        $seen = [];

        foreach ($rows as $row) {
            $line = $row['line'];
            $v = $row['values'];
            $name = $v['name'] ?? '';
            $kindRaw = mb_strtolower($v['kind'] ?? '');
            $problems = [];

            if ($name === '') {
                $plan->addError($line, '', 'The row has no name.');

                continue;
            }

            $kind = CompanyAccountKind::tryFrom($kindRaw);

            if ($kind === null) {
                $plan->addError($line, $name, "kind \"{$v['kind']}\" is not one of: ".implode(', ', array_map(fn (CompanyAccountKind $k) => $k->value, CompanyAccountKind::cases())).'.');

                continue;
            }

            $identifier = CatalogueText::nullIfBlank($v['identifier'] ?? null);
            $key = $identifier !== null ? 'id:'.$kind->value.'|'.mb_strtolower($identifier) : 'name:'.$kind->value.'|'.mb_strtolower($name);

            if (isset($seen[$key])) {
                $plan->addError($line, $name, "The same account is already on line {$seen[$key]}. Keep one row per account.");

                continue;
            }

            $seen[$key] = $line;

            $statusRaw = mb_strtolower($v['status'] ?? '');
            $status = $statusRaw === '' ? CompanyAccountStatus::Active : CompanyAccountStatus::tryFrom($statusRaw);

            if ($status === null) {
                $problems[] = "status \"{$v['status']}\" must be active, suspended or closed.";
            }

            $renewsOn = null;
            $renewsRaw = $v['renews_on'] ?? '';

            if ($renewsRaw !== '') {
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $renewsRaw) !== 1 || ! Carbon::canBeCreatedFromFormat($renewsRaw, 'Y-m-d')) {
                    $problems[] = "renews_on \"{$renewsRaw}\" must be a date written YYYY-MM-DD.";
                } else {
                    $renewsOn = $renewsRaw;
                }
            }

            $cost = null;
            $costRaw = $v['renewal_cost'] ?? '';

            if ($costRaw !== '') {
                if (! is_numeric($costRaw) || (float) $costRaw < 0) {
                    $problems[] = "renewal_cost \"{$costRaw}\" must be a number of taka, nothing else.";
                } else {
                    $cost = number_format((float) $costRaw, 4, '.', '');
                }
            }

            $owner = $this->employeeFrom($v['owner'] ?? '', 'owner', $problems);
            $twoFactor = $this->employeeFrom($v['two_factor_holder'] ?? '', 'two_factor_holder', $problems);
            $users = [];

            foreach (array_filter(array_map('trim', explode(';', $v['users'] ?? ''))) as $who) {
                $id = $this->employeeFrom($who, 'users', $problems);

                if ($id !== null && ! in_array($id, $users, true)) {
                    $users[] = $id;
                }
            }

            $email = CatalogueText::nullIfBlank($v['recovery_email'] ?? null);

            if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $problems[] = "recovery_email \"{$email}\" is not an email address.";
            }

            if ($problems !== []) {
                $plan->addError($line, $name, implode(' ', $problems));

                continue;
            }

            $data = [
                'kind' => $kind->value,
                'name' => $name,
                'provider' => CatalogueText::nullIfBlank($v['provider'] ?? null),
                'identifier' => $identifier,
                'url' => CatalogueText::nullIfBlank($v['url'] ?? null),
                'owner_employee_id' => $owner,
                'two_factor_employee_id' => $twoFactor,
                'renews_on' => $renewsOn,
                'renewal_cost' => $cost,
                'recovery_email' => $email,
                'recovery_phone' => CatalogueText::nullIfBlank($v['recovery_phone'] ?? null),
                'status' => $status->value,
                'note' => CatalogueText::nullIfBlank($v['note'] ?? null),
            ];

            // ⚠ By identifier first. A row WITH an identifier may still match
            // an account recorded without one, by name, so the identifier can
            // be filled in from the file; it never re-points an account that
            // already has a different identifier.
            $match = $identifier !== null ? $byIdentifier->get($kind->value.'|'.mb_strtolower($identifier)) : null;

            if ($match === null) {
                $candidate = $byName->get($kind->value.'|'.mb_strtolower($name));
                $match = $candidate !== null && ($identifier === null || $candidate->identifier === null) ? $candidate : null;
            }

            if ($match === null) {
                $plan->creates[] = ['line' => $line, 'name' => $name, 'kind' => $kind->label(), 'data' => $data, 'users' => $users];

                continue;
            }

            $changes = [];

            foreach ($data as $field => $to) {
                $from = $this->current($match, $field);

                if ((string) $from !== (string) $to) {
                    $changes[$field] = [$from, $to];
                }
            }

            $held = $match->activeAccess->pluck('employee_id')->all();
            $newUsers = array_values(array_diff($users, $held));

            if ($changes === [] && $newUsers === []) {
                $plan->unchanged++;

                continue;
            }

            $plan->updates[] = [
                'line' => $line,
                'id' => $match->id,
                'name' => $name,
                'kind' => $kind->label(),
                'data' => $data,
                'changes' => $changes,
                'users' => $newUsers,
                'new_users' => array_map(fn (int $id) => $this->employees->firstWhere('id', $id)?->employee_code ?? "#{$id}", $newUsers),
            ];
        }

        return $plan;
    }

    /**
     * An employee of this business by code or by exact full name; null when
     * blank. Anything else is a problem on the row.
     *
     * @param  list<string>  $problems
     */
    private function employeeFrom(string $raw, string $column, array &$problems): ?int
    {
        $raw = CatalogueText::clean($raw);

        if ($raw === '') {
            return null;
        }

        $needle = mb_strtolower($raw);
        $byCode = $this->employees->filter(fn (Employee $e) => mb_strtolower($e->employee_code) === $needle);

        if ($byCode->count() === 1) {
            return $byCode->first()->id;
        }

        $byName = $this->employees->filter(fn (Employee $e) => mb_strtolower($e->fullName()) === $needle);

        if ($byName->count() === 1) {
            return $byName->first()->id;
        }

        $problems[] = $byName->count() > 1
            ? "{$column} \"{$raw}\" names more than one employee; use the employee code."
            : "{$column} \"{$raw}\" is not an employee of this business (use the code, e.g. BDG-000016, or the exact full name).";

        return null;
    }

    private function current(CompanyAccount $account, string $field): ?string
    {
        $value = $account->getAttribute($field);

        return match ($field) {
            'kind', 'status' => $value?->value,
            'renews_on' => $value?->toDateString(),
            'renewal_cost' => $value === null ? null : number_format((float) $value, 4, '.', ''),
            default => $value === null ? null : (string) $value,
        };
    }
}
