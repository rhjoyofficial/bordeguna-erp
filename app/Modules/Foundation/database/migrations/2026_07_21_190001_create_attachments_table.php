<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            // What this file is attached to (Business logo today; employee
            // documents, correction evidence, payroll adjustments later).
            $table->morphs('attachable');
            // Groups attachments on the same record — e.g. 'logo', 'documents',
            // 'nid'. A "singleton" collection like logo keeps only its latest.
            $table->string('collection')->nullable();
            // The disk is stored per-file so retrieval works regardless of the
            // current default (local now; an S3-style disk in production later).
            $table->string('disk');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['attachable_type', 'attachable_id', 'collection']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
