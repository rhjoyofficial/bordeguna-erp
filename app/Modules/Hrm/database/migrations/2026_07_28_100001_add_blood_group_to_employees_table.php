<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Blood group (EMP-04 family).
     *
     * Added when Bor de Guna's real employee data arrived carrying it for five
     * of nine people and the column did not exist — so migration would have
     * silently dropped it and somebody would have had to collect it again.
     *
     * It sits beside the emergency contact rather than with the identity
     * fields, because that is what it is for: it is the field somebody reads
     * when an employee is hurt at work, which is also why it belongs on the
     * record rather than in a document.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('blood_group', 8)->nullable()->after('emergency_contact_relationship');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('blood_group');
        });
    }
};
