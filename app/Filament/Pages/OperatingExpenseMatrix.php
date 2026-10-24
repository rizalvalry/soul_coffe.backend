<?php

namespace App\Filament\Pages;

use App\Enums\PanelModule;
use App\Exports\MonthlyOperatingExpenseExport;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use App\Services\Reporting\MonthlyOperatingExpenseService;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class OperatingExpenseMatrix extends Page
{
    protected static ?string $navigationLabel = 'Rekap Biaya Harian';

    protected static ?string $title = 'Rekap Biaya Harian';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.operating-expense-matrix';

    public string $month = '';

    public string $employeeId = '';

    public static function canAccess(): bool
    {
        return PermissionMatrix::can(Auth::user(), PanelModule::CASH, 'view');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->month = today()->format('Y-m');
    }

    public function matrix(): array
    {
        abort_unless(static::canAccess(), 403);

        return app(MonthlyOperatingExpenseService::class)->matrix(
            $this->month, $this->employeeId === '' ? null : (int) $this->employeeId,
        );
    }

    public function employees(): array
    {
        abort_unless(static::canAccess(), 403);

        return User::query()->whereHas('employeeProfile')->orderBy('name')->pluck('name', 'id')->all();
    }

    public function exportExcel(): BinaryFileResponse
    {
        $matrix = $this->matrix();

        return Excel::download(new MonthlyOperatingExpenseExport($matrix), "biaya-harian-{$this->month}.xlsx");
    }
}
