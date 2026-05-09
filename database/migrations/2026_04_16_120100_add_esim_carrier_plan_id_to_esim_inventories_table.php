<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('esim_inventories', function (Blueprint $table): void {
            $table->foreignId('esim_carrier_plan_id')
                ->nullable()
                ->after('id')
                ->constrained('esim_carrier_plans')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('esim_inventories', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('esim_carrier_plan_id');
        });
    }
};
