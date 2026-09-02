<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Held / parked carts (Module 3, Slice 2). A hold is a bookmark of the
// counter state and nothing else: no stock reserved, no document posted,
// no price frozen. The lines live as JSON because they are a draft the
// resolver will re-price, never rows anything reports on.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_held_carts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->string('label')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('warehouse_id');
            $table->json('payload');
            $table->unsignedBigInteger('held_by');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_held_carts');
    }
};
