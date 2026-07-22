<?php

namespace App\Http\Middleware;

use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Models\Employee;
use App\Support\ApprovalInbox;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');

        return array_merge(parent::share($request), [
            ...parent::share($request),
            'name' => config('app.name'),
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            'auth' => [
                'user' => $request->user(),
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'pendingApprovalsCount' => fn () => $this->actionableApprovalsCount($request),
            'canReviewApprovals' => fn () => $this->canReviewApprovals($request),
            'canManageUsers' => fn () => (bool) $request->user()?->can('foundation.users.manage'),
            'canManageOrganization' => fn () => (bool) $request->user()?->can('foundation.organization.manage'),
            'canManageEmployees' => fn () => (bool) $request->user()?->can('hrm.employees.manage'),
            // Self-scope (P3): whether the signed-in user has their own employee
            // record, so the "My profile" self-service link can show. Distinct
            // from canManageEmployees — an employee who isn't HR still has one.
            'hasEmployeeProfile' => fn () => $request->user() !== null
                && Employee::where('user_id', $request->user()->id)->exists(),
        ]);
    }

    private function canReviewApprovals(Request $request): bool
    {
        $user = $request->user();

        if (! $user || ! app(BusinessContext::class)->hasBusiness()) {
            return false;
        }

        // Legacy approvers keep the nav even at zero pending; anyone else sees it
        // once they actually have something to act on (a change-request stage).
        return $user->can('inventory.stock.approve')
            || $user->can('purchasing.po.approve')
            || $this->actionableApprovalsCount($request) > 0;
    }

    private function actionableApprovalsCount(Request $request): int
    {
        $user = $request->user();

        if (! $user || ! app(BusinessContext::class)->hasBusiness()) {
            return 0;
        }

        return app(ApprovalInbox::class)->countFor($user);
    }
}
