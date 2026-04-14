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

    private function backfillKeys(string $table): void
    {
        $ids = DB::table($table)->whereNull('unique_key')->pluck('id');
        foreach ($ids as $id) {
            DB::table($table)->where('id', $id)->update([
                'unique_key' => $this->generateUniqueKey($table),
            ]);
        }
    }

    private function generateUniqueKey(string $table): string
    {
        do {
            $code = (string) random_int(1000000, 9999999);
        } while (DB::table($table)->where('unique_key', $code)->exists());

        return $code;
    }
};
