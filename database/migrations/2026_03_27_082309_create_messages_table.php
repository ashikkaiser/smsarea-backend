<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('phone_number_id')->constrained()->cascadeOnDelete();
            $table->string('direction');
            $table->string('message_type')->default('sms');
            $table->longText('body')->nullable();
            $table->json('attachments')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->unsignedBigInteger('device_message_id')->nullable();
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('sim_slot')->nullable();
            $table->string('status')->default('received');
            $table->timestamp('occurred_at');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['device_id', 'device_message_id', 'direction']);
            $table->index(['conversation_id', 'occurred_at']);
            $table->index(['phone_number_id', 'occurred_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
