<?php

namespace App\Modules\Hrm\Payroll\Disbursement;

use App\Modules\Hrm\Payroll\Disbursement\Contracts\DisbursementFormat;
use App\Modules\Hrm\Payroll\Disbursement\Formats\GenericCsvFormat;
use Illuminate\Validation\ValidationException;

/**
 * The disbursement formats this system can write (DIS-06, Correction C9d).
 *
 * One entry today. When the client supplies the Brac Bank sample it is added
 * here and appears in the export menu — no change to the register, the batches
 * or the screens. Registering a format is the entire integration.
 */
class DisbursementFormatRegistry
{
    /** @var array<string, DisbursementFormat> */
    private array $formats = [];

    public function __construct()
    {
        $this->register(new GenericCsvFormat);
    }

    public function register(DisbursementFormat $format): void
    {
        $this->formats[$format->key()] = $format;
    }

    /**
     * @return array<int, DisbursementFormat>
     */
    public function all(): array
    {
        return array_values($this->formats);
    }

    /**
     * @throws ValidationException
     */
    public function get(string $key): DisbursementFormat
    {
        if (! isset($this->formats[$key])) {
            throw ValidationException::withMessages([
                'format' => sprintf('No disbursement format named "%s" is registered.', $key),
            ]);
        }

        return $this->formats[$key];
    }

    /**
     * @return array<int, array{key: string, name: string, description: string}>
     */
    public function options(): array
    {
        return array_map(fn (DisbursementFormat $format) => [
            'key' => $format->key(),
            'name' => $format->name(),
            'description' => $format->description(),
        ], $this->all());
    }
}
