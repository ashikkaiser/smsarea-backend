<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_device_entitlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('slots_purchased')->default(0);
            $table->unsignedInteger('slots_used')->default(0);
            $table->string('status', 32)->default('active');
            $table->timestamp('valid_until')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique('user_id');
            $table->index(['status', 'valid_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_device_entitlements');
    }
};
