<?php

namespace App\Modules\Assets\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Support\Money;
use App\Modules\Assets\Actions\SaveCompanyAccount;
use App\Modules\Assets\Enums\AccountAccessRole;
use App\Modules\Assets\Enums\CompanyAccountKind;
use App\Modules\Assets\Enums\CompanyAccountStatus;
use App\Modules\Assets\Models\CompanyAccount;
use App\Modules\Assets\Models\CompanyAccountAccess;
use App\Modules\Assets\Support\CompanyAccountFile;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Hrm\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The accounts hub (asset register slice 4, 13 October 2026): every account
 * the business owns, who is responsible, who has access, when it renews.
 *
 * ⚠ Reading is `assets.accounts.view` (Owner, Manager, IT Custodian); writing
 * is `assets.accounts.manage` (Owner, IT Custodian), Joy's answer 2. The
 * logins of an account are the Logins card, under CredentialAccess.
 */
class CompanyAccountController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'kind' => ['nullable', Rule::enum(CompanyAccountKind::class)],
            'status' => ['nullable', Rule::enum(CompanyAccountStatus::class)],
            'owner' => ['nullable', 'integer'],
            'renewing' => ['nullable', 'boolean'],
        ]);

        $today = Carbon::today();

        $accounts = CompanyAccount::query()
            ->with(['owner:id,first_name,last_name,employee_code'])
            ->withCount('activeAccess')
            ->when($filters['q'] ?? null, function (Builder $query, string $q) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
                $query->where(fn (Builder $inner) => $inner
                    ->where('name', 'like', $like)->orWhere('provider', 'like', $like)
                    ->orWhere('identifier', 'like', $like)->orWhere('url', 'like', $like));
            })
            ->when($filters['kind'] ?? null, fn (Builder $query, $kind) => $query->where('kind', $kind))
            ->when($filters['status'] ?? null, fn (Builder $query, $status) => $query->where('status', $status))
            // ⚠ Closed accounts leave the working list unless asked for.
            ->when(! ($filters['status'] ?? null), fn (Builder $query) => $query->open())
            ->when($filters['owner'] ?? null, fn (Builder $query, $id) => $query->where('owner_employee_id', $id))
            ->when($filters['renewing'] ?? null, fn (Builder $query) => $query->whereNotNull('renews_on')->whereDate('renews_on', '<=', $today->copy()->addDays(CompanyAccount::REMIND_DAYS_FIRST)->toDateString()))
            ->orderBy('kind')->orderBy('name')
            ->paginate(30)
            ->withQueryString();

        $accounts->getCollection()->transform(fn (CompanyAccount $account) => [
            'id' => $account->id,
            'kind' => $account->kind->value,
            'kind_label' => $account->kind->label(),
            'name' => $account->name,
            'provider' => $account->provider,
            'identifier' => $account->identifier,
            'url' => $account->url,
            'owner' => $account->owner?->fullName(),
            'status' => $account->status->value,
            'status_label' => $account->status->label(),
            'renews_on' => $account->renews_on?->toDateString(),
            'renewal_tone' => $account->renewalTone($today),
            'access_count' => $account->active_access_count,
        ]);

        return Inertia::render('assets/accounts/index', [
            'accounts' => $accounts,
            'filters' => $filters,
            'kinds' => CompanyAccountKind::options(),
            'statuses' => CompanyAccountStatus::options(),
            'owners' => $this->employees(),
            'can_manage' => $request->user()->can('assets.accounts.manage'),
            'counts' => [
                'open' => CompanyAccount::query()->open()->count(),
                'renewing' => CompanyAccount::query()->open()->whereNotNull('renews_on')
                    ->whereDate('renews_on', '<=', $today->copy()->addDays(CompanyAccount::REMIND_DAYS_FIRST)->toDateString())->count(),
                'overdue' => CompanyAccount::query()->open()->whereNotNull('renews_on')->whereDate('renews_on', '<', $today->toDateString())->count(),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('assets/accounts/form', $this->formOptions() + ['account' => null]);
    }

    public function store(Request $request, SaveCompanyAccount $save): RedirectResponse
    {
        $account = $save->create($this->validated($request), $request->user());

        return redirect()->route('assets.accounts.show', $account)->with('success', "{$account->name} recorded.");
    }

    public function show(Request $request, CompanyAccount $account): Response
    {
        $account->load(['owner:id,first_name,last_name,employee_code', 'twoFactorHolder:id,first_name,last_name,employee_code', 'renewalMoneyAccount:id,name', 'createdBy:id,name',
            'access.employee:id,first_name,last_name,employee_code', 'access.grantedBy:id,name']);
        $today = Carbon::today();
        $canManage = $request->user()->can('assets.accounts.manage');

        $history = Activity::query()
            ->where('subject_type', $account->getMorphClass())
            ->where('subject_id', $account->id)
            ->with('causer')
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (Activity $entry) => [
                'id' => $entry->id,
                'when' => $entry->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i'),
                'who' => $entry->causer?->name,
                'what' => $entry->description,
                'changes' => collect($entry->properties['attributes'] ?? [])
                    ->map(fn ($new, $field) => [
                        'field' => self::FIELD_LABELS[$field] ?? $field,
                        'from' => $this->shown($field, ($entry->properties['old'] ?? [])[$field] ?? null),
                        'to' => $this->shown($field, $new),
                    ])
                    ->values(),
            ]);

        $held = $account->access->whereNull('revoked_on')->pluck('employee_id')->all();

        return Inertia::render('assets/accounts/show', [
            'account' => [
                'id' => $account->id,
                'kind' => $account->kind->value,
                'kind_label' => $account->kind->label(),
                'name' => $account->name,
                'provider' => $account->provider,
                'identifier' => $account->identifier,
                'url' => $account->url,
                'owner' => $account->owner ? $account->owner->fullName().' ('.$account->owner->employee_code.')' : null,
                'two_factor_holder' => $account->twoFactorHolder ? $account->twoFactorHolder->fullName().' ('.$account->twoFactorHolder->employee_code.')' : null,
                'renews_on' => $account->renews_on?->toDateString(),
                'renewal_stage' => $account->renewalStage($today),
                'renewal_tone' => $account->renewalTone($today),
                'renewal_cost' => $account->renewal_cost !== null ? Money::ofTaka((string) $account->renewal_cost)->toTaka() : null,
                'paid_from' => $account->renewalMoneyAccount?->name,
                'recovery_email' => $account->recovery_email,
                'recovery_phone' => $account->recovery_phone,
                'status' => $account->status->value,
                'status_label' => $account->status->label(),
                'note' => $account->note,
                'recorded_by' => $account->createdBy?->name,
                'recorded_at' => $account->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i'),
            ],
            'access' => $account->access->sortByDesc('id')->values()->map(fn (CompanyAccountAccess $row) => [
                'id' => $row->id,
                'employee' => $row->employee?->fullName(),
                'employee_code' => $row->employee?->employee_code,
                'role' => $row->role->value,
                'role_label' => $row->role->label(),
                'granted_on' => $row->granted_on->toDateString(),
                'revoked_on' => $row->revoked_on?->toDateString(),
                'granted_by' => $row->grantedBy?->name,
                'note' => $row->note,
            ]),
            // Employees who could be given access: of this business, not already holding it.
            'grantable' => $this->employees()->reject(fn (array $employee) => in_array($employee['id'], $held, true))->values(),
            'roles' => AccountAccessRole::options(),
            'credentials' => AssetCredentialController::cardFor($request, $account),
            'history' => $history,
            'can_manage' => $canManage,
            'today' => $today->toDateString(),
        ]);
    }

    public function edit(Request $request, CompanyAccount $account): Response
    {
        return Inertia::render('assets/accounts/form', $this->formOptions($account) + [
            'account' => [
                'id' => $account->id,
                'kind' => $account->kind->value,
                'name' => $account->name,
                'provider' => $account->provider,
                'identifier' => $account->identifier,
                'url' => $account->url,
                'owner_employee_id' => $account->owner_employee_id,
                'two_factor_employee_id' => $account->two_factor_employee_id,
                'renews_on' => $account->renews_on?->toDateString(),
                'renewal_cost' => $account->renewal_cost !== null ? Money::ofTaka((string) $account->renewal_cost)->toTaka() : null,
                'renewal_money_account_id' => $account->renewal_money_account_id,
                'recovery_email' => $account->recovery_email,
                'recovery_phone' => $account->recovery_phone,
                'status' => $account->status->value,
                'note' => $account->note,
            ],
        ]);
    }

    public function update(Request $request, CompanyAccount $account, SaveCompanyAccount $save): RedirectResponse
    {
        $save->update($account, $this->validated($request));

        return redirect()->route('assets.accounts.show', $account)->with('success', "{$account->name} saved.");
    }

    public function grantAccess(Request $request, CompanyAccount $account, SaveCompanyAccount $save): RedirectResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer'],
            'role' => ['required', Rule::enum(AccountAccessRole::class)],
            'granted_on' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $save->grant($account, (int) $data['employee_id'], AccountAccessRole::from($data['role']), $data['granted_on'], $data['note'] ?? null, $request->user());

        return back()->with('success', 'Access granted.');
    }

    public function revokeAccess(Request $request, CompanyAccount $account, CompanyAccountAccess $access, SaveCompanyAccount $save): RedirectResponse
    {
        abort_unless($access->company_account_id === $account->id, 404);

        $data = $request->validate(['revoked_on' => ['required', 'date']]);

        $save->revoke($account, $access, $data['revoked_on'], $request->user());

        return back()->with('success', 'Access revoked.');
    }

    public function markRenewed(Request $request, CompanyAccount $account, SaveCompanyAccount $save): RedirectResponse
    {
        $data = $request->validate([
            'next_renews_on' => ['required', 'date'],
            'renewal_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        $save->markRenewed($account, $data['next_renews_on'], isset($data['renewal_cost']) ? (string) $data['renewal_cost'] : null, $request->user());

        return back()->with('success', "Renewed. Next renewal {$data['next_renews_on']}.");
    }

    /**
     * The hub in the importer's own shape, so the round trip is export, edit,
     * import. ⛔ No logins: they are never in a file, by design.
     */
    public function export(): StreamedResponse
    {
        $accounts = CompanyAccount::query()
            ->with(['owner:id,employee_code', 'twoFactorHolder:id,employee_code', 'activeAccess.employee:id,employee_code'])
            ->orderBy('kind')->orderBy('name')->get();

        return response()->streamDownload(function () use ($accounts) {
            $out = fopen('php://output', 'w');

            // ⚠⚠ UTF-8 BOM, or Excel on Windows shows every Bangla name as mojibake.
            fwrite($out, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($out, CompanyAccountFile::TEMPLATE);

            foreach ($accounts as $account) {
                fputcsv($out, [
                    $account->kind->value,
                    $account->name,
                    $account->provider,
                    $account->identifier,
                    $account->url,
                    $account->owner?->employee_code,
                    $account->activeAccess->map(fn (CompanyAccountAccess $row) => $row->employee?->employee_code)->filter()->implode(';'),
                    $account->renews_on?->toDateString(),
                    $account->renewal_cost !== null ? rtrim(rtrim((string) $account->renewal_cost, '0'), '.') : '',
                    $account->status->value,
                    $account->recovery_email,
                    $account->recovery_phone,
                    $account->twoFactorHolder?->employee_code,
                    $account->note,
                ]);
            }

            fclose($out);
        }, 'company-accounts-'.now()->toDateString().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'kind' => ['required', Rule::enum(CompanyAccountKind::class)],
            'name' => ['required', 'string', 'max:120'],
            'provider' => ['nullable', 'string', 'max:120'],
            'identifier' => ['nullable', 'string', 'max:190'],
            'url' => ['nullable', 'string', 'max:255'],
            'owner_employee_id' => ['nullable', 'integer'],
            'two_factor_employee_id' => ['nullable', 'integer'],
            'renews_on' => ['nullable', 'date'],
            'renewal_cost' => ['nullable', 'numeric', 'min:0'],
            'renewal_money_account_id' => ['nullable', 'integer'],
            'recovery_email' => ['nullable', 'email', 'max:190'],
            'recovery_phone' => ['nullable', 'string', 'max:30'],
            'status' => ['required', Rule::enum(CompanyAccountStatus::class)],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);

        // ⚠ `nullable` leaves a never-sent key absent; the model wants null.
        foreach (['provider', 'identifier', 'url', 'owner_employee_id', 'two_factor_employee_id', 'renews_on', 'renewal_cost', 'renewal_money_account_id', 'recovery_email', 'recovery_phone', 'note'] as $key) {
            $data[$key] = ($data[$key] ?? null) === '' ? null : ($data[$key] ?? null);
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(?CompanyAccount $editing = null): array
    {
        return [
            'kinds' => CompanyAccountKind::options(),
            'statuses' => CompanyAccountStatus::options(),
            'employees' => $this->employees(),
            'money_accounts' => MoneyAccount::query()
                ->where(fn (Builder $query) => $query->where('is_active', true)->when($editing?->renewal_money_account_id, fn (Builder $q, $id) => $q->orWhere('id', $id)))
                ->orderBy('name')->get(['id', 'name'])
                ->map(fn (MoneyAccount $money) => ['id' => $money->id, 'name' => $money->name]),
        ];
    }

    /**
     * Employees of this business, as the hand-over form lists them.
     *
     * @return Collection<int, array{id: int, label: string}>
     */
    private function employees()
    {
        return Employee::query()->where('status', '!=', 'separated')
            ->whereHas('assignments')->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'employee_code'])
            ->map(fn (Employee $employee) => ['id' => $employee->id, 'label' => $employee->fullName().' ('.$employee->employee_code.')']);
    }

    private function shown(string $field, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($field) {
            'owner_employee_id', 'two_factor_employee_id' => Employee::query()->whereKey($value)->first()?->fullName() ?? "employee #{$value}",
            'renewal_money_account_id' => MoneyAccount::query()->withoutGlobalScopes()->whereKey($value)->value('name') ?? "account #{$value}",
            'kind' => CompanyAccountKind::tryFrom((string) $value)?->label() ?? (string) $value,
            'status' => CompanyAccountStatus::tryFrom((string) $value)?->label() ?? (string) $value,
            // ⚠ The log keeps a date as a UTC timestamp: read it in Dhaka, as on the item page.
            'renews_on' => Carbon::parse((string) $value)->timezone(config('app.timezone'))->toDateString(),
            default => is_scalar($value) ? (string) $value : json_encode($value),
        };
    }

    private const FIELD_LABELS = [
        'kind' => 'Kind',
        'name' => 'Name',
        'provider' => 'Provider',
        'identifier' => 'Identifier',
        'url' => 'URL',
        'owner_employee_id' => 'Owner',
        'two_factor_employee_id' => '2FA holder',
        'renews_on' => 'Renews on',
        'renewal_cost' => 'Renewal cost',
        'renewal_money_account_id' => 'Paid from',
        'recovery_email' => 'Recovery email',
        'recovery_phone' => 'Recovery phone',
        'status' => 'Status',
    ];
}
