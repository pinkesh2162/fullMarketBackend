<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_notification_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 32)->unique();
            $table->string('campaign_id', 128)->unique();
            $table->string('title', 120);
            $table->text('body');
            $table->string('selected_segment', 64);
            $table->string('segment_label', 120)->nullable();
            $table->string('country_filter', 120)->nullable();
            $table->string('city_filter', 120)->nullable();
            $table->unsignedInteger('targeted_users')->default(0);
            $table->unsignedInteger('reachable_devices')->default(0);
            $table->unsignedInteger('skipped_no_token')->default(0);
            $table->string('status', 32);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->decimal('open_rate', 5, 2)->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_email', 255)->nullable();
            $table->boolean('dry_run')->default(false);
            $table->string('source', 32)->default('admin_panel');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_notification_campaigns');
    }
};
