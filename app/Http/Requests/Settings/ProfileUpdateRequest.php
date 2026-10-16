<?php

namespace App\Http\Requests\Settings;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],

            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],

            // ⚠ Bug hunt 16 Oct 2026: changing the sign-in email asks for the
            // current password. Without it, anyone at an unlocked screen could
            // put their own address here and take the account over with
            // "Forgot password". A name change asks for nothing.
            'current_password' => [
                Rule::requiredIf(fn () => mb_strtolower(trim((string) $this->input('email'))) !== mb_strtolower((string) $this->user()->email)),
                'nullable',
                'string',
                'current_password',
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'current_password.required' => 'Type your current password to change your email address.',
            'current_password.current_password' => 'That is not your current password.',
        ];
    }
}
