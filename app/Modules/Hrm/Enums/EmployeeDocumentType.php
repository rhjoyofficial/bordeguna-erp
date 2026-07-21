<?php

namespace App\Modules\Hrm\Enums;

/**
 * The kinds of file an employee record holds (EMP-04). Stored as the
 * attachment's collection, so one employee can carry many documents of each
 * kind (unlike a singleton collection such as a logo).
 */
enum EmployeeDocumentType: string
{
    case NationalId = 'national_id';
    case BirthCertificate = 'birth_certificate';
    case Passport = 'passport';
    case Photo = 'photo';
    case AppointmentLetter = 'appointment_letter';
    case Contract = 'contract';
    case Certificate = 'certificate';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::NationalId => 'National ID',
            self::BirthCertificate => 'Birth Certificate',
            self::Passport => 'Passport',
            self::Photo => 'Photo',
            self::AppointmentLetter => 'Appointment Letter',
            self::Contract => 'Contract',
            self::Certificate => 'Certificate',
            self::Other => 'Other',
        };
    }
}
