<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PayrollConfigurationSeeder extends Seeder
{
    public function run(): void
    {
        $date = '2026-01-01';
        $note = 'Nilai awal sistem; wajib diverifikasi akuntan sebelum payroll pertama.';
        DB::table('rider_incentive_rules')->insertOrIgnore([
            'effective_from' => $date,
            'amount_per_cup_minor' => 0, 'target_cups' => 0, 'bonus_minor' => 0,
            'source_note' => $note, 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ([
            ['MEAL', 'Uang Makan', 'EARNING', true],
            ['TRANSPORT', 'Transport', 'EARNING', true],
            ['ADVANCE', 'Kasbon', 'DEDUCTION', false],
            ['OTHER_DEDUCTION', 'Potongan Lain', 'DEDUCTION', false],
            ['OTHER_COST', 'Biaya Lain-lain', 'EARNING', false],
        ] as [$code, $name, $kind, $taxable]) {
            DB::table('payroll_component_types')->insertOrIgnore([
                'code' => $code, 'effective_from' => $date,
                'name' => $name, 'kind' => $kind, 'taxable' => $taxable,
                'source_note' => $note, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // No BPJS/PTKP/TER numerical rates are seeded without verified official tables.
        // PMK 168/2023 and PP 58/2023 must be checked by the client's accountant.
    }
}
