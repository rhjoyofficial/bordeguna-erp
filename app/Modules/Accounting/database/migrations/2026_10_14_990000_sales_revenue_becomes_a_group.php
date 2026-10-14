<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ⚠⚠ 4100 SALES REVENUE BECOMES A GROUP (Joy, 14 Oct 2026), with heads under
 * it for each sales channel, and a second group, 4400, for IT and software
 * service income.
 *
 * ⚠ THE OLD ROW IS RECODED, NOT CONVERTED - the 21 Sep money-ledger rule.
 * 4100 is the account every sales invoice and counter sale credits: it carries
 * the `sales_revenue` slug, every business's `sales.revenue` mapping points at
 * its ID, and production may already hold postings on it. Journal lines name an
 * account by id and are never updated, and a reversal posts back to the same
 * id. So that row keeps its id, slug, mappings, postings and type, takes the
 * code 4101 and the name "Product Sales (automatic)", and moves under a NEW
 * non-postable row that takes the code 4100 and the name "Sales Revenue".
 * Nothing posted moves; automatic sales posting carries on exactly as before.
 *
 * Turning the row itself into a heading is what the chart screen refuses (the
 * 4900 rule and the 3 Oct mapping rule in AccountController), for exactly
 * those reasons, and this migration does not go round them.
 *
 * ⚠ Written with the DB facade, like the migrations beside it. The Account
 * model's events would log every change into the CURRENT business, and a
 * migration has none, so each change is written to the activity log here
 * instead (the 21 Sep pattern), plus one system entry for the whole change.
 *
 * ⚠ Does nothing on an empty database (the 4300 rule): the chart seeder plants
 * the same shape on a fresh install. Idempotent: a code already in the chart is
 * never created twice, a second run changes nothing, and a code held by
 * something that is not what this needs stops the migration with a sentence
 * rather than guessing.
 *
 * ⚠ MySQL: codes are bound as STRINGS. An integer compared with the varchar code
 * column makes MySQL cast the whole column to DOUBLE.
 */
return new class extends Migration
{
    private const REASON = 'Sales Revenue 4100 becomes a group; the automatic product sales head is now 4101 (14 Oct 2026).';

    private const ACCOUNT = 'App\\Modules\\Accounting\\Models\\Account';

    private const INCOME_ROOT = '4000';

    private const SALES_GROUP = ['4100', 'Sales Revenue'];

    private const PRODUCT_SALES = ['4101', 'Product Sales (automatic)'];

    private const PRODUCT_SALES_NOTE = 'Every sales invoice and counter sale credits this head by itself (mapping sales.revenue). Its history from before 14 Oct 2026, when it was 4100, stays here.';

    /** Postable heads under 4100 Sales Revenue. */
    private const SALES_HEADS = [
        '4120' => 'Retail Sales',
        '4130' => 'Wholesale Sales',
        '4140' => 'Dealer Sales',
        '4150' => 'Corporate Sales',
        '4160' => 'Online & E-commerce Sales',
    ];

    private const IT_GROUP = ['4400', 'IT & Software Service Revenue'];

    /** Postable heads under 4400 IT & Software Service Revenue. */
    private const IT_HEADS = [
        '4410' => 'Software Development',
        '4420' => 'Website Design & Development',
        '4430' => 'Web Hosting',
        '4440' => 'Domain Registration',
        '4450' => 'IT Support & Maintenance',
        '4460' => 'Software Subscriptions (SaaS)',
        '4470' => 'IT Consulting',
        '4490' => 'Other IT Services',
    ];

    public function up(): void
    {
        $incomeId = DB::table('accounts')->where('code', self::INCOME_ROOT)->value('id');

        if ($incomeId === null) {
            return;
        }

        DB::transaction(function () use ($incomeId) {
            $changes = [];
            $sales = $this->productSalesAccount();

            // a. Free 4100: the account sales post to becomes 4101, same id.
            if ($sales !== null && (string) $sales->code === self::SALES_GROUP[0]) {
                $taken = DB::table('accounts')->where('code', self::PRODUCT_SALES[0])->first();

                if ($taken !== null) {
                    throw new RuntimeException('Cannot make 4100 Sales Revenue a group: code 4101 is already used by "'.$taken->name.'" (account id '.$taken->id.'). Renumber that account first, then run the migration again.');
                }

                $update = ['code' => self::PRODUCT_SALES[0], 'name' => self::PRODUCT_SALES[1], 'updated_at' => now()];

                if ($sales->note === null) {
                    $update['note'] = self::PRODUCT_SALES_NOTE;
                }

                DB::table('accounts')->where('id', $sales->id)->update($update);
                $this->log($sales->id, 'updated',
                    ['code' => self::PRODUCT_SALES[0], 'name' => self::PRODUCT_SALES[1]],
                    ['code' => (string) $sales->code, 'name' => $sales->name]);
                $changes[] = "{$sales->code} {$sales->name} is now ".self::PRODUCT_SALES[0].' '.self::PRODUCT_SALES[1];

                $sales = DB::table('accounts')->where('id', $sales->id)->first();
            }

            // b. The heading on 4100, and the product sales head under it.
            $groupId = $this->heading(self::SALES_GROUP[0], self::SALES_GROUP[1], $incomeId, $changes);

            // ⚠ Only from where it always sat (directly under 4000). A head
            // somebody filed elsewhere on purpose is left where they put it.
            if ($sales !== null && (int) $sales->parent_id === (int) $incomeId && (int) $sales->id !== $groupId) {
                DB::table('accounts')->where('id', $sales->id)->update(['parent_id' => $groupId, 'updated_at' => now()]);
                $this->log($sales->id, 'updated', ['parent_id' => $groupId], ['parent_id' => $sales->parent_id]);
                $changes[] = "{$sales->code} {$sales->name} now sits under ".self::SALES_GROUP[0];
            }

            // b2. ⚠ Production, 14 Oct 2026: a head made by hand on 4110 "Sales
            // Collected Before ERP" already carries postings and the owner's
            // daily entry guide names it 4110 throughout. That is why the
            // automatic head takes 4101, not 4110. Any postable income head
            // numbered 4101 to 4199 that still sits directly under 4000 is sales
            // revenue too, so it moves under the new group: same id, code, name
            // and postings; only its parent changes.
            $strays = DB::table('accounts')
                ->where('parent_id', $incomeId)
                ->where('type', 'income')
                ->where('is_postable', true)
                ->whereBetween('code', ['4101', '4199'])
                ->where('id', '!=', $groupId)
                ->get(['id', 'code', 'name', 'parent_id']);

            foreach ($strays as $stray) {
                DB::table('accounts')->where('id', $stray->id)->update(['parent_id' => $groupId, 'updated_at' => now()]);
                $this->log((int) $stray->id, 'updated', ['parent_id' => $groupId], ['parent_id' => $stray->parent_id]);
                $changes[] = "{$stray->code} {$stray->name} now sits under ".self::SALES_GROUP[0];
            }

            // c. One head per sales channel.
            foreach (self::SALES_HEADS as $code => $name) {
                $this->head((string) $code, $name, $groupId, $changes);
            }

            // d. IT and software service income.
            $itId = $this->heading(self::IT_GROUP[0], self::IT_GROUP[1], $incomeId, $changes);

            foreach (self::IT_HEADS as $code => $name) {
                $this->head((string) $code, $name, $itId, $changes);
            }

            // e. One entry for the whole change, beside the per-account ones.
            if ($changes !== []) {
                $this->insertLog([
                    'log_name' => 'system',
                    'description' => 'Chart of accounts: '.self::REASON,
                    'properties' => json_encode(['reason' => self::REASON, 'changes' => $changes]),
                ], null);
            }
        });
    }

    /**
     * Takes back what up() did, but only while it is safe: no posting on any
     * new head and nothing else filed under the two new groups. Otherwise it
     * stops with a sentence, because deleting a head with history would strand
     * it. ⚠ For rehearsal; a live chart has no reason to go back.
     */
    public function down(): void
    {
        $group = DB::table('accounts')->where('code', self::SALES_GROUP[0])->where('is_postable', false)->first();
        $itGroup = DB::table('accounts')->where('code', self::IT_GROUP[0])->where('is_postable', false)->first();
        $sales = $this->productSalesAccount();

        $headIds = DB::table('accounts')
            ->whereIn('code', array_map('strval', array_merge(array_keys(self::SALES_HEADS), array_keys(self::IT_HEADS))))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
        $groupIds = array_values(array_filter([$group?->id, $itGroup?->id]));

        $posted = DB::table('journal_lines')->whereIn('account_id', array_merge($headIds, $groupIds))
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->distinct()->orderBy('accounts.code')->pluck('accounts.code');

        if ($posted->isNotEmpty()) {
            throw new RuntimeException('Cannot roll back the Sales Revenue group: these heads already have postings: '.$posted->implode(', ').'. Rolling back would strand them.');
        }

        $ours = array_merge($headIds, $groupIds, $sales === null ? [] : [$sales->id]);

        foreach ($groupIds as $id) {
            $strangers = DB::table('accounts')->where('parent_id', $id)->whereNotIn('id', $ours)->pluck('code');

            if ($strangers->isNotEmpty()) {
                throw new RuntimeException('Cannot roll back the Sales Revenue group: '.$strangers->implode(', ').' were added under it since. Move them first.');
            }
        }

        try {
            DB::transaction(function () use ($group, $itGroup, $sales, $headIds) {
                DB::table('accounts')->whereIn('id', $headIds)->delete();

                if ($sales !== null && $group !== null && (int) $sales->parent_id === (int) $group->id) {
                    DB::table('accounts')->where('id', $sales->id)->update(['parent_id' => $group->parent_id, 'updated_at' => now()]);
                }

                if ($group !== null) {
                    DB::table('accounts')->where('id', $group->id)->delete();
                }

                if ($itGroup !== null) {
                    DB::table('accounts')->where('id', $itGroup->id)->delete();
                }

                if ($sales !== null && (string) $sales->code === self::PRODUCT_SALES[0]) {
                    DB::table('accounts')->where('id', $sales->id)->update([
                        'code' => self::SALES_GROUP[0],
                        'name' => $this->loggedOld($sales->id, 'name', self::SALES_GROUP[1]),
                        'note' => $sales->note === self::PRODUCT_SALES_NOTE ? null : $sales->note,
                        'updated_at' => now(),
                    ]);
                }
            });
        } catch (QueryException $e) {
            // Something else still names one of the new heads (a draft voucher,
            // a budget line, a mapping): the database refuses the delete.
            throw new RuntimeException('Cannot roll back the Sales Revenue group: one of the new heads is still in use ('.$e->getMessage().').', 0, $e);
        }
    }

    /**
     * The head sales post to: by slug, the way the software finds it; by code
     * 4100 only on a chart that never had the slug, and only while it is a
     * postable head (on a migrated chart 4100 is the heading).
     */
    private function productSalesAccount(): ?object
    {
        return DB::table('accounts')->where('system_slug', 'sales_revenue')->first()
            ?? DB::table('accounts')->where('code', self::SALES_GROUP[0])->where('is_postable', true)->first();
    }

    /**
     * A non-postable income group on `$code`, created when missing. An existing
     * row on that code is used only when it is already an income heading;
     * anything else stops the migration, because heads cannot sit under an
     * account that is posted to (the statements would skip them).
     *
     * @param  list<string>  $changes
     */
    private function heading(string $code, string $name, int $parentId, array &$changes): int
    {
        $existing = DB::table('accounts')->where('code', $code)->first();

        if ($existing !== null) {
            if ($existing->type !== 'income' || (bool) $existing->is_postable) {
                throw new RuntimeException("Cannot plant the {$name} group: code {$code} is already used by \"{$existing->name}\" (account id {$existing->id}), which is not an income heading. Renumber that account first, then run the migration again.");
            }

            return (int) $existing->id;
        }

        $id = DB::table('accounts')->insertGetId([
            'code' => $code,
            'name' => $name,
            'type' => 'income',
            'parent_id' => $parentId,
            'is_postable' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->log($id, 'created', ['code' => $code, 'name' => $name, 'parent_id' => $parentId, 'is_postable' => false], []);
        $changes[] = "{$code} {$name} created as a heading";

        return $id;
    }

    /**
     * A postable income head, created only when its code is free.
     *
     * @param  list<string>  $changes
     */
    private function head(string $code, string $name, int $parentId, array &$changes): void
    {
        if (DB::table('accounts')->where('code', $code)->exists()) {
            return;
        }

        $id = DB::table('accounts')->insertGetId([
            'code' => $code,
            'name' => $name,
            'type' => 'income',
            'parent_id' => $parentId,
            'is_postable' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->log($id, 'created', ['code' => $code, 'name' => $name, 'parent_id' => $parentId, 'is_postable' => true], []);
        $changes[] = "{$code} {$name} created";
    }

    /** A value as it was before up() changed it, read back from the activity log up() wrote. */
    private function loggedOld(int $accountId, string $key, mixed $default): mixed
    {
        $properties = DB::table('activity_log')
            ->where('subject_type', self::ACCOUNT)->where('subject_id', $accountId)
            ->where('properties', 'like', '%14 Oct 2026%')
            ->orderByDesc('id')->pluck('properties');

        foreach ($properties as $json) {
            $old = json_decode($json, true)['old'] ?? [];

            if (array_key_exists($key, $old)) {
                return $old[$key];
            }
        }

        return $default;
    }

    private function log(int $accountId, string $event, array $attributes, array $old): void
    {
        $this->insertLog([
            'log_name' => 'default',
            'description' => $event,
            'subject_type' => self::ACCOUNT,
            'subject_id' => $accountId,
            'properties' => json_encode(array_filter(['attributes' => $attributes, 'old' => $old, 'reason' => self::REASON])),
        ], $event);
    }

    /** @param  array<string, mixed>  $row */
    private function insertLog(array $row, ?string $event): void
    {
        if (! Schema::hasTable('activity_log')) {
            return;
        }

        $row['created_at'] = now();
        $row['updated_at'] = now();

        if ($event !== null && Schema::hasColumn('activity_log', 'event')) {
            $row['event'] = $event;
        }

        DB::table('activity_log')->insert($row);
    }
};
