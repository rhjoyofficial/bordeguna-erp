<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Courier hub slice 1 (11 Oct 2026). Joy: "all settings must be available on UI
 * so I can change them anytime". A courier account is a row an Owner fills in on
 * Settings > Couriers, never a value in .env.
 *
 * ⚠ `courier_accounts` is NOT business-scoped: one Pathao merchant account
 * serves LaLaDia and Bionic. Which business uses it, from which pickup store and
 * into which courier money account lives on `courier_account_business`
 * (ChatGPT ruling 11).
 *
 * ⚠ What a provider IS (its fields, its official URLs, what it can do) lives in
 * code (Sales\Couriers\Providers). The provider table only lets an account point
 * at a row; the five rows are inserted here so a deploy needs no seeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_providers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            // A driver exists for it in this ERP. The others are listed so the
            // screen can say "not yet connected" instead of not knowing them.
            $table->boolean('is_connected')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(100);
            $table->timestamps();
        });

        Schema::create('courier_accounts', function (Blueprint $table) {
            $table->id();
            // The future webhook address names this, never the numeric id.
            $table->uuid('uuid')->unique();
            $table->foreignId('courier_provider_id')->constrained('courier_providers')->restrictOnDelete();
            $table->string('label', 120);
            $table->string('environment', 10);
            // ⚠ One of the provider's official URLs for this environment; the
            // server refuses anything else (ChatGPT risk 6).
            $table->string('base_url', 255);
            // ⚠⚠ Ciphertext (encrypted:array). Never sent to a browser.
            $table->text('credentials')->nullable();
            $table->json('options')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamp('activated_at')->nullable();
            // A keyed hash of the credentials, environment and URL that passed
            // the last test. Activation needs it to equal the current one.
            $table->string('tested_fingerprint', 64)->nullable();
            $table->timestamp('last_test_at')->nullable();
            $table->boolean('last_test_ok')->nullable();
            $table->string('last_test_message', 500)->nullable();
            $table->json('last_test_stores')->nullable();
            $table->unsignedInteger('last_test_city_count')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['courier_provider_id', 'environment', 'label']);
        });

        Schema::create('courier_account_business', function (Blueprint $table) {
            $table->id();
            $table->foreignId('courier_account_id')->constrained('courier_accounts')->cascadeOnDelete();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('provider_store_id', 50)->nullable();
            $table->string('provider_store_name', 150)->nullable();
            // ⚠ A courier-type money account of THIS business (1145).
            $table->foreignId('money_account_id')->nullable()->constrained('money_accounts')->restrictOnDelete();
            $table->boolean('is_default')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['courier_account_id', 'business_id']);
            $table->index('business_id');
        });

        Schema::create('courier_api_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('courier_account_id')->constrained('courier_accounts')->cascadeOnDelete();
            $table->string('method', 8);
            // ⚠ The path only. No query string, no body, no header: never a secret.
            $table->string('endpoint', 255);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->boolean('ok')->default(false);
            $table->string('error', 500)->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });

        $now = now();

        DB::table('courier_providers')->insert([
            ['code' => 'pathao', 'name' => 'Pathao', 'is_connected' => true, 'sort_order' => 10, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'carrybee', 'name' => 'CarryBee', 'is_connected' => true, 'sort_order' => 20, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'steadfast', 'name' => 'Steadfast', 'is_connected' => false, 'sort_order' => 30, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'redx', 'name' => 'RedX', 'is_connected' => false, 'sort_order' => 40, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'paperfly', 'name' => 'Paperfly', 'is_connected' => false, 'sort_order' => 50, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_api_calls');
        Schema::dropIfExists('courier_account_business');
        Schema::dropIfExists('courier_accounts');
        Schema::dropIfExists('courier_providers');
    }
};
