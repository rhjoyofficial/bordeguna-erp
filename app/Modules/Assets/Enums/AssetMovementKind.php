<?php

namespace App\Modules\Assets\Enums;

/**
 * What a movement does (asset management slice A2, plan §4c).
 *
 * - issue:        the place hands a thing to an employee
 * - return:       an employee hands it back to its place
 * - send_out:     it goes to an outside party (a repair shop, a freelancer)
 * - receive_back: it comes back from the outside party
 * - transfer:     it goes from one place of the business to another
 * - reassign:     one employee hands it straight to another (13 Oct 2026),
 *                 one document instead of a return and an issue
 *
 * ⚠ Only a TRANSFER has two steps (dispatched, then received at the other
 * place, by somebody there). The others are recorded once, by the keeper who
 * did the hand-over: there is nobody else at a keyboard to confirm them.
 */
enum AssetMovementKind: string
{
    case Issue = 'issue';
    case Return = 'return';
    case SendOut = 'send_out';
    case ReceiveBack = 'receive_back';
    case Transfer = 'transfer';
    case Reassign = 'reassign';

    public function label(): string
    {
        return match ($this) {
            self::Issue => 'Handed to an employee',
            self::Return => 'Returned by an employee',
            self::SendOut => 'Sent to an outside party',
            self::ReceiveBack => 'Received back from an outside party',
            self::Transfer => 'Moved to another place',
            self::Reassign => 'From one person to another',
        };
    }

    public function isTwoStep(): bool
    {
        return $this === self::Transfer;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case) => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
