<?php

namespace App\Modules\Sales\Couriers\Actions;

use App\Models\User;
use App\Modules\Sales\Couriers\Models\CourierAccount;
use App\Modules\Sales\Couriers\Models\CourierProvider;
use App\Modules\Sales\Couriers\Providers\CourierProviderDefinition;
use App\Modules\Sales\Couriers\Providers\CourierProviderRegistry;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Creates or changes a courier account, for the settings screen and the import
 * command alike, so both obey the same rules.
 *
 * ⚠⚠ A BLANK CREDENTIAL MEANS "keep the saved one", never "erase it". The screen
 * cannot show a saved secret, so the field always arrives blank unless somebody
 * typed a new value; erasing on blank would wipe the password on every rename.
 *
 * ⚠⚠ THE BASE URL MUST BE ONE OF THE PROVIDER'S OFFICIAL URLS FOR THE CHOSEN
 * ENVIRONMENT (ChatGPT risk 6). Refused here, on the server, whatever the form
 * sent.
 *
 * ⚠ Changing credentials, environment or URL switches an active account off
 * (CourierAccount::booted): it has to pass a new test before it can be used.
 */
class SaveCourierAccount
{
    public function __construct(private readonly CourierProviderRegistry $registry) {}

    /**
     * @param  array<string,mixed>  $input  provider (new only), label, environment, base_url, credentials[], options[]
     *
     * @throws ValidationException
     */
    public function handle(?CourierAccount $account, array $input, ?User $by = null, bool $requireCredentials = false): CourierAccount
    {
        $definition = $account?->definition() ?? $this->definitionFor($input['provider'] ?? null);
        $provider = $account?->provider ?? CourierProvider::forCode($definition->code());

        $data = Validator::make($input, [
            'label' => ['required', 'string', 'max:120', Rule::unique('courier_accounts', 'label')
                ->where('courier_provider_id', $provider->id)
                ->where('environment', $input['environment'] ?? null)
                ->ignore($account?->id)],
            'environment' => ['required', Rule::in(CourierProviderDefinition::ENVIRONMENTS)],
            'base_url' => ['required', 'string', 'max:255'],
            'credentials' => ['nullable', 'array'],
            'credentials.*' => ['nullable', 'string', 'max:1000'],
            'options' => ['nullable', 'array'],
            'integration_secret' => ['nullable', 'string', 'max:255'],
        ], [
            'label.unique' => 'Another account of this courier in this environment already has that label.',
        ])->validate();

        if (! $definition->allowsBaseUrl($data['environment'], $data['base_url'])) {
            throw ValidationException::withMessages([
                'base_url' => "Choose one of the official {$definition->name()} addresses for {$data['environment']}.",
            ]);
        }

        // Unknown keys are dropped: only the provider's own fields are stored.
        $given = array_intersect_key($data['credentials'] ?? [], array_flip($definition->credentialKeys()));
        $credentials = $account?->credentialValues() ?? [];

        foreach ($given as $key => $value) {
            $value = trim((string) $value);

            if ($value !== '') {
                $credentials[$key] = $value;
            }
        }

        if ($requireCredentials) {
            $missing = [];

            foreach ($definition->credentialFields() as $field) {
                if ($field['required'] && ($credentials[$field['key']] ?? '') === '') {
                    $missing["credentials.{$field['key']}"] = "{$field['label']} is required.";
                }
            }

            if ($missing !== []) {
                throw ValidationException::withMessages($missing);
            }
        }

        $options = $definition->validateOptions(array_merge($account?->options ?? [], $data['options'] ?? []));

        $attributes = [
            'label' => $data['label'],
            'environment' => $data['environment'],
            'base_url' => $data['base_url'],
            'options' => $options,
        ];

        // ⭐ Slice 4: the courier's webhook reply value (CarryBee and Pathao show
        // it on their webhook page). Blank keeps the saved one, like a credential. Not part of the tested fingerprint: it
        // is what WE answer, not what we send.
        $echo = trim((string) ($data['integration_secret'] ?? ''));

        if ($account === null) {
            return CourierAccount::create($attributes + ($echo === '' ? [] : ['integration_secret' => $echo]) + [
                'courier_provider_id' => $provider->id,
                'credentials' => $credentials,
                'is_active' => false,
                'created_by' => $by?->id,
            ]);
        }

        if ($credentials !== $account->credentialValues()) {
            $attributes['credentials'] = $credentials;
        }

        if ($echo !== '') {
            $account->forceFill(['integration_secret' => $echo]);
        }

        $account->fill($attributes)->save();

        return $account;
    }

    private function definitionFor(mixed $code): CourierProviderDefinition
    {
        $code = is_string($code) ? $code : '';

        if (! $this->registry->has($code)) {
            throw ValidationException::withMessages(['provider' => 'Choose a courier from the list.']);
        }

        $definition = $this->registry->get($code);

        if (! $definition->connected()) {
            throw ValidationException::withMessages(['provider' => "{$definition->name()} is not connected to the ERP yet."]);
        }

        return $definition;
    }
}
