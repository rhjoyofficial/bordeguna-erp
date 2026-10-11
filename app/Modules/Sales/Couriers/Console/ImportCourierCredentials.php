<?php

namespace App\Modules\Sales\Couriers\Console;

use App\Modules\Sales\Couriers\Actions\SaveCourierAccount;
use App\Modules\Sales\Couriers\Models\CourierAccount;
use App\Modules\Sales\Couriers\Models\CourierProvider;
use App\Modules\Sales\Couriers\Providers\CourierProviderDefinition;
use App\Modules\Sales\Couriers\Providers\CourierProviderRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Loads a courier account's credentials from a root-only KEY=VALUE file on the
 * server (e.g. /root/courier-credentials/carrybee-laladia.env) so nobody has to
 * paste them through a browser or a chat.
 *
 *   php artisan couriers:import-credentials /root/courier-credentials/carrybee-laladia.env --provider=carrybee --label="LaLaDia" --environment=live
 *
 * Keys are read as {PREFIX}{FIELD}, e.g. CARRYBEE_LALADIA_LIVE_CLIENT_ID. The
 * prefix is found by itself when the file holds one set; pass --prefix when it
 * holds several.
 *
 * ⚠⚠ The account is left INACTIVE, and only masked values are printed. Test and
 * activate it on Settings > Couriers.
 */
class ImportCourierCredentials extends Command
{
    protected $signature = 'couriers:import-credentials
        {path : A KEY=VALUE file}
        {--provider= : pathao or carrybee}
        {--label= : The account label (default: from the key prefix)}
        {--environment=live : sandbox or live}
        {--prefix= : The key prefix, e.g. CARRYBEE_LALADIA_LIVE_}';

    protected $description = 'Create or update an inactive courier account from a KEY=VALUE credentials file, printing only masked values';

    /** Other spellings a credentials file may use for a field. */
    private const ALIASES = [
        'username' => ['USERNAME', 'EMAIL', 'MERCHANT_EMAIL', 'LOGIN_EMAIL'],
        'password' => ['PASSWORD', 'MERCHANT_PASSWORD', 'LOGIN_PASSWORD'],
        'client_context' => ['CLIENT_CONTEXT', 'CONTEXT'],
    ];

    public function handle(CourierProviderRegistry $registry, SaveCourierAccount $save): int
    {
        $code = strtolower((string) $this->option('provider'));

        if (! in_array($code, $registry->connectedCodes(), true)) {
            $this->error('Pass --provider='.implode(' or --provider=', $registry->connectedCodes()).'.');

            return self::FAILURE;
        }

        $environment = strtolower((string) $this->option('environment'));

        if (! in_array($environment, CourierProviderDefinition::ENVIRONMENTS, true)) {
            $this->error('--environment must be sandbox or live.');

            return self::FAILURE;
        }

        $path = (string) $this->argument('path');

        if (! is_file($path) || ! is_readable($path)) {
            $this->error("Cannot read {$path}.");

            return self::FAILURE;
        }

        $definition = $registry->get($code);
        $env = self::parse((string) file_get_contents($path));
        $prefix = $this->option('prefix') !== null ? strtoupper((string) $this->option('prefix')) : self::detectPrefix($env, $definition, $environment);

        if ($prefix === null) {
            $this->error('The file holds more than one set of keys (or none). Pass --prefix, e.g. --prefix=CARRYBEE_LALADIA_LIVE_.');

            return self::FAILURE;
        }

        $credentials = [];

        foreach ($definition->credentialFields() as $field) {
            foreach (self::spellings($field['key']) as $suffix) {
                if (($env[$prefix.$suffix] ?? '') !== '') {
                    $credentials[$field['key']] = $env[$prefix.$suffix];

                    break;
                }
            }
        }

        if ($credentials === []) {
            $this->error("No {$definition->name()} credential keys start with {$prefix}.");

            return self::FAILURE;
        }

        $label = (string) ($this->option('label') ?: self::labelFrom($prefix, $code, $environment, $definition->name()));
        $provider = CourierProvider::forCode($code);

        $existing = CourierAccount::query()->where('courier_provider_id', $provider->id)
            ->where('environment', $environment)->where('label', $label)->first();

        $baseUrl = $existing !== null && $definition->allowsBaseUrl($environment, (string) $existing->base_url)
            ? $existing->base_url
            : $definition->defaultBaseUrl($environment);

        try {
            $account = $save->handle($existing, [
                'provider' => $code,
                'label' => $label,
                'environment' => $environment,
                'base_url' => $baseUrl,
                'credentials' => $credentials,
            ]);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $messages) {
                $this->error(implode(' ', $messages));
            }

            return self::FAILURE;
        }

        // Left switched off whatever it was: imported values are untested.
        $account->forceFill(['is_active' => false, 'activated_at' => null])->save();

        $this->info(($existing ? 'Updated' : 'Created')." {$definition->name()} account \"{$account->label}\" ({$environment}, {$account->base_url}), INACTIVE.");

        foreach ($definition->credentialFields() as $field) {
            $value = $account->credential($field['key']);
            $this->line(sprintf('  %-16s %s', $field['key'], $value === null ? '(missing)' : CourierAccount::mask($value)));
        }

        $this->line('Next: Settings > Couriers, Test connection, then Activate.');

        return self::SUCCESS;
    }

    /**
     * @return array<string,string>
     */
    public static function parse(string $contents): array
    {
        $values = [];

        foreach (preg_split('/\r\n|\r|\n/', $contents) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }

            [$key, $value] = explode('=', $line, 2);
            $value = trim($value);

            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                $value = substr($value, 1, -1);
            }

            $values[strtoupper(trim($key))] = $value;
        }

        return $values;
    }

    /**
     * @return list<string>
     */
    private static function spellings(string $key): array
    {
        return array_values(array_unique(array_merge([strtoupper($key)], self::ALIASES[$key] ?? [])));
    }

    /**
     * The prefix before {FIRST FIELD}, e.g. CARRYBEE_LALADIA_LIVE_ before
     * CLIENT_ID. When there are several, the one naming the environment wins.
     *
     * @param  array<string,string>  $env
     */
    private static function detectPrefix(array $env, CourierProviderDefinition $definition, string $environment): ?string
    {
        $first = strtoupper($definition->credentialKeys()[0] ?? 'CLIENT_ID');
        $prefixes = [];

        foreach (array_keys($env) as $key) {
            if ($key === $first || str_ends_with($key, '_'.$first)) {
                $prefixes[] = substr($key, 0, strlen($key) - strlen($first));
            }
        }

        $prefixes = array_values(array_unique($prefixes));

        if (count($prefixes) > 1) {
            $prefixes = array_values(array_filter($prefixes, fn ($p) => str_contains('_'.$p, '_'.strtoupper($environment).'_')));
        }

        return count($prefixes) === 1 ? $prefixes[0] : null;
    }

    private static function labelFrom(string $prefix, string $code, string $environment, string $fallback): string
    {
        $words = array_filter(explode('_', strtolower($prefix)), fn ($w) => $w !== '' && $w !== $code && $w !== $environment);

        return $words === [] ? $fallback : Str::title(implode(' ', $words));
    }
}
