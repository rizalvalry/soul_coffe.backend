<?php

namespace App\Filament\Pages;

use App\Enums\PanelModule;
use App\Exports\ManagementReportExport;
use App\Filament\Concerns\RenameableModule;
use App\Models\AuditLog;
use App\Models\Cart;
use App\Models\FinanceDailyTarget;
use App\Services\Access\PermissionMatrix;
use App\Services\Reporting\FinanceDailyReportService;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use UnitEnum;

class FinanceDailyReport extends Page
{
    use RenameableModule;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Laporan';

    protected static ?string $navigationLabel = 'Laporan Harian Finance';

    protected static ?string $title = 'Laporan Harian Finance';

    protected static ?int $navigationSort = 15;

    protected string $view = 'filament.pages.finance-daily-report';

    public string $date = '';

    public string $bikeType = '';

    public string $rankingPeriod = 'weekly';

    public string $appliedDate = '';

    public string $appliedBikeType = '';

    public string $appliedRankingPeriod = 'weekly';

    public string $tab = 'per_rider';

    public string $companyCups = '';

    public string $riderCups = '';

    public int $targetRevision = 0;

    public static function panelModule(): PanelModule
    {
        return PanelModule::FINANCE_DAILY_REPORT;
    }

    public static function canAccess(): bool
    {
        return PermissionMatrix::can(Auth::user(), self::panelModule(), 'view');
    }

    public function canManage(): bool
    {
        return PermissionMatrix::can(Auth::user(), self::panelModule(), 'edit');
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
        $this->date = today()->toDateString();
        $this->appliedDate = $this->date;
        $this->loadTargets();
    }

    public function filterForm(Schema $schema): Schema
    {
        return $schema->components([
            DatePicker::make('date')->label('Tanggal')->required(),
            Select::make('bikeType')->label('Jenis Coffee Bike')->placeholder('Semua jenis')
                ->options(array_combine(Cart::BIKE_TYPES, Cart::BIKE_TYPES))->native(false),
            Select::make('rankingPeriod')->label('Peringkat')->options([
                'weekly' => 'Mingguan (Senin–Minggu)', 'monthly' => 'Bulanan',
            ])->required()->native(false),
        ])->columns(['default' => 1, 'md' => 3]);
    }

    public function applyFilters(): void
    {
        abort_unless(self::canAccess(), 403);
        $data = $this->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'bikeType' => ['nullable', Rule::in(Cart::BIKE_TYPES)],
            'rankingPeriod' => ['required', Rule::in(['weekly', 'monthly'])],
        ]);
        if (! Carbon::hasFormat($data['date'], 'Y-m-d')) {
            throw ValidationException::withMessages(['date' => 'Isi tanggal yang valid, ya.']);
        }
        $this->appliedDate = $data['date'];
        $this->appliedBikeType = $data['bikeType'] ?? '';
        $this->appliedRankingPeriod = $data['rankingPeriod'];
        $this->loadTargets();
    }

    public function setTab(string $tab): void
    {
        abort_unless(self::canAccess(), 403);
        abort_unless(in_array($tab, ['per_rider', 'rekap', 'target', 'peringkat'], true), 404);
        $this->tab = $tab;
    }

    public function report(?string $scope = null): array
    {
        abort_unless(self::canAccess(), 403);
        $this->validateAppliedFilters();
        $scope ??= $this->tab;
        abort_unless(in_array($scope, ['all', 'per_rider', 'rekap', 'target', 'target_bulanan',
            'target_rider', 'peringkat', 'porsi_produk', 'total_produk'], true), 404);

        return app(FinanceDailyReportService::class)->build(
            $this->appliedDate, $this->appliedBikeType ?: null, $this->appliedRankingPeriod, $scope,
        );
    }

    public function displayReport(): ?array
    {
        if (! self::canAccess() || ! in_array($this->tab, ['per_rider', 'rekap', 'target', 'peringkat'], true)) {
            return null;
        }
        try {
            return $this->report();
        } catch (ValidationException) {
            return null;
        }
    }

    public function saveTargets(): void
    {
        abort_unless($this->canManage(), 403);
        $this->validateAppliedFilters();
        $data = $this->validate([
            'companyCups' => ['required', 'integer', 'min:0', 'max:1000000'],
            'riderCups' => ['required', 'integer', 'min:0', 'max:1000000'],
        ]);
        DB::transaction(function () use ($data): void {
            $company = (int) $data['companyCups'];
            $rider = (int) $data['riderCups'];
            $created = DB::table('finance_daily_targets')->insertOrIgnore([
                'operating_date' => $this->appliedDate,
                'company_cups' => FinanceDailyReportService::DEFAULT_COMPANY_CUPS,
                'rider_cups' => FinanceDailyReportService::DEFAULT_RIDER_CUPS,
                'revision' => 0, 'updated_by' => Auth::id(),
                'created_at' => now(), 'updated_at' => now(),
            ]) === 1;
            $target = FinanceDailyTarget::query()->where('operating_date', $this->appliedDate)
                ->lockForUpdate()->firstOrFail();
            if (! $created && $target->company_cups === $company && $target->rider_cups === $rider) {
                $this->targetRevision = $target->revision;

                return;
            }
            if ($target->revision !== $this->targetRevision) {
                throw ValidationException::withMessages([
                    'companyCups' => 'Target tanggal ini sudah berubah. Tampilkan ulang dulu sebelum nyimpan lagi, ya.',
                ]);
            }
            $before = $created ? null : $target->only(['company_cups', 'rider_cups']);
            $target->fill([
                'company_cups' => $company,
                'rider_cups' => $rider,
                'revision' => $target->revision + 1,
                'updated_by' => Auth::id(),
            ]);
            $target->save();
            $this->targetRevision = $target->revision;
            AuditLog::recordAction(Auth::user(), 'finance_daily_target.saved', $target, $before, [
                'operating_date' => $this->appliedDate,
                'company_cups' => $target->company_cups,
                'rider_cups' => $target->rider_cups,
            ]);
        });
    }

    public function exportExcel(string $section = 'all'): BinaryFileResponse
    {
        abort_unless(self::canAccess(), 403);
        $report = $this->report($section);
        $sections = $report['sections'];
        if ($section !== 'all') {
            if (! array_key_exists($section, $sections)) {
                throw ValidationException::withMessages(['section' => 'Bagian laporan ini belum ada, ya.']);
            }
            $sections = [$section => $sections[$section]];
        }

        return Excel::download(new ManagementReportExport($sections),
            "laporan-harian-finance-{$section}-{$this->appliedDate}.xlsx");
    }

    private function loadTargets(): void
    {
        $targets = app(FinanceDailyReportService::class)->targetsFor($this->appliedDate);
        $this->companyCups = (string) $targets['company_cups'];
        $this->riderCups = (string) $targets['rider_cups'];
        $this->targetRevision = $targets['revision'];
    }

    private function validateAppliedFilters(): void
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $this->appliedDate)
            || ! Carbon::hasFormat($this->appliedDate, 'Y-m-d')
            || ($this->appliedBikeType !== '' && ! in_array($this->appliedBikeType, Cart::BIKE_TYPES, true))
            || ! in_array($this->appliedRankingPeriod, ['weekly', 'monthly'], true)) {
            throw ValidationException::withMessages(['date' => 'Filter laporan kurang pas. Pilih tanggal dan jenis Coffee Bike lagi, ya.']);
        }
    }
}
