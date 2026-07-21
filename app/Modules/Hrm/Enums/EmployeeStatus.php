<?php

namespace App\Modules\Hrm\Enums;

/**
 * The employment lifecycle (EMP-06). A new record starts as Draft and is
 * activated once confirmed; every transition is effective-dated and audited
 * in a later slice. Separated is terminal and carries a separation reason.
 */
enum EmployeeStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case OnLeave = 'on_leave';
    case Suspended = 'suspended';
    case Separated = 'separated';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Active => 'Active',
            self::OnLeave => 'On leave',
            self::Suspended => 'Suspended',
            self::Separated => 'Separated',
        };
    }
}
