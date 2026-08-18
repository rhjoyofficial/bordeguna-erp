<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-SB-6: a posted supplier bill is immutable and its correction is a reversal.
 *
 * ⚠⚠ Before this, a bill that had already posted could simply be DELETED. Its
 * journal stayed standing with nothing behind it, and the purchase order line
 * kept the invoiced quantity forever, so the goods could never be billed again.
 * Nothing reconciled the two and the trial balance still tied while it happened.
 *
 * The bill itself is kept when reversed, because the paper really did arrive and
 * the audit trail should record what became of it. These three columns are what
 * let a reversed bill say so rather than vanish.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_bills', function (Blueprint $table) {
            $table->timestamp('reversed_at')->nullable()->after('note');
            $table->foreignId('reversed_by')->nullable()->after('reversed_at')
                ->constrained('users')->restrictOnDelete();
            $table->text('reversal_note')->nullable()->after('reversed_by');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_bills', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reversed_by');
            $table->dropColumn(['reversed_at', 'reversal_note']);
        });
    }
};
