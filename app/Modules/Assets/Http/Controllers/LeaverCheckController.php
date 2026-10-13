<?php

namespace App\Modules\Assets\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assets\Models\AssetCredential;
use App\Modules\Assets\Models\AssetHolding;
use App\Modules\Assets\Models\AssetItem;
use App\Modules\Assets\Models\CompanyAccount;
use App\Modules\Assets\Models\CompanyAccountAccess;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Models\Employee;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The leaver check (review of 13 October 2026, ruling 10): everything one
 * employee holds, is responsible for, has access to or guards, on one page,
 * each with the link to the screen that acts on it.
 *
 * ⚠ READ-ONLY. It changes nothing itself: returning a thing is a hand-over,
 * revoking access is the account's page, changing who is responsible or who
 * keeps the 2FA device is the account's form. This page is the checklist,
 * and the checklist is what an offboarding has always lacked.
 *
 * ⛔ No secret, no hint. The logins section names what the person COULD
 * reveal as a holder, so the manager knows which passwords to change.
 */
class LeaverCheckController extends Controller
{
    public function index(Request $request): Response
    {
        $request->validate(['employee' => ['nullable', 'integer']]);

        $businessId = (int) app(BusinessContext::class)->id();

        $employees = Employee::query()
            ->whereHas('assignments')
            ->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'employee_code', 'status'])
            ->map(fn (Employee $employee) => [
                'id' => $employee->id,
                'label' => $employee->fullName().' ('.$employee->employee_code.')'.($employee->status === 'separated' ? ' - left' : ''),
            ])->values();

        $employee = $request->query('employee') ? Employee::query()->whereHas('assignments')->find((int) $request->query('employee')) : null;

        return Inertia::render('assets/accounts/leaver-check', [
            'employees' => $employees,
            'employee' => $employee ? [
                'id' => $employee->id,
                'name' => $employee->fullName(),
                'code' => $employee->employee_code,
                'status' => $employee->status,
            ] : null,
            'sections' => $employee ? $this->sections($employee, $businessId) : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function sections(Employee $employee, int $businessId): array
    {
        // (a) Things they hold: tagged things by custodian, counted groups by holding.
        $items = AssetItem::query()->where('custodian_employee_id', $employee->id)
            ->orderBy('tag')->get(['id', 'tag', 'name', 'status']);
        $heldGroups = AssetHolding::query()->where('employee_id', $employee->id)->where('quantity', '>', 0)
            ->with('item:id,tag,name,status')->get();

        $things = $items->map(fn (AssetItem $item) => [
            'id' => $item->id,
            'tag' => $item->tag,
            'name' => $item->name,
            'quantity' => 1,
            'status' => $item->status->label(),
            'return_url' => '/assets/movements/create?kind=return&items[]='.$item->id,
        ])->concat($heldGroups->filter(fn (AssetHolding $holding) => $holding->item !== null)->map(fn (AssetHolding $holding) => [
            'id' => $holding->item->id,
            'tag' => $holding->item->tag,
            'name' => $holding->item->name,
            'quantity' => $holding->quantity,
            'status' => $holding->item->status->label(),
            'return_url' => '/assets/movements/create?kind=return&items[]='.$holding->item->id,
        ]))->values();

        $accountRow = fn (CompanyAccount $account, array $extra = []) => [
            'id' => $account->id,
            'name' => $account->name,
            'kind_label' => $account->kind->label(),
            'provider' => $account->provider,
            'home' => $account->isHomeOf($businessId) ? null : $account->business?->name,
            'url' => "/assets/accounts/{$account->id}",
            'edit_url' => $account->isHomeOf($businessId) ? "/assets/accounts/{$account->id}/edit" : null,
        ] + $extra;

        // (b) Accounts they are responsible for.
        $responsible = CompanyAccount::query()->visibleTo($businessId)->open()
            ->where('responsible_employee_id', $employee->id)->with('business:id,name')->orderBy('name')->get();

        // (c) Accounts they have active access to, with the role.
        $accessRows = CompanyAccountAccess::query()->where('employee_id', $employee->id)->whereNull('revoked_on')
            ->with(['account' => fn ($query) => $query->visibleTo($businessId)->with('business:id,name')])
            ->get()->filter(fn (CompanyAccountAccess $row) => $row->account !== null && ! $row->account->isClosed())
            ->sortBy(fn (CompanyAccountAccess $row) => $row->account->name)->values();

        // (d) Accounts whose second factor they keep.
        $twoFactor = CompanyAccount::query()->visibleTo($businessId)->open()
            ->where('two_factor_employee_id', $employee->id)->with('business:id,name')->orderBy('name')->get();

        // (e) Logins they could reveal as a holder: of the things they hold and
        // the accounts they have access to, where the policy lets a holder in.
        // ⚠ Never the privileged ones: a holder cannot open those, so they are
        // not what this person knows by right. What they know by sight is
        // another matter, and the manager's call.
        $ownerKeys = $things->map(fn (array $thing) => [AssetItem::class, $thing['id']])
            ->concat($accessRows->map(fn (CompanyAccountAccess $row) => [CompanyAccount::class, $row->company_account_id]));

        $credentials = AssetCredential::query()->withoutGlobalScopes()
            ->where('holder_may_reveal', true)
            ->whereIn('kind', ['user_login', 'other'])
            ->where(function ($query) use ($ownerKeys) {
                $query->whereRaw('1 = 0');

                foreach ($ownerKeys as [$type, $id]) {
                    $query->orWhere(fn ($inner) => $inner->where('credentialable_type', $type)->where('credentialable_id', $id));
                }
            })
            ->orderBy('label')->get();

        $thingNames = $things->keyBy('id');
        $accountNames = $accessRows->keyBy('company_account_id')->map(fn (CompanyAccountAccess $row) => $row->account);

        $logins = $credentials->map(function (AssetCredential $credential) use ($thingNames, $accountNames) {
            $isThing = $credential->credentialable_type === AssetItem::class;
            $thing = $isThing ? $thingNames->get($credential->credentialable_id) : null;
            $account = $isThing ? null : $accountNames->get($credential->credentialable_id);

            return [
                'id' => $credential->id,
                'label' => $credential->label,
                'kind_label' => $credential->kind->label(),
                'needs_review' => $credential->needs_review,
                'last_rotated_at' => $credential->last_rotated_at?->timezone(config('app.timezone'))->format('Y-m-d H:i'),
                'owner' => $isThing ? ($thing ? $thing['tag'].' '.$thing['name'] : '-') : ($account?->name ?? '-'),
                'owner_url' => $isThing ? "/assets/items/{$credential->credentialable_id}" : "/assets/accounts/{$credential->credentialable_id}",
            ];
        })->values();

        return [
            'things' => $things,
            'responsible' => $responsible->map(fn (CompanyAccount $account) => $accountRow($account))->values(),
            'access' => $accessRows->map(fn (CompanyAccountAccess $row) => $accountRow($row->account, [
                'role_label' => $row->role->label(),
                'since' => $row->granted_on->toDateString(),
            ]))->values(),
            'two_factor' => $twoFactor->map(fn (CompanyAccount $account) => $accountRow($account, [
                'method_label' => $account->two_factor_method->label(),
            ]))->values(),
            'logins' => $logins,
        ];
    }
}
