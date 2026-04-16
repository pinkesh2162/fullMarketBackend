<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FCM registration tokens can exceed 255 characters in edge cases; VARCHAR(255) truncates
     * and breaks delivery for "send to all" while the same full token works in Postman.
     */
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE users MODIFY fcm_token TEXT NULL');
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE users MODIFY fcm_token VARCHAR(255) NULL');
    }
};
