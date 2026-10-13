<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asset register slice 3 (13 October 2026): the logins a thing or an account
 * carries. A laptop's Windows password, a router's admin login, the BIOS PIN;
 * later (slice 4) the password of a mailbox or a hosting panel.
 *
 * ⚠⚠ `secret` HOLDS CIPHERTEXT. The model's `encrypted` cast means a dump, a
 * replica or a `SELECT *` in a console shows noise. Lose `APP_KEY`, lose every
 * password: the key must be backed up off the box before the first real one
 * is stored (plan §3, precondition).
 *
 * ⚠ `hint` is the last four characters, computed at save time, so a list can
 * say "••••1234" without decrypting anything.
 *
 * ⚠ A morph rather than two nullable columns: one credentials table serves
 * things AND accounts (plan §3, slice 4), and the owner is always exactly one.
 *
 * ⭐ Review of 13 October (ChatGPT ruling 1 and 3, plan §8): a login has a
 * KIND and a POLICY. `kind` separates the login a holder is meant to use
 * (user_login) from a shared administrator password, a recovery key, or
 * something else; `holder_may_reveal` says whether the current holder of the
 * thing (or an employee with access to the account) may read it at all;
 * `last_rotated_at` records that the password was replaced, without ever
 * keeping the old one; `needs_review` is raised when the thing is returned or
 * passed on, so the person who knew the password is no longer the only one
 * who knows whether it should change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            // AssetItem or CompanyAccount.
            $table->string('credentialable_type', 191);
            $table->unsignedBigInteger('credentialable_id');
            $table->string('label', 80);
            // CredentialKind: user_login | admin | recovery | other
            $table->string('kind', 20)->default('user_login');
            // The holder of the thing / an employee with access may reveal it.
            // ⚠ Ignored for admin and recovery, which only a privileged permission opens.
            $table->boolean('holder_may_reveal')->default(true);
            $table->string('username', 190)->nullable();
            $table->text('secret');
            $table->string('hint', 8);
            $table->string('host_or_url', 255)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            // Who last looked, and when. The activity log has every look.
            $table->timestamp('last_revealed_at')->nullable();
            $table->foreignId('last_revealed_by')->nullable()->constrained('users')->restrictOnDelete();
            // When the secret was last replaced. The old value is gone; this says that it went.
            $table->timestamp('last_rotated_at')->nullable();
            // Raised on a return or reassignment of the thing; cleared by "Reviewed" or by a replacement.
            $table->boolean('needs_review')->default(false);
            $table->timestamps();

            $table->index(['credentialable_type', 'credentialable_id'], 'asset_credentials_owner_index');
            $table->index('business_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_credentials');
    }
};
