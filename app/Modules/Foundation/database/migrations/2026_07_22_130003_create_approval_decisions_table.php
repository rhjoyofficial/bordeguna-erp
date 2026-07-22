<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every stage decision, recorded separately — so a multi-stage approval
     * keeps a full per-stage audit (who approved which stage, when, with what
     * note), not just the final outcome on the request. stage_name is
     * snapshotted so history stays accurate even if the workflow is later
     * reconfigured.
     */
    public function up(): void
    {
        Schema::create('approval_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_request_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('stage_sequence');
            $table->string('stage_name');
            $table->foreignId('decided_by')->constrained('users')->restrictOnDelete();
            $table->string('decision'); // approved | rejected
            $table->text('note')->nullable();
            $table->timestamp('decided_at');
            $table->timestamps();

            $table->index('approval_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_decisions');
    }
};
