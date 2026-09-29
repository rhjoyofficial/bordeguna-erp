<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The cheque register (finance decision C5, 29 Sep 2026).
 *
 * A cheque is already recorded on its voucher (instrument type, number, date,
 * bank). This table only adds what happened to it afterwards: pending until it
 * clears, bounces or is cancelled. No row means pending.
 *
 * ⚠ A bounced or cancelled cheque never moved the money, so its voucher is
 * reversed at the same moment; the status row points at a voucher that is
 * then marked Reversed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cheque_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('voucher_id')->unique()->constrained('vouchers')->restrictOnDelete();
            $table->string('status', 16); // cleared | bounced | cancelled
            $table->date('status_on');
            $table->string('note', 500)->nullable();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cheque_statuses');
    }
};
