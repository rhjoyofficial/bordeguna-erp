<?php

namespace App\Modules\Assets\Enums;

/**
 * What sort of account the business owns (asset register slice 4).
 */
enum CompanyAccountKind: string
{
    case Email = 'email';
    case Domain = 'domain';
    case Hosting = 'hosting';
    case Website = 'website';
    case SocialPage = 'social_page';
    case Saas = 'saas';
    case BankPortal = 'bank_portal';
    case GovernmentPortal = 'government_portal';
    case CourierPortal = 'courier_portal';
    case Cloud = 'cloud';
    case Software = 'software';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'Email',
            self::Domain => 'Domain',
            self::Hosting => 'Hosting',
            self::Website => 'Website',
            self::SocialPage => 'Social page',
            self::Saas => 'Software (SaaS)',
            self::BankPortal => 'Bank or MFS portal',
            self::GovernmentPortal => 'Government portal',
            self::CourierPortal => 'Courier portal',
            self::Cloud => 'Cloud',
            self::Software => 'Software licence',
            self::Other => 'Other',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case) => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
