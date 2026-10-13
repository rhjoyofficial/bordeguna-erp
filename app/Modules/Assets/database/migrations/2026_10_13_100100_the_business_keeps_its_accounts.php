<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asset register slice 4 (13 October 2026): the accounts hub. Every account
 * the business owns - a mailbox, a domain, a hosting panel, a Facebook page,
 * a courier portal, a bank's internet banking - on record, with who is
 * responsible for it, who has access, and when it renews.
 *
 * ⚠ Owner and 2FA holder RESTRICT on delete: an employee who holds the
 * company's recovery phone cannot quietly vanish from the record.
 *
 * ⚠ `renewal_reminded_stage` + `renewal_reminded_for` make each reminder
 * stage (30 days, 7 days, overdue) fire ONCE per renewal date: when
 * `renews_on` moves after a renewal, the pair no longer matches and the
 * cycle starts again.
 *
 * ⛔ No password column. Logins live in `asset_credentials` (slice 3), the
 * one encrypted table, reached through the same policy as a thing's logins.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            // CompanyAccountKind: email | domain | hosting | website | ...
            $table->string('kind', 30);
            $table->string('name', 120);
            $table->string('provider', 120)->nullable();
            // The address, the domain, the page id, the account number.
            $table->string('identifier', 190)->nullable();
            $table->string('url', 255)->nullable();
            $table->foreignId('owner_employee_id')->nullable()->constrained('employees')->restrictOnDelete();
            $table->foreignId('two_factor_employee_id')->nullable()->constrained('employees')->restrictOnDelete();
            $table->date('renews_on')->nullable();
            $table->decimal('renewal_cost', 15, 4)->nullable();
            $table->foreignId('renewal_money_account_id')->nullable()->constrained('money_accounts')->restrictOnDelete();
            $table->string('recovery_email', 190)->nullable();
            $table->string('recovery_phone', 30)->nullable();
            // CompanyAccountStatus: active | suspended | closed
            $table->string('status', 20)->default('active');
            $table->text('note')->nullable();
            // 30 | 7 | overdue, and the renews_on it was said for.
            $table->string('renewal_reminded_stage', 10)->nullable();
            $table->date('renewal_reminded_for')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'kind']);
            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'renews_on']);
        });

        Schema::create('company_account_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_account_id')->constrained('company_accounts')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            // AccountAccessRole: admin | user
            $table->string('role', 10);
            $table->date('granted_on');
            // Null = access is current. Revoking writes the date; nothing is deleted.
            $table->date('revoked_on')->nullable();
            $table->foreignId('granted_by')->constrained('users')->restrictOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->index(['company_account_id', 'employee_id'], 'company_account_access_account_employee_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_account_access');
        Schema::dropIfExists('company_accounts');
    }
};
