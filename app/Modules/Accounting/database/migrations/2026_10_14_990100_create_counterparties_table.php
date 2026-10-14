<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Counterparties: the people and firms the business lends to or borrows from
 * (loans plan v2, phase 1, 14 Oct 2026). Every line on a loan head names one
 * (Support\PartyLedgers), so the head is the ledger and the counterparty on
 * each line is the sub-ledger, exactly as 1170 and the employee already are.
 *
 * ⚠⚠ GROUP-WIDE, NO business_id (see the Counterparty model for why).
 *
 * ⚠ ONE IDENTITY. A customer, supplier or employee who also lends or borrows
 * is LINKED, never typed in twice (ChatGPT review, point 4): each of the three
 * links is unique, so one customer can stand behind one counterparty only.
 *
 * ⚠ The three `*_key` columns are matching forms of the name, the phone and the
 * TIN, written by the model on every save and used for nothing except finding
 * the same party twice. The columns people typed are never rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('counterparties', function (Blueprint $table) {
            $table->id();
            $table->string('legal_name');
            // company, individual, bank, nbfi, government, other (CounterpartyKind).
            $table->string('kind', 20)->default('company');
            $table->boolean('related_party')->default(false);
            // Why the party is related: "the owner's brother", "owner holds 60%".
            $table->text('relationship')->nullable();
            $table->string('tin', 40)->nullable();
            $table->string('bin', 40)->nullable();
            // NID for a person, the RJSC or licence number for a firm.
            $table->string('registration_no', 60)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            // A bank's branch and routing number, or the party's own account.
            $table->text('bank_details')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('customer_id')->nullable()->unique()->constrained('customers')->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->unique()->constrained('suppliers')->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->unique()->constrained('employees')->nullOnDelete();

            $table->boolean('is_active')->default(true);

            $table->string('name_key')->index();
            $table->string('phone_key', 40)->nullable()->index();
            $table->string('tin_key', 40)->nullable()->index();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('counterparties');
    }
};
