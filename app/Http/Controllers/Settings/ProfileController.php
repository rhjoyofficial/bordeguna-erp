<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Modules\Foundation\Actions\DeleteAttachment;
use App\Modules\Foundation\Actions\StoreAttachment;
use App\Modules\Hrm\Models\Employee;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        $employee = Employee::forUser($request->user());

        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
            // The picture is the linked employee's HR photo; a login with no
            // employee record has nowhere to keep one, and the page says so.
            'hasEmployee' => $employee !== null,
            'photo_url' => $employee?->photo()?->url(),
        ]);
    }

    /**
     * "I added my image but it still shows RJ" (30 Aug 2026). The person's
     * own way to set the picture, writing to the same singleton collection HR
     * uses, so there is one photo per person wherever it is uploaded from.
     */
    public function storePhoto(Request $request, StoreAttachment $action): RedirectResponse
    {
        $employee = Employee::forUser($request->user());

        if ($employee === null) {
            return back()->with('error', 'No employee record is linked to this login, so there is nowhere to keep a picture. Ask HR to link one.');
        }

        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $action->handle(
            attachable: $employee,
            file: $request->file('photo'),
            collection: 'photo',
            uploadedBy: $request->user(),
            singleton: true,
        );

        return back()->with('success', 'Picture updated.');
    }

    public function destroyPhoto(Request $request, DeleteAttachment $action): RedirectResponse
    {
        $photo = Employee::forUser($request->user())?->photo();

        if ($photo !== null) {
            $action->handle($photo);
        }

        return back()->with('success', 'Picture removed.');
    }

    /**
     * Update the user's profile settings.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return to_route('profile.edit');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
