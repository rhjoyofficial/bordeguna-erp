<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Money that came IN and was not a sale.
 *
 * ⚠⚠ THE HOLE THIS FILLS. `4900 Other Income` has existed and been postable
 * since the chart was first seeded, and NOT ONE SCREEN IN THE APPLICATION COULD
 * WRITE TO IT. Scrap sold, bank interest, rent received, a supplier refund, a
 * forfeited deposit: every one of them had to be entered as a hand-written
 * journal, by somebody willing to think in debits and credits, on a screen the
 * rest of the system deliberately spares people. The expense side has had a
 * proper document since August, with its own numbering, evidence and print
 * view. The income side had nothing. This is its mirror.
 *
 * ⚠ A voucher is RECEIVED WHEN IT IS RECORDED - `money_account_id` is required,
 * exactly as on the expense side. Income owed but not yet received is a
 * receivable, which is a different event with a different document, and
 * inventing it here would create an asset with no settlement path.
 *
 * ⚠⚠ THIS IS NOT A SALES DOCUMENT AND MUST NEVER BECOME ONE. See
 * RecordIncomeVoucher for why 4100 Sales Revenue is refused outright: a sale
 * recorded here would have no customer, no stock movement and no cost of goods,
 * so the gross margin would be a fiction and the sales module would disagree
 * with the ledger about what was sold.
 */
return new class extends Migration
{
    /**
     * The heads worth having on day one. `4900 Other Income` becomes the
     * heading they sit under.
     *
     * ⚠ A voucher whose only possible destination is "Other Income" answers
     * "how much other income" and nothing else. The same argument the master
     * expense chart made on 5 September, applied to the other side of the
     * profit and loss: a group with real heads under it can answer "what did
     * scrap earn this quarter".
     *
     * @var list<array{0: string, 1: string}>
     */
    private const HEADS = [
        ['4910', 'Scrap and Waste Sales'],
        ['4920', 'Interest Income'],
        ['4930', 'Rent Received'],
        ['4940', 'Supplier Rebates and Refunds'],
        ['4990', 'Miscellaneous Income'],
    ];

    public function up(): void
    {
        Schema::create('income_vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->string('voucher_number', 30);
            $table->date('received_on');

            // Required: a voucher IS the money arriving.
            $table->foreignId('money_account_id')->constrained()->restrictOnDelete();

            // Who it came from, when that is somebody the system knows. Most
            // other income arrives from a scrap dealer or a bank that will never
            // be a customer record, so this stays optional and `received_from`
            // carries the name for everyone else.
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('received_from')->nullable();

            $table->decimal('total', 15, 4);
            $table->string('reference')->nullable();
            $table->text('note')->nullable();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            // ⚠ Named explicitly. MySQL truncates an identifier at 64
            // characters and the convention-generated name for this pair has
            // gone over that limit on this project before, aborting a migration
            // halfway on production with the tables already created.
            $table->unique(['business_id', 'voucher_number'], 'income_voucher_number_unique');
            $table->index(['business_id', 'received_on'], 'income_voucher_received_index');
        });

        Schema::create('income_voucher_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('income_voucher_id')->constrained()->cascadeOnDelete();

            // ⚠⚠ The line names its account directly, for the same reason the
            // expense voucher does: the classification IS the content of the
            // document. Somebody is deciding this was scrap rather than
            // interest, and that decision is the only thing distinguishing one
            // voucher from another.
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->string('description')->nullable();
            $table->decimal('amount', 15, 4);
            $table->timestamps();

            $table->index(['business_id', 'income_voucher_id'], 'income_voucher_line_index');
        });

        // ─── 4900 becomes a heading, with heads under it ─────────────────────
        //
        // ⚠ Safe precisely because nothing has ever posted to it: the account
        // has existed since the chart was seeded and the whole point of this
        // change is that no screen could reach it. The guard below says so out
        // loud rather than trusting that.
        $other = DB::table('accounts')->where('code', '4900')->first();

        if ($other === null) {
            return;
        }

        $posted = DB::table('journal_lines')->where('account_id', $other->id)->exists();

        if (! $posted) {
            DB::table('accounts')->where('id', $other->id)->update([
                'name' => 'Other Income',
                'is_postable' => false,
                'updated_at' => now(),
            ]);
        }

        foreach (self::HEADS as [$code, $name]) {
            if (DB::table('accounts')->where('code', $code)->exists()) {
                continue;
            }

            DB::table('accounts')->insert([
                'code' => $code,
                'name' => $name,
                'type' => 'income',
                // ⚠ If 4900 could not be made a heading because somebody had
                // already posted to it, these still need a home, and the income
                // root is the honest one. A child of a postable parent would
                // vanish from the profit and loss, which groups on the nearest
                // NON-postable ancestor.
                'parent_id' => $posted
                    ? DB::table('accounts')->where('code', '4000')->value('id')
                    : $other->id,
                'is_postable' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('income_voucher_lines');
        Schema::dropIfExists('income_vouchers');

        foreach (self::HEADS as [$code, $name]) {
            $id = DB::table('accounts')->where('code', $code)->value('id');

            if ($id === null || DB::table('journal_lines')->where('account_id', $id)->exists()) {
                continue;
            }

            DB::table('accounts')->where('id', $id)->delete();
        }

        DB::table('accounts')->where('code', '4900')->update([
            'name' => 'Other Income',
            'is_postable' => true,
            'updated_at' => now(),
        ]);
    }
};
