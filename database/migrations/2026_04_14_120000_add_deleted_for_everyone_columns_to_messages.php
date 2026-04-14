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
        if (! Schema::hasTable('messages')) {
            return;
        }

        Schema::table('messages', function (Blueprint $table) {
            $table->timestamp('deleted_for_everyone_at')->nullable()->after('read_at');
            $table->unsignedBigInteger('deleted_for_everyone_by_id')->nullable()->after('deleted_for_everyone_at');
            $table->string('deleted_for_everyone_by_type')->nullable()->after('deleted_for_everyone_by_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('messages')) {
            return;
        }

        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn([
                'deleted_for_everyone_at',
                'deleted_for_everyone_by_id',
                'deleted_for_everyone_by_type',
            ]);
        });
    }
};
