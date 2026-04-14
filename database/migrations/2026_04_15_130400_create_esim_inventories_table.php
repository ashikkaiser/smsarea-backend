<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('esim_inventories', function (Blueprint $table): void {
            $table->id();
            $table->string('iccid', 64)->unique();
            $table->string('phone_number', 32);
            $table->text('qr_code')->nullable();
            $table->string('manual_code', 128)->nullable();
            $table->string('zip_code', 16)->nullable();
            $table->string('area_code', 16)->nullable();
            $table->string('status', 32)->default('available');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['status', 'zip_code']);
            $table->index(['status', 'area_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('esim_inventories');
    }
};
