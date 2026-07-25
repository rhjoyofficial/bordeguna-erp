<?php

namespace App\Modules\Hrm\Enums;

/**
 * How a raw punch reached the system (ATT-01: hybrid capture — biometric
 * device, CSV/Excel import, employee web/mobile clock-in, and controlled
 * manual entry by an authorized user).
 *
 * The source is part of a punch's identity, never a mutable attribute: it is
 * what tells the Phase-2b2 processing engine how much to trust a record and
 * what tells an auditor where a number came from.
 */
enum PunchSource: string
{
    case Biometric = 'biometric';
    case CsvImport = 'csv_import';
    case Web = 'web';
    case Mobile = 'mobile';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Biometric => 'Biometric device',
            self::CsvImport => 'CSV import',
            self::Web => 'Web clock-in',
            self::Mobile => 'Mobile clock-in',
            self::Manual => 'Manual entry',
        };
    }

    /**
     * Self-service capture must carry proof (Correction C2: GPS + accuracy, IP
     * and device metadata are always required, never optional).
     */
    public function requiresProof(): bool
    {
        return $this === self::Web || $this === self::Mobile;
    }

    /**
     * A punch a human typed rather than a device recorded. ATT-01 allows it but
     * requires the full activity-log audit that comes with it.
     */
    public function isManual(): bool
    {
        return $this === self::Manual;
    }
}
