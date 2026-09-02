<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Group product links (Module 6, Slice 2). Same design as partner links:
// no shared master, no renames - the link IS the knowledge.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_product_links', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
        });

        Schema::create('group_product_link_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('link_id')->constrained('group_product_links')->cascadeOnDelete();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('product_id');
            $table->timestamps();
            $table->unique(['business_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_product_link_members');
        Schema::dropIfExists('group_product_links');
    }
};
