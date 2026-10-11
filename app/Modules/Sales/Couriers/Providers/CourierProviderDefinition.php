<?php

namespace App\Modules\Sales\Couriers\Providers;

use App\Modules\Sales\Couriers\Drivers\CourierDriver;
use Illuminate\Validation\ValidationException;

/**
 * What one courier company is, as far as the ERP needs to know: the fields its
 * credentials have, the options a merchant picks, the ONLY URLs a request may
 * go to, and what its API can do.
 *
 * ⚠⚠ THE BASE URL IS AN ALLOW-LIST, NOT A TEXT BOX (ChatGPT risk 6). The
 * credentials are sent to whatever URL the account names, so a typed URL is a
 * way to post the merchant password to anybody's server. The screen offers a
 * select of these and the server refuses anything else.
 */
abstract class CourierProviderDefinition
{
    public const SANDBOX = 'sandbox';

    public const LIVE = 'live';

    public const ENVIRONMENTS = [self::SANDBOX, self::LIVE];

    abstract public function code(): string;

    abstract public function name(): string;

    /**
     * A driver exists, so an account can be created and tested.
     */
    public function connected(): bool
    {
        return $this->driverClass() !== null;
    }

    /**
     * @return class-string<CourierDriver>|null
     */
    public function driverClass(): ?string
    {
        return null;
    }

    /**
     * @return list<array{key:string,label:string,secret:bool,required:bool,hint?:string}>
     */
    public function credentialFields(): array
    {
        return [];
    }

    /**
     * Options a merchant may change. `select` has `choices` (value => label),
     * `number` has `min`, `max` and `step`. Values are stored as given type.
     *
     * @return list<array{key:string,label:string,type:string,default:int|float|string,choices?:array<string,string>,min?:float,max?:float,step?:float,hint?:string}>
     */
    public function optionFields(): array
    {
        return [];
    }

    /**
     * The official URLs per environment, URL => label.
     *
     * @return array<string, array<string,string>>
     */
    public function baseUrls(): array
    {
        return [self::SANDBOX => [], self::LIVE => []];
    }

    /**
     * What the API offers, as known from the provider's own documentation.
     * Null means "not known yet", which is different from "no".
     *
     * @return array<string,bool|null>
     */
    public function capabilities(): array
    {
        return [
            'test_connection' => null,
            'stores' => null,
            'locations' => null,
            'book' => null,
            'bulk_book' => null,
            'quote' => null,
            'cancel' => null,
            'webhook' => null,
            'status_lookup' => null,
        ];
    }

    public function allowsBaseUrl(string $environment, string $url): bool
    {
        return array_key_exists($url, $this->baseUrls()[$environment] ?? []);
    }

    public function defaultBaseUrl(string $environment): ?string
    {
        $urls = array_keys($this->baseUrls()[$environment] ?? []);

        return $urls[0] ?? null;
    }

    /**
     * @return list<string>
     */
    public function secretKeys(): array
    {
        return array_values(array_map(fn ($f) => $f['key'], array_filter($this->credentialFields(), fn ($f) => $f['secret'])));
    }

    /**
     * @return list<string>
     */
    public function credentialKeys(): array
    {
        return array_map(fn ($f) => $f['key'], $this->credentialFields());
    }

    /**
     * @return array<string,int|float|string>
     */
    public function defaultOptions(): array
    {
        $defaults = [];

        foreach ($this->optionFields() as $field) {
            $defaults[$field['key']] = $field['default'];
        }

        return $defaults;
    }

    /**
     * Checks options against this provider's schema and returns them typed,
     * with defaults filled in for anything not given. Unknown keys are dropped.
     *
     * @param  array<string,mixed>  $given
     * @return array<string,int|float|string>
     *
     * @throws ValidationException
     */
    public function validateOptions(array $given, string $errorPrefix = 'options'): array
    {
        $clean = [];
        $errors = [];

        foreach ($this->optionFields() as $field) {
            $key = $field['key'];
            $value = $given[$key] ?? null;

            if ($value === null || $value === '') {
                $clean[$key] = $field['default'];

                continue;
            }

            if ($field['type'] === 'select') {
                if (! array_key_exists((string) $value, $field['choices'])) {
                    $errors["{$errorPrefix}.{$key}"] = "{$field['label']}: choose one of the listed values.";

                    continue;
                }

                $clean[$key] = is_int($field['default']) ? (int) $value : (string) $value;

                continue;
            }

            if (! is_numeric($value)) {
                $errors["{$errorPrefix}.{$key}"] = "{$field['label']} must be a number.";

                continue;
            }

            $number = (float) $value;

            if (isset($field['min']) && $number < $field['min']) {
                $errors["{$errorPrefix}.{$key}"] = "{$field['label']} cannot be less than {$field['min']}.";

                continue;
            }

            if (isset($field['max']) && $number > $field['max']) {
                $errors["{$errorPrefix}.{$key}"] = "{$field['label']} cannot be more than {$field['max']}.";

                continue;
            }

            $clean[$key] = $number;
        }

        if ($errors === [] && isset($clean['min_weight_kg'], $clean['max_weight_kg']) && $clean['min_weight_kg'] > $clean['max_weight_kg']) {
            $errors["{$errorPrefix}.min_weight_kg"] = 'The lowest weight cannot be above the highest weight.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $clean;
    }

    /**
     * Everything the settings screen needs to draw this provider's form. No
     * account data, no secrets: this is the provider's own description.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code(),
            'name' => $this->name(),
            'connected' => $this->connected(),
            'credential_fields' => $this->credentialFields(),
            'option_fields' => $this->optionFields(),
            'base_urls' => $this->baseUrls(),
            'capabilities' => $this->capabilities(),
        ];
    }
}
