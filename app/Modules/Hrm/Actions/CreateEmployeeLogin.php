<?php

namespace App\Modules\Hrm\Actions;

use App\Models\User;
use App\Modules\Hrm\Models\Employee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Gives an employee their way in: a user account joined to their employee
 * record so the self-service screens ("my profile", "my payslips") can find
 * them at all. Without the join a login is an empty shell, because every
 * self-service screen resolves through Employee::forUser().
 *
 * The account is created with a password nobody knows and nobody needs: the
 * caller emails a set-password link straight after, and the employee chooses
 * their own.
 */
class CreateEmployeeLogin
{
    public const SELF_SERVICE_ROLE = 'Self-service';

    /**
     * @return array{user: User, role_assigned: bool, reused_existing_account: bool}
     */
    public function handle(Employee $employee, string $email, int $businessId): array
    {
        return DB::transaction(function () use ($employee, $email, $businessId) {
            $existing = User::where('email', $email)->first();

            if ($existing !== null) {
                // An address already in use is either this person under a
                // login made earlier, or somebody else. Joining the wrong two
                // records would hand one employee's payslips to another, so
                // this refuses rather than guesses.
                $claimedBy = Employee::where('user_id', $existing->id)
                    ->where('id', '!=', $employee->id)->first();

                if ($claimedBy !== null) {
                    throw ValidationException::withMessages([
                        'email' => "That address already logs in as {$claimedBy->fullName()} ({$claimedBy->employee_code}). Use a different address.",
                    ]);
                }
            }

            $user = $existing ?? User::create([
                'name' => $employee->fullName(),
                // Never communicated to anyone. The set-password link that
                // follows is the only way into this account.
                'password' => bcrypt(Str::password(40)),
                'email' => $email,
            ]);

            if (! $user->businesses()->where('businesses.id', $businessId)->exists()) {
                $user->businesses()->attach($businessId);
            }

            // Self-service is the floor: their own profile and nothing else.
            // Anything more is a deliberate grant on Settings > Team.
            $roleAssigned = false;

            if ($user->getRoleNames()->isEmpty()) {
                $role = Role::where('business_id', $businessId)
                    ->where('name', self::SELF_SERVICE_ROLE)->first();

                if ($role !== null) {
                    $user->assignRole($role->name);
                    $roleAssigned = true;
                }
            }

            $employee->user_id = $user->id;

            // HR asked for these addresses for months; the one just typed is
            // the answer, so it lands on the record too rather than living
            // only on the login.
            if (blank($employee->personal_email)) {
                $employee->personal_email = $email;
            }

            $employee->save();

            return [
                'user' => $user,
                'role_assigned' => $roleAssigned,
                'reused_existing_account' => $existing !== null,
            ];
        });
    }
}
