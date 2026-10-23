<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SupportMaterialsSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['ES-BATU', 'Es Batu', 'pack'],
            ['PLASTIK', 'Plastik', 'pcs'],
            ['SEDOTAN', 'Sedotan', 'pcs'],
        ] as [$code, $name, $unit]) {
            if (DB::table('raw_materials')->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists()) {
                continue;
            }
            DB::table('raw_materials')->insertOrIgnore([
                'code' => $code, 'name' => $name, 'unit' => $unit,
                'is_active' => true, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $name = 'Bahan pendukung (es batu, plastik, sedotan)';
        if (! DB::table('cash_categories')->where('name', $name)->exists()) {
            $accountId = DB::table('accounts')->where('code', '6-1800')->value('id');
            DB::table('cash_categories')->insert([
                'name' => $name, 'direction' => 'OUT', 'account_id' => $accountId,
                'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
