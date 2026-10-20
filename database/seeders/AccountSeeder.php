<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Public, system-only opening chart; no person or company balance is seeded. */
class AccountSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            ['1-1100', 'Kas Kecil Kantor', 'ASSET'],
            ['1-1200', 'Kas Setoran', 'ASSET'],
            ['1-1300', 'Bank', 'ASSET'],
            ['1-1400', 'QRIS/Transfer Diterima (Kliring)', 'ASSET'],
            ['1-1500', 'Piutang Karyawan (Kasbon)', 'ASSET'],
            ['1-1600', 'Persediaan', 'ASSET'],
            ['1-1700', 'Persediaan Bahan Baku', 'ASSET'],
            ['2-1400', 'Hutang Supplier', 'LIABILITY'],
            ['2-1100', 'Utang Gaji', 'LIABILITY'],
            ['2-1200', 'Utang BPJS', 'LIABILITY'],
            ['2-1300', 'Utang PPh 21', 'LIABILITY'],
            ['3-1100', 'Modal Pemilik', 'EQUITY'],
            ['3-1200', 'Saldo Awal Ekuitas', 'EQUITY'],
            ['4-1100', 'Pendapatan Penjualan Coffee Bike', 'REVENUE'],
            ['4-1200', 'Pendapatan Penjualan Langsung', 'REVENUE'],
            ['4-1300', 'Pendapatan Lain-lain', 'REVENUE'],
            ['5-1100', 'HPP', 'COGS'],
            ['6-1100', 'Selisih Kas Setoran (Lebih/Kurang)', 'EXPENSE'],
            ['6-1200', 'Beban Gaji', 'EXPENSE'],
            ['6-1300', 'Beban BPJS Perusahaan', 'EXPENSE'],
            ['6-1400', 'Beban Listrik', 'EXPENSE'],
            ['6-1500', 'Beban Air/Galon', 'EXPENSE'],
            ['6-1600', 'Beban Keperluan Kantor', 'EXPENSE'],
            ['6-1700', 'Beban Fasilitas Karyawan', 'EXPENSE'],
            ['6-1800', 'Beban Lain-lain', 'EXPENSE'],
            ['6-1990', 'Biaya Admin/MDR', 'EXPENSE'],
        ];

        foreach ($accounts as [$code, $name, $type]) {
            DB::table('accounts')->insertOrIgnore([
                'code' => $code,
                'name' => $name,
                'type' => $type,
                'normal_balance' => in_array($type, ['ASSET', 'COGS', 'EXPENSE'], true) ? 'DEBIT' : 'CREDIT',
                'is_active' => true,
                'is_system' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
