<?php

namespace App\Filament\Pages;

use App\Enums\AttendanceCode;
use App\Enums\PanelModule;
use App\Enums\Role;
use App\Exports\AttendanceSheetExport;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use App\Services\AttendanceSheetService;
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
 * "Laporan Absensi" — the monthly sheet from docs/screenshots/bisnisproses/excel-absensi.jpeg,
 * filled in place.
 *
 * Rows are employees straight out of `users`; there is no second roster to maintain. Presence
 * arrives on its own from the mobile absen flow and a hand-entered code overrides it — see
 * AttendanceSheetService for why the two live in different tables.
 *
 * Built as a custom page rather than a Filament resource table because the columns ARE the days
 * of the selected month: 28 to 31 of them, changing with the month. A resource table has a fixed
 * column list, so reproducing this shape there would mean 31 conditionally-hidden columns and a
 * different bug every February.
 */
class AttendanceSheet extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static string|UnitEnum|null $navigationGroup = 'Absensi';

    protected static ?string $navigationLabel = 'Laporan Absensi';

    protected static ?string $title = 'Laporan Absensi';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.attendance-sheet';

    /** Selected month, as `Y-m` — a month input's native value. */
    public string $month = '';

    /** Role heading to show, mirroring the sheet's own "RIDER" banner. Empty = all roles. */
    public string $role = '';

    public static function panelModule(): PanelModule
    {
        return PanelModule::ATTENDANCE;
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
        return app(AttendanceSheetService::class)->monthlySheet(
            $this->selectedMonth(),
            $this->role === '' ? null : Role::from($this->role),
        );
    }

    /** @return array<int, AttendanceCode> */
    public function codeOptions(): array
    {
        return AttendanceCode::cases();
    }

    /** Whether the role on screen can absen from the app at all — see AttendanceService. */
    public function roleClocksIn(): bool
    {
        return in_array($this->role, [Role::BARISTA->value, Role::STAFF->value], true);
    }

    public function setCell(int $userId, int $day, string $code): void
    {
        if (! $this->canEditCells()) {
            Notification::make()
                ->danger()
                ->title('Tidak diizinkan')
                ->body('Peran Anda hanya diberi akses melihat laporan absensi.')
                ->send();

            return;
        }

        $user = User::query()->find($userId);

        if (! $user) {
            return;
        }

        $date = $this->selectedMonth()->copy()->setDay($day);

        app(AttendanceSheetService::class)->setCode(
            $user,
            $date,
            $code === '' ? null : AttendanceCode::from($code),
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
                    new AttendanceSheetExport($this->selectedMonth(), $this->role === '' ? null : Role::from($this->role)),
                    sprintf('absensi_%s.xlsx', $this->selectedMonth()->format('Y-m')),
                )),
        ];
    }
}
