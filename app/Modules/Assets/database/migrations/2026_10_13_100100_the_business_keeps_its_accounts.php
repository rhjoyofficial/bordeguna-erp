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
 * ⚠ Responsible employee and 2FA custodian RESTRICT on delete: an employee
 * who holds the company's recovery phone cannot quietly vanish from the record.
 *
 * ⭐ Review of 13 October (ChatGPT ruling 2, plan §8): `responsible_employee_id`
 * is the person accountable INSIDE the company, and `registered_as` is the
 * identity the account is registered under at the provider (a personal email,
 * a phone number): recording Joy as responsible for the Facebook page does not
 * make the company the registered owner of it, and the record must not pretend
 * so. `two_factor_method` names how the second factor works; the employee
 * column beside it is the custodian of that factor, not the whole MFA setup.
 *
 * ⭐ Group-wide accounts: one real account (a hosting plan, a Google Workspace)
 * serves several of the group's businesses. It is recorded ONCE, in its home
 * `business_id`, and `company_account_businesses` lists the OTHER businesses
 * it also serves; they read it, and only the home business changes it or
 * reveals its logins.
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
            // The identity the account is registered under at the provider, when it is not the company's own.
            $table->string('registered_as', 190)->nullable();
            $table->foreignId('responsible_employee_id')->nullable()->constrained('employees')->restrictOnDelete();
            // TwoFactorMethod: none | authenticator | sms | email | security_key | other
            $table->string('two_factor_method', 20)->default('none');
            // The custodian of that second factor (the phone, the authenticator).
            $table->foreignId('two_factor_employee_id')->nullable()->constrained('employees')->restrictOnDelete();
            $table->date('renews_on')->nullable();
            // RenewalCycle: none | monthly | yearly | other
            $table->string('renewal_cycle', 10)->default('none');
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

        // The OTHER businesses of the group an account also serves. Its home
        // business is `company_accounts.business_id` and is never in here.
        Schema::create('company_account_businesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_account_id')->constrained('company_accounts')->cascadeOnDelete();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['company_account_id', 'business_id'], 'company_account_businesses_unique');
            $table->index('business_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_account_businesses');
        Schema::dropIfExists('company_account_access');
        Schema::dropIfExists('company_accounts');
    }
};
