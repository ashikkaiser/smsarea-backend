<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('status', 32)->default('awaiting_payment');
            $table->string('source', 32)->default('user_self');
            $table->string('provider', 32)->nullable();
            $table->string('provider_payment_id', 64)->nullable()->index();
            $table->text('provider_pay_address')->nullable();
            $table->string('provider_pay_currency', 32)->nullable();
            $table->decimal('provider_pay_amount', 24, 12)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
