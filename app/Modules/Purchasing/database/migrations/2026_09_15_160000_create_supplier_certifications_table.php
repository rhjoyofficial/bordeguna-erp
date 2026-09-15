<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A supplier's certifications (Joy's answer 18, CRM lead slice 2e): type, number,
 * expiry and a scan, all optional, editable from the supplier screen.
 *
 * ⚠ The type is the one thing an entry needs, because an entry with no type says
 * nothing. `name` names an "Other" certification. The scan is an attachment on
 * the certification (collection `scan`), not a column.
 *
 * ⚠ restrictOnDelete on the supplier: a supplier is never deleted in this ERP
 * (SupplierController::destroy deactivates), and a certification's scan must not
 * be orphaned on disk by a cascade nobody sees.
 *
 * Measured on production 15 Sep 2026: 6 suppliers, no certifications recorded
 * anywhere, so the table starts empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_certifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->string('name', 120)->nullable();
            $table->string('number', 100)->nullable();
            $table->date('expires_on')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['supplier_id', 'expires_on'], 'supplier_certs_supplier_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_certifications');
    }
};
