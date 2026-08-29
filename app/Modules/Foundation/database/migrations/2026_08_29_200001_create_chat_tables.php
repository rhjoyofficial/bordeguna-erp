<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Internal chat (Joy, 29 August 2026).
 *
 * ⚠⚠ SAID PLAINLY, BECAUSE IT MATTERS FOR WHAT THIS IS: the team already uses
 * WhatsApp, and this will not beat WhatsApp at chatting. It was advised against
 * in August for that reason and Joy has asked for it since, so it is built - but
 * built for the one thing WhatsApp genuinely cannot do, which is keep a
 * conversation ABOUT THE BUSINESS inside the business, where it survives
 * somebody leaving and is not mixed in with family messages.
 *
 * ⚠⚠ THERE IS NO REAL-TIME PUSH. This project has no websocket server, no
 * Reverb, no Pusher, and adding one is infrastructure Joy has not asked for. The
 * screen polls. That is an honest limitation and it is written here rather than
 * discovered later: messages arrive within seconds, not instantly, and nobody
 * should design a workflow that depends on the difference.
 *
 * ⚠ A channel is either open to the whole business or has an explicit member
 * list. There are no one-to-one threads as a separate concept: a private
 * conversation is a channel with two members, which is the same machinery and
 * one fewer thing to get wrong.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('topic')->nullable();

            // ⚠ Open channels are readable by anybody who may open chat at all.
            // Private ones are readable only by their members, and the
            // difference is enforced in the query, not on the screen.
            $table->boolean('is_private')->default(false);

            $table->boolean('is_archived')->default(false);
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'name']);
        });

        Schema::create('chat_channel_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // ⚠⚠ How far this person has read, so an unread count means
            // something. Storing a COUNT instead would be wrong the moment two
            // devices read the same channel.
            $table->foreignId('last_read_message_id')->nullable();

            $table->timestamps();

            $table->unique(['chat_channel_id', 'user_id']);
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chat_channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->text('body');

            // ⚠ Edited and deleted are recorded, never destructive. A message
            // that vanishes without trace turns every disagreement about what
            // was said into somebody's word against somebody else's.
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('deleted_at')->nullable();

            $table->timestamps();

            $table->index(['chat_channel_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_channel_members');
        Schema::dropIfExists('chat_channels');
    }
};
