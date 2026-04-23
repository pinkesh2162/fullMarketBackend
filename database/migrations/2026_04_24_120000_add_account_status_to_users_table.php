<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('account_status', 20)
                ->default('active')
                ->after('email');
            $table->index('account_status');
            $table->string('role', 32)->default('user')->after('account_status');
            $table->index('role');
            $table->string('registered_from', 16)->default('web')->after('role');
            $table->index('registered_from');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['account_status']);
            $table->dropColumn('account_status');
            $table->dropIndex(['role']);
            $table->dropColumn('role');
            $table->dropIndex(['registered_from']);
            $table->dropColumn('registered_from');
        });
    }
};
