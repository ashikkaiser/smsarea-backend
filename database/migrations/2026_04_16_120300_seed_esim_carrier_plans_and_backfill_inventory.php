<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $settings = DB::table('billing_settings')->orderBy('id')->first();
        $minor = $settings ? (int) ($settings->esim_price_minor ?? 3500) : 3500;
        $duration = $settings ? (int) ($settings->default_duration_days ?? 30) : 30;

        $now = now();
        $carriers = [
            ['slug' => 'tmobile', 'name' => 'T-Mobile', 'sort_order' => 0],
            ['slug' => 'att', 'name' => 'AT&T', 'sort_order' => 1],
            ['slug' => 'verizon', 'name' => 'Verizon', 'sort_order' => 2],
        ];

        $firstId = null;
        foreach ($carriers as $c) {
            $id = DB::table('esim_carrier_plans')->insertGetId([
                'slug' => $c['slug'],
                'name' => $c['name'],
                'price_minor' => $minor,
                'duration_days' => $duration,
                'sort_order' => $c['sort_order'],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $firstId ??= $id;
        }

        if ($firstId !== null) {
            DB::table('esim_inventories')->whereNull('esim_carrier_plan_id')->update([
                'esim_carrier_plan_id' => $firstId,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('esim_inventories')->update(['esim_carrier_plan_id' => null]);
        DB::table('esim_carrier_plans')->truncate();
    }
};
