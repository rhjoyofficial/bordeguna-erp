<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Buy X Get Y free (POS spec section 12, phase 1). A CART rule, never a
// price: the discounts engine owns every cheaper-price shape through the
// resolver, and the resolver keeps knowing nothing about this table.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->string('name');
            $table->unsignedBigInteger('buy_product_id');
            $table->decimal('buy_quantity', 12, 4);
            $table->unsignedBigInteger('get_product_id');
            $table->decimal('get_quantity', 12, 4);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status')->default('active');
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->index(['business_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotions');
    }
};
