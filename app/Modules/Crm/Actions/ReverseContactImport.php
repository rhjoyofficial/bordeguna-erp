<?php

namespace App\Modules\Crm\Actions;

use App\Models\User;
use App\Modules\Crm\Models\Contact;
use App\Modules\Crm\Models\ContactImportBatch;
use App\Modules\Crm\Models\ContactImportBatchRow;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\CorrectionLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Normalizer;

/**
 * ⭐ Takes a phone book file back (corrections wave 3, 24 Oct 2026;
 * FINAL-PLAN 3.2 "Contact import: Reverse import with preview, only rows not
 * changed since").
 *
 *   created by the file, untouched since       -> the Recycle bin
 *   changed by the file, untouched since       -> its values before the file
 *   changed by anybody since, already in the   -> kept, and named in the
 *   bin, or a message was sent to it              preview with the reason
 *
 * "Untouched" is judged on the values: every column the file wrote still
 * holds what the file wrote. A later edit, a later import or a switch off
 * makes the row somebody else's work, and it is left alone.
 *
 * ⚠ plan() writes nothing (the preview). handle() plans again under the
 * batch's lock, so the reversal does exactly what was true at that moment,
 * and a second click finds the batch reversed and is refused.
 */
class ReverseContactImport
{
    /**
     * @return array{remove: list<array{id:int, name:string}>, restore: list<array{id:int, name:string, changes:list<string>}>, kept: list<array{id:int, name:string, why:string}>}
     */
    public function plan(ContactImportBatch $batch): array
    {
        $plan = ['remove' => [], 'restore' => [], 'kept' => []];

        foreach ($batch->rows()->orderBy('id')->get() as $row) {
            $contact = Contact::withTrashed()->find($row->contact_id);
            $name = (string) ($contact?->name ?? ($row->after['name'] ?? "#{$row->contact_id}"));
            $entry = ['id' => (int) $row->contact_id, 'name' => $name];

            $why = match (true) {
                $contact === null => 'It is no longer in the phone book.',
                $contact->trashed() => 'It is already in the Recycle bin.',
                ! $this->untouched($contact, $row) => 'It was changed after the import.',
                $row->kind === ContactImportBatchRow::CREATED && ($used = $contact->whyNotRemovable()) !== null => $used,
                default => null,
            };

            if ($why !== null) {
                $plan['kept'][] = $entry + ['why' => $why];
            } elseif ($row->kind === ContactImportBatchRow::CREATED) {
                $plan['remove'][] = $entry;
            } else {
                $plan['restore'][] = $entry + ['changes' => $this->changes($row)];
            }
        }

        return $plan;
    }

    /**
     * @throws ValidationException
     */
    public function handle(ContactImportBatch $batch, User $by, ActionReason $reason): array
    {
        return DB::transaction(function () use ($batch, $by, $reason) {
            $current = ContactImportBatch::query()->whereKey($batch->id)->lockForUpdate()->first();

            if ($current === null) {
                throw ValidationException::withMessages(['import' => 'This import is not one of this business.']);
            }

            if ($current->isReversed()) {
                throw ValidationException::withMessages(['import' => 'This import was already reversed.']);
            }

            $plan = $this->plan($current);
            $why = "Import #{$current->id} reversed: {$reason->display()}";
            $rows = $current->rows()->get()->keyBy('contact_id');

            foreach ($plan['remove'] as $entry) {
                Contact::query()->findOrFail($entry['id'])->recycle($by, new ActionReason($reason->reasonId, $why));
                $this->outcome($rows[$entry['id']] ?? null, 'removed');
            }

            foreach ($plan['restore'] as $entry) {
                $row = $rows[$entry['id']];
                Contact::query()->findOrFail($entry['id'])->fill($row->before ?? [])->save();
                $this->outcome($row, 'restored');
            }

            foreach ($plan['kept'] as $entry) {
                $this->outcome($rows[$entry['id']] ?? null, 'kept');
            }

            $current->forceFill([
                'reversed_at' => now(),
                'reversed_by' => $by->id,
                'reverse_reason' => $reason->display(),
                'reversed_count' => count($plan['remove']) + count($plan['restore']),
                'kept_count' => count($plan['kept']),
            ])->save();

            CorrectionLog::event('import_reversed', $by, $current, [
                'reason' => $reason->display(),
                'removed' => count($plan['remove']),
                'restored' => count($plan['restore']),
                'kept' => count($plan['kept']),
            ]);

            return $plan;
        });
    }

    /**
     * Every column the file wrote still holds what the file wrote, and nothing
     * else on the contact was saved after the import (a note, an edit on the
     * phone book screen). A few seconds of slack: the import itself is one
     * transaction that may straddle a second.
     */
    private function untouched(Contact $contact, ContactImportBatchRow $row): bool
    {
        $batchAt = $row->batch?->created_at;

        if ($batchAt !== null && $contact->updated_at !== null && $contact->updated_at->gt($batchAt->copy()->addSeconds(5))) {
            return false;
        }

        foreach ($row->after ?? [] as $column => $written) {
            if ($this->same($column, $contact->getAttribute($column), $written) === false) {
                return false;
            }
        }

        return true;
    }

    private function same(string $column, mixed $now, mixed $written): bool
    {
        if ($column === 'is_active') {
            return (bool) $now === (bool) $written;
        }

        $nfc = fn (mixed $v) => Normalizer::normalize((string) ($v ?? ''), Normalizer::FORM_C) ?: (string) ($v ?? '');

        return $nfc($now) === $nfc($written);
    }

    /** @return list<string> "name: Rahim Store -> Rahim" */
    private function changes(ContactImportBatchRow $row): array
    {
        $lines = [];

        foreach ($row->after ?? [] as $column => $written) {
            $before = $row->before[$column] ?? null;
            $show = fn (mixed $v) => match (true) {
                $v === null, $v === '' => '(none)',
                $v === true => 'active',
                $v === false => 'inactive',
                default => (string) $v,
            };

            $lines[] = "{$column}: {$show($written)} -> {$show($before)}";
        }

        return $lines;
    }

    private function outcome(?ContactImportBatchRow $row, string $outcome): void
    {
        $row?->forceFill(['outcome' => $outcome])->save();
    }
}
