<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Turns the single-stage ApprovalRequest into a multi-stage workflow
     * instance without breaking anything: `status` stays the OVERALL result
     * (pending/approved/rejected) that every existing reader relies on; we only
     * add which workflow drives it and which stage it's currently on.
     * workflow_id is nullable — a request with no configured workflow behaves
     * as a single implicit stage, exactly as before, which is what keeps the
     * existing Inventory/Purchasing approvals (and their tests) working
     * unchanged.
     */
    public function up(): void
    {
        Schema::table('approval_requests', function (Blueprint $table) {
            $table->foreignId('workflow_id')->nullable()->after('business_id')
                ->constrained('approval_workflows')->nullOnDelete();
            $table->unsignedInteger('current_stage')->default(1)->after('workflow_id');
        });
    }

    public function down(): void
    {
        Schema::table('approval_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('workflow_id');
            $table->dropColumn('current_stage');
        });
    }
};
