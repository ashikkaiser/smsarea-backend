<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_phone_prices', function (Blueprint $table): void {
            $table->unsignedBigInteger('device_slot_price_minor')->nullable()->after('duration_days');
            $table->unsignedBigInteger('esim_price_minor')->nullable()->after('device_slot_price_minor');
        });
    }

    public function down(): void
    {
        Schema::table('user_phone_prices', function (Blueprint $table): void {
            $table->dropColumn(['device_slot_price_minor', 'esim_price_minor']);
        });
    }
};
