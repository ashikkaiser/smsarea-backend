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
        Schema::create('phone_numbers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            $table->string('imei_or_device_uid')->nullable();
            $table->unsignedTinyInteger('sim_slot')->nullable();
            $table->string('phone_number')->nullable();
            $table->string('carrier_name')->nullable();
            $table->string('country_code', 8)->nullable();
            $table->string('region_code', 16)->nullable();
            $table->string('status')->default('inactive');
            $table->timestamp('purchase_date')->nullable();
            $table->timestamp('expiry_date')->nullable();
            $table->timestamp('last_renewed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['device_id', 'sim_slot']);
            $table->index('phone_number');
            $table->index(['status', 'expiry_date']);
            $table->index('carrier_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('phone_numbers');
    }
};
