<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A named, business-owned approval workflow definition (config, not code) —
     * e.g. "Purchase order approval" or "Payroll unlock". Referenced by a stable
     * `key` that an approvable maps to (StockRequest → 'stock_request'). Seeded
     * with Bor de Guna's defaults; editable per business so a sequence can
     * change without a code change. The ordered stages live in
     * approval_workflow_stages.
     */
    public function up(): void
    {
        Schema::create('approval_workflows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('key'); // stable identifier an approvable maps to
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['business_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_workflows');
    }
};
