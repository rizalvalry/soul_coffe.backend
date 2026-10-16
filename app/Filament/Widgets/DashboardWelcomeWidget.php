<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Reports;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

class DashboardWelcomeWidget extends Widget
{
    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.dashboard-welcome';

    public function dateLabel(): string
    {
        return now()->locale('id')->translatedFormat('l, d F Y');
    }

    public function greeting(): string
    {
        $hour = now()->hour;
        $time = match (true) {
            $hour < 11 => 'pagi',
            $hour < 15 => 'siang',
            $hour < 18 => 'sore',
            default => 'malam',
        };

        return 'Selamat '.$time.', '.Auth::user()->getFilamentName();
    }

    public function canExport(): bool
    {
        return Reports::canAccess();
    }
}
