<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Align blocked_users.blocker_type / blocked_type with Relation::morphMap keys (`user`, `store`).
     * Older rows stored concrete class names, so hasBlocked() / isBlockedBy() never matched.
     */
    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('blocked_users')) {
            return;
        }

        $pairs = [
            'App\\Models\\User' => 'user',
            'App\\Models\\Store' => 'store',
        ];

        foreach (['blocked_type', 'blocker_type'] as $column) {
            foreach ($pairs as $class => $alias) {
                DB::table('blocked_users')->where($column, $class)->update([$column => $alias]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('blocked_users')) {
            return;
        }

        $pairs = [
            'user' => 'App\\Models\\User',
            'store' => 'App\\Models\\Store',
        ];

        foreach (['blocked_type', 'blocker_type'] as $column) {
            foreach ($pairs as $alias => $class) {
                DB::table('blocked_users')->where($column, $alias)->update([$column => $class]);
            }
        }
    }
};
