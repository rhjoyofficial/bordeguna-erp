<?php

namespace App\Modules\Assets\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assets\Models\AssetCredential;
use App\Modules\Assets\Models\AssetItem;
use App\Modules\Assets\Models\CompanyAccount;
use App\Modules\Assets\Support\CredentialAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The logins a thing or an account carries (asset register slice 3).
 *
 * ⚠⚠ ONLY `reveal` EVER RETURNS A SECRET, as JSON, once, to somebody the
 * policy allows, after they re-typed their own password (route middleware
 * `password.confirm`) and within a throttle. Every other response is built
 * from `toSafeArray()`, a whitelist.
 *
 * ⚠ There is no edit form pre-filled with the current secret. Changing a
 * password means typing the new one; a blank secret on an edit keeps the old.
 */
class AssetCredentialController extends Controller
{
    public const MAX_SECRET = 500;

    public function storeForItem(Request $request, AssetItem $item): RedirectResponse
    {
        return $this->store($request, $item);
    }

    public function storeForAccount(Request $request, CompanyAccount $account): RedirectResponse
    {
        return $this->store($request, $account);
    }

    private function store(Request $request, Model $owner): RedirectResponse
    {
        abort_unless(CredentialAccess::mayManage($request->user(), $owner), 403);

        $data = $this->validated($request, creating: true);
        $secret = $data['secret'];
        unset($data['secret']);

        AssetCredential::create($data + [
            'business_id' => $owner->business_id,
            'credentialable_type' => $owner->getMorphClass(),
            'credentialable_id' => $owner->getKey(),
            'secret' => $secret,
            'hint' => AssetCredential::hintFor($secret),
            'created_by' => $request->user()->id,
        ]);

        // ⚠ The flash names the label only. The secret never enters the session.
        return back()->with('success', "Login \"{$data['label']}\" saved.");
    }

    public function update(Request $request, AssetCredential $credential): RedirectResponse
    {
        $owner = $credential->credentialable;
        abort_unless($owner !== null && CredentialAccess::mayManage($request->user(), $owner), 403);

        $data = $this->validated($request, creating: false);
        $secret = $data['secret'] ?? null;
        unset($data['secret']);

        if ($secret !== null && $secret !== '') {
            $data['secret'] = $secret;
            $data['hint'] = AssetCredential::hintFor($secret);
        }

        $credential->update($data + ['updated_by' => $request->user()->id]);

        return back()->with('success', "Login \"{$credential->label}\" saved.");
    }

    public function destroy(Request $request, AssetCredential $credential): RedirectResponse
    {
        $owner = $credential->credentialable;
        abort_unless($owner !== null && CredentialAccess::mayManage($request->user(), $owner), 403);

        $label = $credential->label;
        $credential->delete();

        return back()->with('success', "Login \"{$label}\" removed.");
    }

    /**
     * The secret, once, as JSON.
     *
     * ⚠⚠ Three gates before this line runs: the route's `password.confirm`
     * (423 for a JSON request until the person re-typed their password),
     * `throttle:10,1`, and the policy here. Every reveal is a log line that
     * says who looked at which login of what, and never what they saw.
     */
    public function reveal(Request $request, AssetCredential $credential): JsonResponse
    {
        $owner = $credential->credentialable;
        abort_unless($owner !== null && CredentialAccess::mayReveal($request->user(), $owner), 403);

        // ⚠ Query builder, not save(): a look is not an edit, so `updated_at`
        // and the model's own log stay as they were.
        AssetCredential::query()->whereKey($credential->id)
            ->update(['last_revealed_at' => now(), 'last_revealed_by' => $request->user()->id]);

        activity('assets.secrets')
            ->performedOn($owner)
            ->causedBy($request->user())
            ->log($request->user()->name.' revealed '.$credential->label.' of '.CredentialAccess::ownerLabel($owner));

        return response()->json(['secret' => $credential->secret]);
    }

    /**
     * What the Logins card is handed for one owner: the safe rows and what the
     * reader may do with them. Null when the card is absent for this person.
     *
     * @return array{rows: list<array<string, mixed>>, can_manage: bool, can_reveal: bool, confirm_minutes: int}|null
     */
    public static function cardFor(Request $request, Model $owner): ?array
    {
        $user = $request->user();

        if (! CredentialAccess::maySee($user, $owner)) {
            return null;
        }

        // ⚠ Without the business scope: My assets lists a thing from every
        // business the employee holds in, and the owner already names its rows.
        $rows = AssetCredential::query()->withoutGlobalScopes()
            ->where('credentialable_type', $owner->getMorphClass())
            ->where('credentialable_id', $owner->getKey())
            ->with('lastRevealedBy:id,name')
            ->orderBy('label')->get()
            ->map(fn (AssetCredential $credential) => $credential->toSafeArray())
            ->values()->all();

        return [
            'rows' => $rows,
            'can_manage' => CredentialAccess::mayManage($user, $owner),
            'can_reveal' => CredentialAccess::mayReveal($user, $owner),
            // The framework's own confirmation window (config auth.password_timeout),
            // said in minutes so the card can tell the truth about how long a
            // confirmation lasts.
            'confirm_minutes' => max(1, intdiv((int) config('auth.password_timeout'), 60)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'label' => ['required', 'string', 'max:80'],
            'username' => ['nullable', 'string', 'max:190'],
            // ⚠ Required on create; on an edit a blank keeps the stored one.
            'secret' => [$creating ? 'required' : 'nullable', 'string', 'max:'.self::MAX_SECRET],
            'host_or_url' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
