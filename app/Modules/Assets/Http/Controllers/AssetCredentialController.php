<?php

namespace App\Modules\Assets\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assets\Enums\CredentialKind;
use App\Modules\Assets\Models\AssetCredential;
use App\Modules\Assets\Models\AssetItem;
use App\Modules\Assets\Models\CompanyAccount;
use App\Modules\Assets\Support\CredentialAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
 *
 * ⭐ Review of 13 October (rulings 3 and 5): a login has a kind and a holder
 * policy (CredentialKind, `holder_may_reveal`); `replace` records a new
 * secret and the fact that the old one went; `reviewed` clears the flag a
 * return or a reassignment raised. A refused reveal is logged too, without
 * the secret, by AuditRefusedReveal around this controller.
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
        abort_if($owner === null, 404);
        abort_unless(CredentialAccess::mayManage($request->user(), $owner), 403);

        $data = $this->validated($request, creating: false);
        $secret = $data['secret'] ?? null;
        unset($data['secret']);

        if ($secret !== null && $secret !== '') {
            $data['secret'] = $secret;
            $data['hint'] = AssetCredential::hintFor($secret);
            $data['last_rotated_at'] = now();
        }

        $credential->update($data + ['updated_by' => $request->user()->id]);

        return back()->with('success', "Login \"{$credential->label}\" saved.");
    }

    /**
     * A new secret in place of the old one (review ruling 5). The old value
     * is overwritten, never kept: a readable history of passwords is more
     * copies of a password. What stays is WHEN it was replaced and by whom.
     */
    public function replace(Request $request, AssetCredential $credential): RedirectResponse
    {
        $owner = $credential->credentialable;
        abort_if($owner === null, 404);
        abort_unless(CredentialAccess::mayManage($request->user(), $owner), 403);

        $data = $request->validate([
            'secret' => ['required', 'string', 'max:'.self::MAX_SECRET],
        ]);

        // ⚠ The model's own log is off for this write: it would say "Login
        // changed" with nothing in it (secret and hint are never logged), and
        // the line that matters is the one below.
        $credential->disableLogging();
        $credential->update([
            'secret' => $data['secret'],
            'hint' => AssetCredential::hintFor($data['secret']),
            'last_rotated_at' => now(),
            // A replacement IS the review a return or a hand-over asked for.
            'needs_review' => false,
            'updated_by' => $request->user()->id,
        ]);
        $credential->enableLogging();

        activity('assets.secrets')
            ->performedOn($owner)
            ->causedBy($request->user())
            ->log($request->user()->name.' replaced '.$credential->label.' of '.CredentialAccess::ownerLabel($owner));

        return back()->with('success', "Login \"{$credential->label}\" replaced.");
    }

    /**
     * The flag a return or a reassignment raised is cleared: somebody looked
     * and decided the password may stay (or changed it at the provider and
     * recorded it with Replace, which clears the flag itself).
     */
    public function reviewed(Request $request, AssetCredential $credential): RedirectResponse
    {
        $owner = $credential->credentialable;
        abort_if($owner === null, 404);
        abort_unless(CredentialAccess::mayManage($request->user(), $owner), 403);

        $credential->disableLogging();
        $credential->update(['needs_review' => false, 'updated_by' => $request->user()->id]);
        $credential->enableLogging();

        activity('assets.secrets')
            ->performedOn($owner)
            ->causedBy($request->user())
            ->log($request->user()->name.' reviewed '.$credential->label.' of '.CredentialAccess::ownerLabel($owner).' and kept it');

        return back()->with('success', "Login \"{$credential->label}\" marked reviewed.");
    }

    public function destroy(Request $request, AssetCredential $credential): RedirectResponse
    {
        $owner = $credential->credentialable;
        abort_if($owner === null, 404);
        abort_unless(CredentialAccess::mayManage($request->user(), $owner), 403);

        $label = $credential->label;
        $credential->delete();

        return back()->with('success', "Login \"{$label}\" removed.");
    }

    /**
     * The secret, once, as JSON.
     *
     * ⚠⚠ Three gates before this line runs: the route's `password.confirm`
     * (423 for a JSON request until the person re-typed their password),
     * the throttles (per user and per address), and the policy here. Every
     * reveal is a log line that says who looked at which login of what, and
     * never what they saw; every refusal is a log line too (AuditRefusedReveal).
     *
     * ⚠ `Cache-Control: no-store`: the one response that carries a password
     * must not survive in a browser cache, a proxy or a service worker.
     */
    public function reveal(Request $request, AssetCredential $credential): JsonResponse
    {
        $owner = $credential->credentialable;
        // ⚠ 404, not 403: a login whose owner is gone is not reachable at all.
        abort_if($owner === null, 404);

        $denial = CredentialAccess::revealDenial($request->user(), $credential, $owner);

        if ($denial !== null) {
            $request->attributes->set('reveal_denial', $denial);
            abort(403);
        }

        // ⚠ Query builder, not save(): a look is not an edit, so `updated_at`
        // and the model's own log stay as they were.
        AssetCredential::query()->whereKey($credential->id)
            ->update(['last_revealed_at' => now(), 'last_revealed_by' => $request->user()->id]);

        activity('assets.secrets')
            ->performedOn($owner)
            ->causedBy($request->user())
            ->log($request->user()->name.' revealed '.$credential->label.' of '.CredentialAccess::ownerLabel($owner));

        return response()->json(['secret' => $credential->secret])
            ->withHeaders(['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache']);
    }

    /**
     * What the Logins card is handed for one owner: the safe rows, each with
     * whether THIS reader may reveal it, and what the reader may do with
     * them. Null when the card is absent for this person.
     *
     * @return array{rows: list<array<string, mixed>>, can_manage: bool, can_reveal: bool, kinds: list<array<string, mixed>>, confirm_minutes: int}|null
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
            ->map(fn (AssetCredential $credential) => $credential->toSafeArray() + [
                'can_reveal' => CredentialAccess::mayReveal($user, $credential, $owner),
            ])
            ->values()->all();

        return [
            'rows' => $rows,
            'can_manage' => CredentialAccess::mayManage($user, $owner),
            'can_reveal' => CredentialAccess::mayRevealSome($user, $owner),
            'kinds' => CredentialKind::options(),
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
        $data = $request->validate([
            'label' => ['required', 'string', 'max:80'],
            'kind' => ['required', Rule::enum(CredentialKind::class)],
            'holder_may_reveal' => ['nullable', 'boolean'],
            'username' => ['nullable', 'string', 'max:190'],
            // ⚠ Required on create; on an edit a blank keeps the stored one.
            'secret' => [$creating ? 'required' : 'nullable', 'string', 'max:'.self::MAX_SECRET],
            'host_or_url' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $kind = CredentialKind::from($data['kind']);

        // ⚠ An admin or recovery login is never the holder's, whatever the box
        // says: the policy ignores the flag for them, and the row says so too.
        $data['holder_may_reveal'] = $kind->isPrivileged() ? false : (bool) ($data['holder_may_reveal'] ?? true);

        return $data;
    }
}
