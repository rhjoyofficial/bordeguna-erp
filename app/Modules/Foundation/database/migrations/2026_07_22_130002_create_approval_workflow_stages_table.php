<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One ordered stage of a workflow. A stage authorises by role AND/OR
     * permission (a user may act if they hold any allowed role or any allowed
     * permission) — so role-named flows (HR Head → Finance Head → Management)
     * and the existing permission-gated flows both express cleanly, which is
     * how the two current consumers migrate without a rewrite.
     */
    public function up(): void
    {
        Schema::create('approval_workflow_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_workflow_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence'); // 1..N, order of approval
            $table->string('name');
            $table->json('allowed_roles')->nullable();       // spatie role names
            $table->json('allowed_permissions')->nullable(); // permission names
            $table->timestamps();

            $table->unique(['approval_workflow_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_workflow_stages');
    }
};
