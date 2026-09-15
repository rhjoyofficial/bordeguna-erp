<?php

namespace App\Modules\Foundation\Enums;

/**
 * The papers a customer or a supplier hands over when it becomes one (CRM lead
 * slice 2e, plan D11; Joy's answers 18-20).
 *
 * ⭐ Rule agreed with Joy: observable facts live on the lead, documents arrive
 * only at conversion. Both kinds are optional, always.
 *
 * ⚠⚠ Answer 19: an NID is a RESTRICTED SCAN and nothing more. No number column,
 * no search, no OCR. Its scan opens only for the module's identity permission
 * (the Owner by default). A trade licence hangs on the shop wall, so anyone who
 * can open the record may see it.
 *
 * ⚠ The values are attachment COLLECTION names. Renaming one orphans every file
 * already stored under it.
 */
enum IdentityDocument: string
{
    case TradeLicence = 'trade_licence';

    case Nid = 'nid';

    public function label(): string
    {
        return match ($this) {
            self::TradeLicence => 'Trade licence',
            self::Nid => 'NID',
        };
    }

    public function isRestricted(): bool
    {
        return $this === self::Nid;
    }

    /** Pages per upload: a licence may run to two, an NID has a front and a back. */
    public const MAX_FILES = 4;
}
