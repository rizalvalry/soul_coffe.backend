<?php

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Models\AccountingPeriod;
use App\Services\AccountingPeriodService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

class AccountingPeriods extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Laporan';

    protected static ?string $navigationLabel = 'Periode Akuntansi';

    protected static ?string $title = 'Periode Akuntansi';

    protected string $view = 'filament.pages.accounting-periods';

    public int $year;

    public int $month;

    public function mount(): void
    {
        $this->year = (int) now()->year;
        $this->month = (int) now()->month;
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->role === Role::MANAGEMENT;
    }

    public function periods(): mixed
    {
        return AccountingPeriod::query()->orderByDesc('year')->orderByDesc('month')->limit(36)->get();
    }

    public function setStatus(int $year, int $month, string $status): void
    {
        Gate::authorize('manage-accounting-periods');
        app(AccountingPeriodService::class)->setStatus($year, $month, $status, Auth::user());
        Notification::make()->success()->title($status === 'CLOSED' ? 'Periode ditutup' : 'Periode dibuka')->send();
    }

    public function closeSelected(): void
    {
        $this->setStatus($this->year, $this->month, 'CLOSED');
    }
}
