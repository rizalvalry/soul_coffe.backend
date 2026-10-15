<?php

namespace App\Console\Commands;

use App\Services\RosterImportService;
use Illuminate\Console\Command;
use Throwable;

class ImportRoster extends Command
{
    protected $signature = 'soul:import-roster {path : CSV roster privat} {--dry-run : Pratinjau tanpa perubahan data}';

    protected $description = 'Import employee roster into inactive accounts and payroll profiles';

    public function handle(RosterImportService $importer): int
    {
        try {
            $result = $importer->import($this->argument('path'), (bool) $this->option('dry-run'));
        } catch (Throwable $exception) {
            // Database exceptions may include bound employee names; never echo them to the terminal.
            $safe = [
                'CSV tidak dapat dibaca.',
                'CSV tidak dapat dibuka.',
                'CSV kosong.',
                'CSV roster harus berada di luar repo backend.',
                'Header CSV wajib berisi divisi,jabatan,nama,kode_cart,role_sistem.',
                'Impor dibatalkan karena data berubah sejak pratinjau.',
            ];
            $this->error(in_array($exception->getMessage(), $safe, true)
                ? $exception->getMessage()
                : 'Impor gagal dan transaksi dibatalkan. Periksa database secara aman.');

            return self::FAILURE;
        }

        $this->table(['Baris', 'Status', 'Keterangan'], array_map(
            fn (array $row): array => [$row['line'], $row['status'], $row['reason']],
            $result['rows'],
        ));
        $this->info(sprintf(
            '%s: dibuat %d, diperbarui %d, dilewati %d, masalah %d.',
            $this->option('dry-run') ? 'Pratinjau' : ($result['aborted'] ? 'Impor dibatalkan (rencana)' : 'Impor'),
            $result['created'],
            $result['updated'],
            $result['skipped'],
            $result['issues'],
        ));

        return $result['issues'] === 0 && ! $result['aborted'] ? self::SUCCESS : self::FAILURE;
    }
}
