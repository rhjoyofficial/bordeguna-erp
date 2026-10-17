<?php

namespace App\Modules\Foundation\Corrections;

/**
 * The verbs a record may offer (PHASE-0-CONTRACT 1.1, FINAL-PLAN 6.1).
 *
 * ⚠ There is no "purge forever": the owner ruled that nothing is ever purged
 * (FINAL-PLAN section 9, answer 1).
 */
enum DocumentAction: string
{
    case Edit = 'edit';
    case Delete = 'delete';
    case Withdraw = 'withdraw';
    case Cancel = 'cancel';
    case FixDetails = 'fix_details';
    case FixBatchDetails = 'fix_batch_details';
    case Correct = 'correct';
    case Reverse = 'reverse';
    case ReverseAndCopy = 'reverse_and_copy';
    case Deactivate = 'deactivate';
    case Reactivate = 'reactivate';
    case Dispose = 'dispose';
    case UndoDisposal = 'undo_disposal';
    case Restore = 'restore';

    /** The verb on the button and in the dialog title. */
    public function label(): string
    {
        return match ($this) {
            self::FixDetails => 'Fix details',
            self::FixBatchDetails => 'Fix batch details',
            self::ReverseAndCopy => 'Reverse and copy',
            self::UndoDisposal => 'Undo disposal',
            default => ucfirst($this->value),
        };
    }

    /** The money actions the correction service runs in Phase 0. */
    public function isCorrection(): bool
    {
        return in_array($this, [self::Reverse, self::Correct, self::ReverseAndCopy], true);
    }
}
