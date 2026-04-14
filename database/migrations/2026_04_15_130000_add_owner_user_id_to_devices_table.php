<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->foreignId('owner_user_id')
                ->nullable()
                ->after('device_token')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('claimed_at')->nullable()->after('owner_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('owner_user_id');
            $table->dropColumn('claimed_at');
        });
    }
};
