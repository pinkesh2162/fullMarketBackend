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
        Schema::table('messages', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::create('message_participant_hides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->onDelete('cascade');
            $table->morphs('participant');
            $table->timestamps();

            $table->unique(['message_id', 'participant_id', 'participant_type'], 'message_hide_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('message_participant_hides');

        Schema::table('messages', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
