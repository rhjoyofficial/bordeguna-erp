<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money moved from one of the four businesses to another (finance decision C2,
 * 29 Sep 2026). One proprietor, four sets of books: the move is an internal
 * balance, recorded in BOTH businesses and cancelling to zero across the group.
 *
 *   sending business    Dr 1195 Due from Other Businesses (party: receiver) / Cr its bank or till
 *   receiving business  Dr its bank or till / Cr 2190 Due to Other Businesses (party: sender)
 *
 * The same document settles a balance the other way: money sent back reduces
 * what the first business was owed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inter_business_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_business_id')->constrained('businesses')->restrictOnDelete();
            $table->foreignId('to_business_id')->constrained('businesses')->restrictOnDelete();
            $table->foreignId('from_money_account_id')->constrained('money_accounts')->restrictOnDelete();
            $table->foreignId('to_money_account_id')->constrained('money_accounts')->restrictOnDelete();
            $table->date('transferred_on');
            $table->decimal('amount', 15, 4);
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['from_business_id', 'transferred_on']);
            $table->index(['to_business_id', 'transferred_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inter_business_transfers');
    }
};
