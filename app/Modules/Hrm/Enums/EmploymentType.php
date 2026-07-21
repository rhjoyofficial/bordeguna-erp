<?php

namespace App\Modules\Hrm\Enums;

enum EmploymentType: string
{
    case Permanent = 'permanent';
    case Probation = 'probation';
    case Contract = 'contract';
    case PartTime = 'part_time';
    case Intern = 'intern';
    case DailyWorker = 'daily_worker';
    case Consultant = 'consultant';

    public function label(): string
    {
        return match ($this) {
            self::Permanent => 'Permanent',
            self::Probation => 'Probation',
            self::Contract => 'Contract',
            self::PartTime => 'Part-time',
            self::Intern => 'Intern',
            self::DailyWorker => 'Daily worker',
            self::Consultant => 'Consultant / Freelancer',
        };
    }
}
