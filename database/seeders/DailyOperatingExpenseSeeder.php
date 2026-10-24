<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DailyOperatingExpenseSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['6-2400', 'Beban Bahan Pendukung'],
            ['6-2500', 'Beban Transportasi'],
            ['6-2600', 'Beban Parkir'],
        ] as [$code, $name]) {
            DB::table('accounts')->insertOrIgnore([
                'code' => $code, 'name' => $name, 'type' => 'EXPENSE',
                'normal_balance' => 'DEBIT', 'is_active' => true, 'is_system' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $accounts = DB::table('accounts')->whereIn('code', ['6-2400', '6-1500', '6-2500', '6-2600'])->pluck('id', 'code');
        foreach ([
            ['Es batu Rider', 'pack', 17000, '6-2400'],
            ['Air isi ulang', 'galon', 7000, '6-1500'],
            ['Es batu Blok M', 'pack', 22000, '6-2400'],
            ['Grab', null, null, '6-2500'],
            ['Parkir', null, null, '6-2600'],
            ['Bensin Runner', 'hari', 20000, '6-2500'],
        ] as [$name, $unit, $price, $code]) {
            if (DB::table('cash_categories')->where('name', $name)->exists()) {
                continue;
            }
            DB::table('cash_categories')->insert([
                'name' => $name, 'direction' => 'OUT', 'account_id' => $accounts[$code],
                'default_unit_price_minor' => $price, 'unit' => $unit, 'is_daily' => true,
                'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
