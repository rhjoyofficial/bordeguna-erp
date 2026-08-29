<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recruitment (Joy, 29 August 2026): the openings, and the people who applied.
 *
 * ⚠⚠ THIS MODULE NEVER CREATES AN EMPLOYEE. Marking somebody hired records that
 * the hiring decision was made; it does not write a payroll record. An Employee
 * carries a joining date, an employment type, a probation length and a salary
 * structure, and none of those are things a recruitment screen knows. Inventing
 * them would put a wrong number into payroll on the person's first month, and
 * "do not invent business data" is the standing rule this obeys.
 *
 * So `hired_employee_id` is filled by POINTING at an employee record somebody
 * has already created in HRM. The link is how "who did we hire for that role"
 * keeps an answer a year later, and it is nullable because the answer can arrive
 * days after the decision.
 *
 * ⚠ `phone_normalized` on the applicant for the same reason leads and customers
 * carry it: the same person applies twice, six months apart, spelling their
 * number differently. It WARNS and never blocks - a household sharing a number
 * is as real here as it is in CRM.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_openings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->string('title');
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('designation_id')->nullable()->constrained('designations')->nullOnDelete();

            $table->text('description')->nullable();

            // ⚠ How many people are wanted. Recorded so "is this role still
            // open" has an answer that is not somebody's memory: two openings
            // with one hire is still an open role.
            $table->unsignedSmallInteger('openings')->default(1);

            $table->string('status', 20)->default('open');
            $table->date('opened_on');
            $table->date('closes_on')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'status']);
        });

        Schema::create('job_applicants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_opening_id')->constrained('job_openings')->cascadeOnDelete();

            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('phone_normalized', 32)->nullable();
            $table->string('email')->nullable();

            // Where they came from. Free text: unlike a CRM lead source this is
            // not a reporting axis anybody has asked for.
            $table->string('source')->nullable();

            // ⚠ What they ASKED for, not what they were offered. An expectation
            // recorded at application time is the single most useful number in a
            // hiring conversation three weeks later, and it is not a salary.
            $table->decimal('expected_salary', 15, 2)->nullable();

            $table->string('stage', 20)->default('applied');
            $table->text('note')->nullable();

            // ⚠⚠ Why somebody was turned down, recorded at the moment it is
            // decided. Without it a rejected pile is a list of names nobody can
            // explain, and the same person is invited back next year.
            $table->string('outcome_reason')->nullable();

            // ⚠ Points at an employee somebody created in HRM. This module never
            // creates one. See the file note.
            $table->foreignId('hired_employee_id')->nullable()->constrained('employees')->nullOnDelete();

            $table->date('applied_on');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'stage']);
            // ⚠ NOT unique. The same number applying for two roles is normal,
            // and so is a family sharing one phone.
            $table->index(['business_id', 'phone_normalized']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_applicants');
        Schema::dropIfExists('job_openings');
    }
};
