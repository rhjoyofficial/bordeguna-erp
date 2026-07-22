<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Completes the EMP-04 mandatory field set. Contact/address, birth
     * certificate, emergency contact, confirmation/contract dates, and
     * bank / Mobile-Financial-Service account. All nullable and additive —
     * existing employees stay valid, HR fills them in through the edit form.
     * Bank / MFS / NID are sensitive (restricted-visibility per EMP-04); the
     * schema just holds them, access is governed elsewhere.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // Contact / personal
            $table->text('present_address')->nullable()->after('phone');
            $table->text('permanent_address')->nullable()->after('present_address');
            $table->string('birth_certificate_no')->nullable()->after('national_id');

            // Emergency contact
            $table->string('emergency_contact_name')->nullable()->after('joining_date');
            $table->string('emergency_contact_phone')->nullable()->after('emergency_contact_name');
            $table->string('emergency_contact_relationship')->nullable()->after('emergency_contact_phone');

            // Employment dates (joining_date already exists)
            $table->date('confirmation_date')->nullable()->after('emergency_contact_relationship');
            $table->date('contract_end_date')->nullable()->after('confirmation_date');

            // Bank account
            $table->string('bank_account_name')->nullable()->after('contract_end_date');
            $table->string('bank_account_number')->nullable()->after('bank_account_name');
            $table->string('bank_name')->nullable()->after('bank_account_number');
            $table->string('bank_branch')->nullable()->after('bank_name');
            $table->string('bank_routing_number')->nullable()->after('bank_branch');

            // Mobile Financial Service (bKash / Nagad / Rocket / Upay)
            $table->string('mfs_provider')->nullable()->after('bank_routing_number');
            $table->string('mfs_account_number')->nullable()->after('mfs_provider');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn([
                'present_address', 'permanent_address', 'birth_certificate_no',
                'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relationship',
                'confirmation_date', 'contract_end_date',
                'bank_account_name', 'bank_account_number', 'bank_name', 'bank_branch', 'bank_routing_number',
                'mfs_provider', 'mfs_account_number',
            ]);
        });
    }
};
