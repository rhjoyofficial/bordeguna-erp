<?php

namespace App\Modules\Assets\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assets\Models\CompanyAccount;
use App\Modules\Assets\Models\CompanyAccountAccess;
use App\Modules\Hrm\Models\Employee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * My accounts (slice 4): the accounts the signed-in employee has ACTIVE
 * access to, with their logins under the reveal policy. Self-scope like My
 * assets: no permission, only one's own rows, nothing written here.
 */
class MyAccountsController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        $employee = Employee::forUser($request->user());

        if ($employee === null) {
            return redirect()->route('dashboard')->with('error', 'Your account is not linked to an employee record.');
        }

        $rows = CompanyAccountAccess::query()
            ->where('employee_id', $employee->id)->whereNull('revoked_on')
            ->with(['account' => fn ($query) => $query->withoutGlobalScopes()->with(['responsible:id,first_name,last_name', 'business:id,name'])])
            ->get()
            // ⚠ A closed account is no longer anyone's to use.
            ->filter(fn (CompanyAccountAccess $row) => $row->account !== null && ! $row->account->isClosed())
            ->sortBy(fn (CompanyAccountAccess $row) => $row->account->kind->value.' '.$row->account->name)
            ->values()
            ->map(function (CompanyAccountAccess $row) use ($request) {
                /** @var CompanyAccount $account */
                $account = $row->account;

                return [
                    'id' => $account->id,
                    'kind_label' => $account->kind->label(),
                    'name' => $account->name,
                    'provider' => $account->provider,
                    'identifier' => $account->identifier,
                    'url' => $account->url,
                    'responsible' => $account->responsible?->fullName(),
                    'business' => $account->business?->name,
                    'role_label' => $row->role->label(),
                    'since' => $row->granted_on->toDateString(),
                    'status_label' => $account->status->label(),
                    'credentials' => AssetCredentialController::cardFor($request, $account),
                ];
            });

        return Inertia::render('hrm/my-accounts/index', ['accounts' => $rows]);
    }
}
