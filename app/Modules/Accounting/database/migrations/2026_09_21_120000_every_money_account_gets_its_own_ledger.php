<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ⚠⚠ 1110, 1120 AND 1130 BECOME GROUPS, AND EVERY CASH BOX, BANK ACCOUNT AND
 * MOBILE WALLET GETS A HEAD OF ITS OWN UNDER THEM (21 Sep 2026). The why is in
 * Support\MoneyLedgers.
 *
 * ⚠ THE OLD ROW IS RECODED, NOT CONVERTED. Journal lines point at an account's
 * id and can never be updated, and a reversal posts back to the same id. So the
 * postable row that carries today's lines keeps its id, its lines and its slug,
 * moves to 11201 (1110 to 11101, 1130 to 11301) under a NEW group row that
 * takes the four-digit code.
 * Every posted journal stays reversible. (Turning the row itself into a group,
 * as the 4900 migration did, is only safe when nothing was ever posted to it,
 * and production has two payment vouchers.)
 *
 * ⚠ WHO KEEPS THE OLD ROW. The money account whose lines are on it. When two or
 * more money accounts posted to one shared head (bKash and Nagad both on 1130),
 * nothing in a line says which wallet it was, so the history is NOT guessed onto
 * one of them: the old row stays as a named legacy head and every account gets a
 * fresh head from here on.
 *
 * ⚠ Does nothing on an empty database (the 4300 rule): the seeder plants the new
 * shape there. Every change is written to the activity log, because a migration
 * bypasses the model events that would otherwise record it.
 *
 * ⚠ MySQL: codes are bound as STRINGS. An integer compared with the varchar code
 * column makes MySQL cast the whole column to DOUBLE.
 */
return new class extends Migration
{
    /** old head slug => [money account type, group slug, group name, the old head's name before today] */
    private const KINDS = [
        'cash_in_hand' => ['cash', 'cash_accounts', 'Cash in Hand', 'Cash in Hand'],
        'cash_at_bank' => ['bank', 'bank_accounts', 'Bank Accounts', 'Cash at Bank'],
        'mobile_financial_services' => ['mfs', 'mfs_accounts', 'Mobile Financial Services', 'Mobile Financial Services'],
    ];

    private const REASON = 'One ledger head per cash box, bank account and mobile wallet (21 Sep 2026).';

    /** The first head's name where nothing reported under it yet (a fresh or unused installation). */
    private const FIRST_HEAD = ['cash' => 'Main Cash', 'bank' => 'Main Bank Account', 'mfs' => 'Main Mobile Wallet'];

    public function up(): void
    {
        foreach (self::KINDS as $headSlug => [$type, $groupSlug, $groupName]) {
            $head = DB::table('accounts')->where('system_slug', $headSlug)->first();

            if ($head === null || DB::table('accounts')->where('system_slug', $groupSlug)->exists() || ! $head->is_postable) {
                continue;
            }

            DB::transaction(function () use ($head, $type, $groupSlug, $groupName) {
                $groupCode = (string) $head->code;
                $headCode = $this->freeCode($groupCode);

                // 1. Free the four-digit code, 2. plant the group on it, 3. hang the old row under it.
                DB::table('accounts')->where('id', $head->id)->update(['code' => $headCode, 'updated_at' => now()]);

                $groupId = DB::table('accounts')->insertGetId([
                    'code' => $groupCode,
                    'name' => $groupName,
                    'type' => 'asset',
                    'parent_id' => $head->parent_id,
                    'is_postable' => false,
                    'is_active' => true,
                    'system_slug' => $groupSlug,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->log($groupId, 'created', ['code' => $groupCode, 'name' => $groupName, 'is_postable' => false], []);

                $owners = DB::table('money_accounts')->where('account_id', $head->id)->orderBy('id')->get();
                $keeper = $this->keeperOf($head->id, $owners);

                $newName = match (true) {
                    $keeper !== null => $this->headName($keeper),
                    $owners->isNotEmpty() => "{$groupName} (shared before 21 Sep 2026)",
                    default => self::FIRST_HEAD[$type],
                };

                DB::table('accounts')->where('id', $head->id)->update([
                    'name' => $newName,
                    'parent_id' => $groupId,
                    'updated_at' => now(),
                ]);
                $this->log($head->id, 'updated',
                    ['code' => $headCode, 'name' => $newName, 'parent_id' => $groupId],
                    ['code' => $groupCode, 'name' => $head->name, 'parent_id' => $head->parent_id]);

                // Every other money account of this kind gets a head of its own:
                // the ones that shared the old row, and any never mapped at all.
                $others = $owners->reject(fn ($owner) => $keeper !== null && $owner->id === $keeper->id)
                    ->concat(DB::table('money_accounts')->where('type', $type)->whereNull('account_id')->orderBy('id')->get());

                foreach ($others as $owner) {
                    $code = $this->freeCode($groupCode);
                    $name = $this->headName($owner);
                    $newId = DB::table('accounts')->insertGetId([
                        'code' => $code,
                        'name' => $name,
                        'type' => 'asset',
                        'parent_id' => $groupId,
                        'is_postable' => true,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $this->log($newId, 'created', ['code' => $code, 'name' => $name, 'parent_id' => $groupId], []);

                    DB::table('money_accounts')->where('id', $owner->id)->update(['account_id' => $newId, 'updated_at' => now()]);
                    $this->log($owner->id, 'updated', ['account_id' => $newId], ['account_id' => $owner->account_id], 'App\\Modules\\Foundation\\Models\\MoneyAccount');
                }
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::KINDS, true) as $headSlug => [$type, $groupSlug, $groupName, $oldName]) {
            $group = DB::table('accounts')->where('system_slug', $groupSlug)->first();
            $head = DB::table('accounts')->where('system_slug', $headSlug)->first();

            if ($group === null || $head === null) {
                continue;
            }

            $children = DB::table('accounts')->where('parent_id', $group->id)->where('id', '!=', $head->id)->get();

            foreach ($children as $child) {
                if (DB::table('journal_lines')->where('account_id', $child->id)->exists()) {
                    throw new RuntimeException("Cannot roll back: {$child->code} {$child->name} already has postings. Rolling back would strand them.");
                }
            }

            DB::transaction(function () use ($group, $head, $children, $oldName) {
                foreach ($children as $child) {
                    foreach (DB::table('money_accounts')->where('account_id', $child->id)->get() as $moneyAccount) {
                        DB::table('money_accounts')->where('id', $moneyAccount->id)->update([
                            'account_id' => $this->loggedOld('App\Modules\Foundation\Models\MoneyAccount', $moneyAccount->id, 'account_id', $head->id),
                            'updated_at' => now(),
                        ]);
                    }
                    DB::table('accounts')->where('id', $child->id)->delete();
                }

                DB::table('accounts')->where('id', $head->id)->update(['parent_id' => $group->parent_id, 'updated_at' => now()]);
                DB::table('accounts')->where('id', $group->id)->delete();
                DB::table('accounts')->where('id', $head->id)->update([
                    'code' => (string) $group->code,
                    'name' => $this->loggedOld('App\Modules\Accounting\Models\Account', $head->id, 'name', $oldName),
                    'updated_at' => now(),
                ]);
            });
        }
    }

    /**
     * The money account the old row now belongs to, or null when that cannot be
     * said honestly. Lines with no money account on them (an opening balance by
     * manual journal) belong to the only owner when there is exactly one.
     */
    private function keeperOf(int $headId, $owners): ?object
    {
        if ($owners->isEmpty()) {
            return null;
        }

        $stamped = DB::table('journal_lines')->where('account_id', $headId)->whereNotNull('money_account_id')
            ->distinct()->pluck('money_account_id')->map(fn ($id) => (int) $id);
        $bare = DB::table('journal_lines')->where('account_id', $headId)->whereNull('money_account_id')->exists();
        $ownerIds = $owners->pluck('id')->map(fn ($id) => (int) $id);

        // A line stamped with an account that no longer reports here, lines from
        // two accounts, or unstamped lines while several accounts share the head:
        // none of those can be put on one account without guessing.
        if ($stamped->diff($ownerIds)->isNotEmpty() || $stamped->count() > 1 || ($bare && $owners->count() > 1)) {
            return null;
        }

        return $stamped->isEmpty() ? $owners->first() : $owners->firstWhere('id', $stamped->first());
    }

    /** A value as it was before up() changed it, read back from the activity log up() wrote. */
    private function loggedOld(string $subjectType, int $subjectId, string $key, mixed $default): mixed
    {
        $properties = DB::table('activity_log')
            ->where('subject_type', $subjectType)->where('subject_id', $subjectId)
            ->where('properties', 'like', '%'.self::REASON.'%')
            ->orderByDesc('id')->value('properties');

        $old = $properties === null ? [] : (json_decode($properties, true)['old'] ?? []);

        return array_key_exists($key, $old) ? $old[$key] : $default;
    }

    private function headName(object $moneyAccount): string
    {
        $business = DB::table('businesses')->where('id', $moneyAccount->business_id)->value('name');

        return $business === null ? $moneyAccount->name : "{$moneyAccount->name} - {$business}";
    }

    /** Under 1120: 11201, 11202 ... (the group's code without its trailing zero, and two digits). */
    private function freeCode(string $groupCode): string
    {
        $prefix = strlen($groupCode) === 4 && str_ends_with($groupCode, '0') ? substr($groupCode, 0, 3) : $groupCode;

        for ($n = 1; $n <= 99; $n++) {
            $code = $prefix.str_pad((string) $n, 2, '0', STR_PAD_LEFT);

            if (! DB::table('accounts')->where('code', $code)->exists()) {
                return $code;
            }
        }

        throw new RuntimeException("No free code left under {$groupCode}.");
    }

    private function log(int $subjectId, string $event, array $attributes, array $old, string $subjectType = 'App\\Modules\\Accounting\\Models\\Account'): void
    {
        $row = [
            'log_name' => 'default',
            'description' => $event,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'properties' => json_encode(array_filter(['attributes' => $attributes, 'old' => $old, 'reason' => self::REASON])),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('activity_log', 'event')) {
            $row['event'] = $event;
        }

        DB::table('activity_log')->insert($row);
    }
};
