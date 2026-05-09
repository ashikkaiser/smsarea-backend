<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('esim_carrier_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 32)->unique();
            $table->string('name', 128);
            $table->unsignedBigInteger('price_minor');
            $table->unsignedInteger('duration_days')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('esim_carrier_plans');
    }
};
