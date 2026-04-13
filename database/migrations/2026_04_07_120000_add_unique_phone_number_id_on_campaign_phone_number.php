<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One phone line may only be linked to a single campaign at a time.
     */
    public function up(): void
    {
        Schema::table('campaign_phone_number', function (Blueprint $table) {
            $table->unique('phone_number_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('campaign_phone_number', function (Blueprint $table) {
            $table->dropUnique(['phone_number_id']);
        });
    }
};
