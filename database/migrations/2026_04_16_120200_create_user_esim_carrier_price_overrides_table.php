<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_esim_carrier_price_overrides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('esim_carrier_plan_id')->constrained('esim_carrier_plans')->cascadeOnDelete();
            $table->unsignedBigInteger('price_minor');
            $table->timestamps();

            $table->unique(['user_id', 'esim_carrier_plan_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_esim_carrier_price_overrides');
    }
};
