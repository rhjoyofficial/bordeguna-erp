<?php

namespace App\Modules\Accounting\Actions;

use App\Models\User;
use App\Modules\Accounting\Corrections\VoucherActionPolicy;
use App\Modules\Accounting\Models\Voucher;
use App\Modules\Accounting\Models\VoucherLine;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\CorrectionLog;
use App\Modules\Foundation\Corrections\DocumentAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ Corrections wave 2 (B7, 24 Oct 2026; FINAL-PLAN 3.1 and decision 4): Fix
 * details on a posted voucher. Before this, one wrong word cost three records
 * (the voucher, its reversal and a copy).
 *
 *   narration        the voucher's own text
 *   line narration   each line's text, by line
 *   counterparty     the "Paid to / Received from" spelling (a typed name;
 *                    a picked supplier, customer or employee stays as picked)
 *   instrument bank  the cheque's bank and branch
 *
 * ⚠ TEXT ONLY: no amount, date, head, account, party or cheque number moves
 * (those are Correct), so no journal is written or reversed, and it is allowed
 * in a closed month too. The posted journal keeps the old words in its memo:
 * a journal is never edited. The old and new values, who, when and why go to
 * the voucher's History and the Corrections report (`fix_details`).
 *
 * ⚠ Stale guard: the screen sends the fingerprint of what it showed; a voucher
 * that changed since is refused, never overwritten.
 */
class FixVoucherDetails
{
    public function __construct(private readonly VoucherActionPolicy $policy) {}

    /** What the screen showed, so a change made since is caught. */
    public static function fingerprint(Voucher $voucher): string
    {
        $voucher->loadMissing('lines');

        return hash('sha256', (string) json_encode([
            $voucher->status->value, $voucher->narration, $voucher->counterparty, $voucher->instrument_bank,
            $voucher->lines->sortBy('id')->map(fn (VoucherLine $l) => [(int) $l->id, $l->narration])->values()->all(),
        ]));
    }

    /**
     * @param  array{narration?: ?string, counterparty?: ?string, instrument_bank?: ?string, lines?: array<int|string, ?string>}  $changes
     *
     * @throws ValidationException
     */
    public function handle(Voucher $voucher, array $changes, ActionReason $reason, User $by, ?string $seen = null): Voucher
    {
        return DB::transaction(function () use ($voucher, $changes, $reason, $by, $seen) {
            $voucher = Voucher::query()->whereKey($voucher->id)->lockForUpdate()->firstOrFail()->load('lines');

            if ($seen !== null && ! hash_equals(self::fingerprint($voucher), $seen)) {
                throw ValidationException::withMessages(['stale' => 'This record changed after you opened it. Please look again.']);
            }

            $availability = $this->policy->availability($voucher, $by, DocumentAction::FixDetails);

            if (! $availability->allowed) {
                throw ValidationException::withMessages(['reason_text' => $availability->blockers[0]->message]);
            }

            $text = fn ($v) => $v === null || trim((string) $v) === '' ? null : trim((string) $v);
            $before = ['narration' => $voucher->narration, 'counterparty' => $voucher->counterparty, 'instrument_bank' => $voucher->instrument_bank];
            $after = $before;

            foreach (['narration', 'counterparty', 'instrument_bank'] as $field) {
                if (array_key_exists($field, $changes)) {
                    $after[$field] = $text($changes[$field]);
                }
            }

            // ⚠ A picked party's name is the party's, kept with the record; only a
            // typed name is a spelling to fix.
            if ($after['counterparty'] !== $before['counterparty'] && $voucher->party_type !== null) {
                throw ValidationException::withMessages(['counterparty' => 'This voucher names a supplier, customer or employee from the list. Fix the name on their own record.']);
            }

            if ($after['counterparty'] === null && $before['counterparty'] !== null && $voucher->type->counterpartyLabel() !== null) {
                throw ValidationException::withMessages(['counterparty' => 'Keep a name here, spelled right.']);
            }

            $lineChanges = [];

            foreach ((array) ($changes['lines'] ?? []) as $lineId => $narration) {
                $line = $voucher->lines->firstWhere('id', (int) $lineId)
                    ?? throw ValidationException::withMessages(['lines' => 'A line on the form is not on this voucher. Please look again.']);

                if ($text($narration) !== $line->narration) {
                    $lineChanges[(int) $line->id] = ['before' => $line->narration, 'after' => $text($narration), 'line' => (int) $line->line_no];
                }
            }

            $changed = array_values(array_filter(['narration', 'counterparty', 'instrument_bank'], fn (string $f) => $before[$f] !== $after[$f]));

            if ($changed === [] && $lineChanges === []) {
                throw ValidationException::withMessages(['narration' => 'Nothing was changed. Change what was wrong, or go back.']);
            }

            if (mb_strlen((string) $after['narration']) > 1000 || mb_strlen((string) $after['counterparty']) > 255 || mb_strlen((string) $after['instrument_bank']) > 120) {
                throw ValidationException::withMessages(['narration' => 'That text is too long.']);
            }

            $voucher->forceFill(array_intersect_key($after, array_flip($changed)))->save();

            foreach ($lineChanges as $id => $change) {
                VoucherLine::query()->whereKey($id)->update(['narration' => $change['after'] === null ? null : mb_substr($change['after'], 0, 255)]);
            }

            CorrectionLog::event('fix_details', $by, $voucher, [
                'number' => $voucher->title(),
                'before' => array_intersect_key($before, array_flip($changed)) + ($lineChanges === [] ? [] : ['lines' => array_map(fn ($c) => "Line {$c['line']}: ".($c['before'] ?? '(none)'), array_values($lineChanges))]),
                'after' => array_intersect_key($after, array_flip($changed)) + ($lineChanges === [] ? [] : ['lines' => array_map(fn ($c) => "Line {$c['line']}: ".($c['after'] ?? '(none)'), array_values($lineChanges))]),
                'reason' => $reason->display(),
            ]);

            return $voucher->fresh('lines');
        });
    }
}
