<?php

namespace App\Filament\Pages;

use App\Services\Reporting\InventoryAccountingReportService;
use App\Services\Menu\MenuLabels;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use UnitEnum;

class InventoryAccounting extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBarSquare;

    protected static string|UnitEnum|null $navigationGroup = 'Akuntansi';

    protected static ?int $navigationSort = 9;

    protected static ?string $navigationLabel = 'Selisih Biaya & Stok';

    protected static ?string $title = 'Selisih Biaya & Stok';

    protected string $view = 'filament.pages.inventory-accounting';

    public string $month = '';

    public string $startDate = '';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return MenuLabels::group('Akuntansi');
    }

    public static function canAccess(): bool
    {
        return Gate::allows('view-management-reports');
    }

    public function mount(): void
    {
        Gate::authorize('view-management-reports');
        $this->month = today()->format('Y-m');
        $this->startDate = today()->toDateString();
    }

    public function report(): array
    {
        Gate::authorize('view-management-reports');
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month)
            || ! Carbon::hasFormat($this->startDate, 'Y-m-d')) {
            throw ValidationException::withMessages(['date' => 'Tanggal laporan tidak valid.']);
        }
        $service = app(InventoryAccountingReportService::class);

        return ['costs' => $service->costVariance(Carbon::parse($this->month.'-01')),
            'gap' => $service->inventoryGap(Carbon::parse($this->startDate))];
    }
}
