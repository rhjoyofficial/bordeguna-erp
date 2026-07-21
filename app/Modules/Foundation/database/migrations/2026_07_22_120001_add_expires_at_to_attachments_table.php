<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            // Optional expiry for documents that lapse — an employee's NID,
            // passport or work permit today; contracts and licences later.
            $table->date('expires_at')->nullable()->after('size');
        });
    }

    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropColumn('expires_at');
        });
    }
};
