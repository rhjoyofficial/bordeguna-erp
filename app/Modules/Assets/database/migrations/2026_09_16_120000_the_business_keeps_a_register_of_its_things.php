<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asset management slice A1 (Joy, 16 September 2026): the PHYSICAL register of
 * what each business keeps - a laptop, a camera and its charger, ten chairs, two
 * pairs of scissors - with where each is and what shape it is in. Plan:
 * D:\WORK\BorDeGuna\ASSET-MANAGEMENT-PLAN-2026-09-16.md.
 *
 * ⭐⭐ Separate from `fixed_assets`, the FINANCIAL register, on purpose (plan §2.1):
 * most of these things were expensed, and registering one here must never
 * create cost or depreciation.
 *
 * ⚠ The location is an office OR a warehouse, as two foreign keys rather than a
 * polymorphic pair, so neither can be deleted while something is kept there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            // Printed in every tag of the category: BDG-IT-0007.
            $table->string('code', 10);
            // individual | quantity: what a new item of this kind defaults to.
            $table->string('default_tracking', 12)->default('individual');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['business_id', 'code']);
            $table->unique(['business_id', 'name']);
        });

        Schema::create('asset_items', function (Blueprint $table) {
            $table->id();
            // The OWNER. Where it is and who has it are other columns.
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_category_id')->constrained()->restrictOnDelete();

            // ⚠ Unique across the whole group: the tag is a sticker and a QR code.
            $table->string('tag', 40)->unique();
            $table->unsignedInteger('sequence');

            $table->string('name', 160);
            // individual (quantity always 1) | quantity (a counted group).
            $table->string('tracking', 12)->default('individual');
            $table->unsignedInteger('quantity')->default(1);

            $table->string('brand', 80)->nullable();
            $table->string('model', 120)->nullable();
            $table->string('serial_number', 120)->nullable();
            $table->text('specification')->nullable();

            $table->foreignId('office_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->restrictOnDelete();
            // A room, a desk, a shelf: free words inside the place.
            $table->string('area', 120)->nullable();

            // in_store | in_use | under_repair | with_outside_party | missing | disposed
            $table->string('status', 24)->default('in_store');
            // new | good | fair | poor | damaged
            $table->string('condition', 12)->default('good');

            // "Part of": the monitor of a desktop, the charger of a camera.
            $table->foreignId('parent_id')->nullable()->constrained('asset_items')->restrictOnDelete();

            $table->date('acquired_on')->nullable();
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            // ⚠ Shown only to those who may see the financial register.
            $table->decimal('purchase_price', 15, 4)->nullable();
            $table->date('warranty_ends_on')->nullable();

            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['asset_category_id', 'sequence']);
            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'serial_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_items');
        Schema::dropIfExists('asset_categories');
    }
};
