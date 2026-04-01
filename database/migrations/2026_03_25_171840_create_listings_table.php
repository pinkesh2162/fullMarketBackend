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
        Schema::create('listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('store_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('service_type')->nullable();
            $table->string('title')->nullable();
            $table->unsignedInteger('views_count')->default(0);
            $table->foreignId('service_category')
                ->nullable()->constrained('categories')->onDelete('set null');
            $table->string('service_modality')->nullable();
            $table->text('description')->nullable();
            $table->text('search_keyword')->nullable();
            $table->json('contact_info')->nullable();
            $table->json('additional_info')->nullable();

            $table->string('currency')->nullable();
            $table->string('price')->nullable();
            $table->boolean('availability')->nullable();
            $table->string('condition')->nullable();

            $table->boolean('listing_type')->nullable();
            $table->string('property_type')->nullable();
            $table->string('bedrooms')->nullable();
            $table->string('bathrooms')->nullable();
            $table->json('advance_options')->nullable();

            $table->string('vehicle_type')->nullable();
            $table->json('vehical_info')->nullable();
            $table->string('fual_type')->nullable();
            $table->string('transmission')->nullable();
            $table->softDeletes();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('listings');
    }
};
