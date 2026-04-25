<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'platform')) {
                $table->string('platform', 16)->default('unknown')->after('registered_from');
                $table->index('platform');
            }
            if (! Schema::hasColumn('users', 'last_platform_seen_at')) {
                $table->timestamp('last_platform_seen_at')->nullable()->after('platform');
            }
        });

        // Backfill canonical platform from existing known fields.
        DB::table('users')
            ->where('registered_from', 'android')
            ->update(['platform' => 'android']);
        DB::table('users')
            ->where('registered_from', 'ios')
            ->update(['platform' => 'ios']);

        if (DB::getDriverName() === 'mysql') {
            DB::statement("
                UPDATE users
                SET platform = 'android'
                WHERE platform = 'unknown'
                  AND LOWER(COALESCE(
                    JSON_UNQUOTE(JSON_EXTRACT(data, '$.platform')),
                    JSON_UNQUOTE(JSON_EXTRACT(data, '$.device_platform')),
                    JSON_UNQUOTE(JSON_EXTRACT(data, '$.deviceType'))
                  )) LIKE '%android%'
            ");
            DB::statement("
                UPDATE users
                SET platform = 'ios'
                WHERE platform = 'unknown'
                  AND LOWER(COALESCE(
                    JSON_UNQUOTE(JSON_EXTRACT(data, '$.platform')),
                    JSON_UNQUOTE(JSON_EXTRACT(data, '$.device_platform')),
                    JSON_UNQUOTE(JSON_EXTRACT(data, '$.deviceType'))
                  )) IN ('ios', 'iphone')
            ");
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'last_platform_seen_at')) {
                $table->dropColumn('last_platform_seen_at');
            }
            if (Schema::hasColumn('users', 'platform')) {
                $table->dropIndex(['platform']);
                $table->dropColumn('platform');
            }
        });
    }
};
