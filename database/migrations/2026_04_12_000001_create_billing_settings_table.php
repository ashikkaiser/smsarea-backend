<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('default_price_minor')->default(1000);
            $table->string('currency', 3)->default('USD');
            $table->unsignedInteger('default_duration_days')->default(30);
            $table->boolean('self_checkout_enabled')->default(true);
            $table->text('nowpayments_api_key')->nullable();
            $table->text('nowpayments_ipn_secret')->nullable();
            $table->string('nowpayments_pay_currency', 32)->default('btc');
            $table->boolean('nowpayments_sandbox')->default(true);
            $table->string('checkout_success_path', 255)->default('/numbers?checkout=success');
            $table->string('checkout_cancel_path', 255)->default('/numbers?checkout=cancel');
            $table->timestamps();
        });

        DB::table('billing_settings')->insert([
            'default_price_minor' => 1000,
            'currency' => 'USD',
            'default_duration_days' => 30,
            'self_checkout_enabled' => true,
            'nowpayments_pay_currency' => 'btc',
            'nowpayments_sandbox' => true,
            'checkout_success_path' => '/numbers?checkout=success',
            'checkout_cancel_path' => '/numbers?checkout=cancel',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_settings');
    }
};
