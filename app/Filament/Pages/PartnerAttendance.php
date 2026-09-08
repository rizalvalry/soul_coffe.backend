<?php

namespace App\Filament\Pages;

use App\Enums\PanelModule;
use App\Enums\PartnerAttendanceCode;
use App\Enums\Role;
use App\Exports\PartnerAttendanceExport;
use App\Models\Partner;
use App\Services\Access\PermissionMatrix;
use App\Services\PartnerAttendanceService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use UnitEnum;

/**
 * "Laporan Absensi Partner Soul Coffeemate" — the monthly grid from
 * docs/screenshots/bisnisproses/excel-absensi.jpeg, editable in place.
 *
 * Built as a custom page rather than a Filament resource table because the columns ARE the days
 * of the selected month: 28 to 31 of them, changing with the month. A resource table has a fixed
 * column list, so reproducing this shape there would mean 31 conditionally-hidden columns and a
 * different bug every February.
 *
 * Editing is one click per cell and saves immediately — no separate Save button. That is what the
 * spreadsheet it replaces does, and an admin filling in a whole month wants the same rhythm.
 * Every write re-checks the matrix, so a role granted only "Lihat" gets a read-only grid rather
 * than a grid whose writes fail silently.
 */
class PartnerAttendance extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static string|UnitEnum|null $navigationGroup = 'Absensi';

    protected static ?string $navigationLabel = 'Laporan Absensi';

    protected static ?string $title = 'Laporan Absensi Partner Soul Coffeemate';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.partner-attendance';

    /** Selected month, as `Y-m` — a month input's native value. */
    public string $month = '';

    /** Role heading to show, mirroring the sheet's own "RIDER" banner. Empty = all roles. */
    public string $role = '';

    public static function panelModule(): PanelModule
    {
        return PanelModule::PARTNER_ATTENDANCE;
    }

    public static function canAccess(): bool
    {
        return PermissionMatrix::can(Auth::user(), static::panelModule(), 'view');
    }

    public function mount(): void
    {
        $this->month = Carbon::today()->format('Y-m');
        $this->role = Role::RIDER->value;
    }

    /** Whether the signed-in user may change cells, as opposed to only reading them. */
    public function canEditCells(): bool
    {
        return PermissionMatrix::can(Auth::user(), static::panelModule(), 'edit');
    }

    public function selectedMonth(): Carbon
    {
        // A malformed month (hand-edited URL, browser without month-input support) falls back to
        // today rather than throwing a 500 on a report screen.
        return rescue(
            fn () => Carbon::createFromFormat('Y-m', $this->month)->startOfMonth(),
            fn () => Carbon::today()->startOfMonth(),
            report: false,
        );
    }

    public function shiftMonth(int $months): void
    {
        $this->month = $this->selectedMonth()->addMonths($months)->format('Y-m');
    }

    /** @return Collection<int, array<string, mixed>> */
    public function rows(): Collection
    {
        return app(PartnerAttendanceService::class)->monthlySheet(
            $this->selectedMonth(),
            $this->role === '' ? null : Role::from($this->role),
        );
    }

    /** @return array<int, PartnerAttendanceCode> */
    public function codeOptions(): array
    {
        return PartnerAttendanceCode::cases();
    }

    public function setCell(int $partnerId, int $day, string $code): void
    {
        if (! $this->canEditCells()) {
            Notification::make()
                ->danger()
                ->title('Tidak diizinkan')
                ->body('Peran Anda hanya diberi akses melihat laporan absensi.')
                ->send();

            return;
        }

        $partner = Partner::query()->find($partnerId);

        if (! $partner) {
            return;
        }

        $date = $this->selectedMonth()->copy()->setDay($day);

        app(PartnerAttendanceService::class)->setCode(
            $partner,
            $date,
            $code === '' ? null : PartnerAttendanceCode::from($code),
            Auth::user(),
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportExcel')
                ->label('Ekspor Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->action(fn (): BinaryFileResponse => Excel::download(
                    new PartnerAttendanceExport($this->selectedMonth(), $this->role === '' ? null : Role::from($this->role)),
                    sprintf('absensi-partner_%s.xlsx', $this->selectedMonth()->format('Y-m')),
                )),
        ];
    }
}
