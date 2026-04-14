<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('unique_key', 7)->nullable()->after('id');
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->string('unique_key', 7)->nullable()->after('id');
        });

        // $this->backfillKeys('users');
        // $this->backfillKeys('stores');

        Schema::table('users', function (Blueprint $table) {
            $table->unique('unique_key');
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->unique('unique_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['unique_key']);
            $table->dropColumn('unique_key');
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->dropUnique(['unique_key']);
            $table->dropColumn('unique_key');
        });
    }
};
