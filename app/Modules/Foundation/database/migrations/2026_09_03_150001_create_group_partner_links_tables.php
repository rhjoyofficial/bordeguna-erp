<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Group partner links (Module 6, Slice 1; design doc
// BDG-ERP-M6-GROUP-LAYER-DESIGN.md). Partners STAY per-business; a link is
// a reversible statement of identity that touches no document.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_partner_links', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('kind');
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
        });

        Schema::create('group_partner_link_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('link_id')->constrained('group_partner_links')->cascadeOnDelete();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('partner_id');
            $table->timestamps();
            // One row of one company belongs to at most one group party.
            $table->unique(['business_id', 'partner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_partner_link_members');
        Schema::dropIfExists('group_partner_links');
    }
};
