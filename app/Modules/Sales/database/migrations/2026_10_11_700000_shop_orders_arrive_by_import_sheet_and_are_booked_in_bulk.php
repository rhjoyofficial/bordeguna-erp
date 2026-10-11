<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shop orders by import sheet, and parcels booked in bulk (Joy, 11 Oct 2026).
 *
 * ⚠ Additive only: four new tables, nothing on an existing one. The order's
 * shop number already has a unique home (`sales_orders.shop_reference`), which
 * is what makes an import idempotent per order; the file hash below makes it
 * idempotent per file.
 *
 * ⚠ The brand is kept on the import row as a plain id and name, not only on the
 * order: `sales_orders.brand_id` arrives in another branch, and the importer
 * writes it there only when that column exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_order_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('file_name', 255);
            // The original bytes, on the private disk. Customer names and phones.
            $table->string('file_path', 500);
            $table->char('file_sha256', 64);
            $table->unsignedBigInteger('file_size')->default(0);
            $table->unsignedInteger('rows')->default(0);
            $table->unsignedInteger('orders_created')->default(0);
            $table->unsignedInteger('orders_skipped')->default(0);
            $table->unsignedInteger('orders_failed')->default(0);
            $table->boolean('auto_approve')->default(false);
            // method => money_account_id, as chosen on the preview screen.
            $table->json('money_mappings')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'file_sha256'], 'sales_order_imports_business_hash_unique');
        });

        Schema::create('sales_order_import_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_order_import_id')->constrained()->cascadeOnDelete();
            $table->string('order_ref', 40);
            $table->foreignId('sales_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->string('brand_name', 120)->nullable();
            $table->string('source_channel', 20)->nullable();
            // created | skipped | failed
            $table->string('outcome', 10);
            $table->string('message', 500)->nullable();
            $table->timestamps();

            $table->index(['business_id', 'order_ref'], 'sales_order_import_orders_ref_index');
        });

        Schema::create('courier_bulk_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            // Null = the default account for each order's brand.
            $table->foreignId('courier_account_id')->nullable()->constrained('courier_accounts')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            // queued | running | finished | failed
            $table->string('status', 10)->default('queued');
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('done')->default(0);
            $table->unsignedInteger('booked')->default(0);
            $table->unsignedInteger('refused')->default(0);
            $table->unsignedInteger('unknown')->default(0);
            $table->unsignedInteger('fix_address')->default(0);
            $table->string('error', 500)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'status']);
        });

        Schema::create('courier_bulk_run_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('courier_bulk_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('delivery_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('courier_account_id')->nullable()->constrained('courier_accounts')->nullOnDelete();
            $table->foreignId('courier_booking_request_id')->nullable()->constrained('courier_booking_requests')->nullOnDelete();
            $table->foreignId('consignment_id')->nullable()->constrained()->nullOnDelete();
            // pending | working | booked | refused | unknown | fix_address
            $table->string('status', 12)->default('pending');
            $table->string('consignment_number', 100)->nullable();
            $table->decimal('fee_quoted', 15, 4)->nullable();
            $table->unsignedInteger('cod_amount')->nullable();
            $table->string('message', 500)->nullable();
            $table->timestamps();

            $table->index(['business_id', 'sales_order_id', 'status'], 'courier_bulk_run_items_order_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_bulk_run_items');
        Schema::dropIfExists('courier_bulk_runs');
        Schema::dropIfExists('sales_order_import_orders');
        Schema::dropIfExists('sales_order_imports');
    }
};
