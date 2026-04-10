<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        $tables = [
            'messages',
            'conversation_participants',
            'conversation_user',
            'conversations',
            'conversation_requests',
            'blocked_users',
            'friend_requests'
        ];

        foreach ($tables as $table) {
            Schema::dropIfExists($table);
        }

        // Friend Requests (Polymorphic)
        Schema::create('friend_requests', function (Blueprint $table) {
            $table->id();
            $table->morphs('sender');
            $table->morphs('receiver');
            $table->enum('status', ['pending', 'accepted', 'rejected', 'cancelled'])->default('pending');
            $table->timestamps();
        });

        // Blocked Entities (Polymorphic)
        Schema::create('blocked_users', function (Blueprint $table) {
            $table->id();
            $table->morphs('blocker');
            $table->morphs('blocked');
            $table->timestamps();
        });

        // Conversations
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->nullable()->constrained()->onDelete('set null');
            $table->unsignedBigInteger('last_message_id')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
        });

        // Conversation Participants (Polymorphic)
        Schema::create('conversation_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->onDelete('cascade');
            $table->morphs('participant');
            $table->integer('unread_count')->default(0);
            $table->timestamp('last_read_at')->nullable();
            $table->timestamps();
        });

        // Messages (Polymorphic)
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->onDelete('cascade');
            $table->morphs('sender');
            $table->text('body')->nullable();
            $table->enum('type', ['text', 'image', 'video', 'audio', 'document'])->default('text');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        // Cross-reference for conversations
        Schema::table('conversations', function (Blueprint $table) {
            $table->foreign('last_message_id')->references('id')->on('messages')->onDelete('set null');
        });

        Schema::enableForeignKeyConstraints();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversation_participants');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('blocked_users');
        Schema::dropIfExists('friend_requests');
        Schema::enableForeignKeyConstraints();
    }
};
