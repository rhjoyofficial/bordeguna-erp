<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ 27 Oct 2026 (Joy): "their must two thing one is leave approve but unpaid
 * leave it will be, also can be approve also another approval needs to mark as
 * paid or unpaid not related with the approval of leave."
 *
 * Approving a leave says the person may be away. Whether those days are PAID is
 * a second decision, taken by the authority at approval or any time after,
 * until the payroll covering the days closes. The request keeps the result in
 * `unpaid_days` (payroll's one source for Leave Without Pay); this table keeps
 * every decision that changed it: from what, to what, what it did to the
 * balance, who, when and why.
 *
 *   quota_days    signed: + days given back to the balance, - days drawn
 *   granted_days  after the decision, the paid days the COMPANY pays because
 *                 the balance could not (27 Sep 2026 grant), not quota
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_pay_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_request_id')->constrained()->cascadeOnDelete();
            $table->decimal('unpaid_days_before', 6, 2);
            $table->decimal('unpaid_days_after', 6, 2);
            $table->decimal('quota_days', 6, 2)->default(0);
            $table->decimal('granted_days', 6, 2)->default(0);
            // Chosen by the approver on the approval card, or decided later.
            $table->boolean('at_approval')->default(false);
            $table->string('reason', 1000);
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamps();

            $table->index(['leave_request_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_pay_decisions');
    }
};
