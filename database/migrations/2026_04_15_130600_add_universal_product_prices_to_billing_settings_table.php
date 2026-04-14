<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_settings', function (Blueprint $table): void {
            $table->unsignedBigInteger('device_slot_price_minor')
                ->default(1000)
                ->after('default_price_minor');
            $table->unsignedBigInteger('esim_price_minor')
                ->default(1500)
                ->after('device_slot_price_minor');
        });
    }

    public function down(): void
    {
        Schema::table('billing_settings', function (Blueprint $table): void {
            $table->dropColumn(['device_slot_price_minor', 'esim_price_minor']);
        });
    }
};
