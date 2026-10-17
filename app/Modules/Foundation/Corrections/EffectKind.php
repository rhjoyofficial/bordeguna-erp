<?php

namespace App\Modules\Foundation\Corrections;

/**
 * What a document can do to the rest of the system (PHASE-0-CONTRACT 1.1).
 *
 * ⚠⚠ EXECUTABLE METADATA (v2, C14). A policy that declares an effect must carry
 * a DependencyCheck that covers it, or every money action on that type is
 * blocked. A type cannot ship a correction by declaring an effect and quietly
 * skipping its check.
 */
enum EffectKind: string
{
    case Ledger = 'ledger';
    case Stock = 'stock';
    case Payroll = 'payroll';
    case LeaveLedger = 'leave_ledger';
    case Custody = 'custody';
    case Allocation = 'allocation';
    case BankMatch = 'bank_match';
    case External = 'external';
    case Issued = 'issued';
}
