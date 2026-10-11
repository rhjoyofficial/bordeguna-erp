<?php

namespace App\Modules\Sales\Couriers\Actions;

use App\Modules\Sales\Couriers\Drivers\ConnectionResult;
use App\Modules\Sales\Couriers\Drivers\CourierDriver;
use App\Modules\Sales\Couriers\Models\CourierAccount;

/**
 * Asks the courier, read-only, whether the saved credentials work, and records
 * the answer on the account.
 *
 * ⚠ A pass records the fingerprint of exactly what was tested; Activate demands
 * it still matches. A failure clears it, which also switches an active account
 * off: an account the courier now refuses is not one to book through.
 */
class TestCourierConnection
{
    public function handle(CourierAccount $account): ConnectionResult
    {
        $driverClass = $account->definition()->driverClass();

        if ($driverClass === null) {
            $result = ConnectionResult::failure($account->definition()->name().' is not connected to the ERP yet.');
        } else {
            /** @var CourierDriver $driver */
            $driver = app($driverClass);
            $result = $driver->testConnection($account);
        }

        $account->forceFill([
            'last_test_at' => now(),
            'last_test_ok' => $result->ok,
            'last_test_message' => mb_substr($result->message, 0, 500),
            'last_test_stores' => $result->ok ? $result->stores : null,
            'last_test_city_count' => $result->ok ? $result->cityCount : null,
            'tested_fingerprint' => $result->ok ? $account->currentFingerprint() : null,
        ])->save();

        return $result;
    }
}
