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
        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('maintenance_mode')->default(false);
            $table->string('maintenance_title')->nullable();
            $table->text('maintenance_message')->nullable();
            
            $table->string('min_version_android')->default('1.0.0');
            $table->string('latest_version_android')->default('1.0.0');
            $table->string('android_store_url')->nullable();
            
            $table->string('min_version_ios')->default('1.0.0');
            $table->string('latest_version_ios')->default('1.0.0');
            $table->string('ios_store_url')->nullable();
            
            $table->boolean('force_update_below_min')->default(true);
            $table->text('release_notes')->nullable();
            $table->boolean('enabled_location_filter')->default(false);
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};
