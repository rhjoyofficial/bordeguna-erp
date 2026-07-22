<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A generic "propose changes to a record, then approve, then apply" request.
     * Subject is polymorphic (Employee today; Supplier, Product, Business, bank
     * accounts, and future modules plug in by implementing HandlesChangeRequest)
     * — nothing here is HR-specific. Approval itself rides the shared multi-stage
     * engine via the Approvable trait (an ApprovalRequest is attached on submit);
     * this table only holds *what* was proposed and *which* workflow governs it.
     */
    public function up(): void
    {
        Schema::create('change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->morphs('subject'); // the record to change
            $table->string('workflow_key'); // which approval workflow governs it
            // Named proposed_changes, not "changes": Eloquent reserves a
            // protected $changes property (dirty tracking), so a "changes"
            // attribute is unreadable as $this->changes from inside the model.
            $table->json('proposed_changes'); // proposed field => new value
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('change_requests');
    }
};
