<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_builder_limits', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('max_reply_steps')->default(10);
            $table->unsignedTinyInteger('max_followup_steps')->default(10);
            $table->timestamps();
        });

        DB::table('campaign_builder_limits')->insert([
            'max_reply_steps' => 10,
            'max_followup_steps' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_builder_limits');
    }
};
