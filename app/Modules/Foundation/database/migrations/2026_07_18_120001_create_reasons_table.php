<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            // Namespaces the reason list per use-case, e.g. 'inventory.stock_adjustment'
            // vs 'inventory.stock_transfer' — each context has its own managed list.
            $table->string('context');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['business_id', 'context', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reasons');
    }
};
