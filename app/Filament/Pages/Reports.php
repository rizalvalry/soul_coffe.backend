<?php

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Exports\AttendanceExport;
use App\Exports\RefillRequestsExport;
use App\Exports\RevenueExport;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use UnitEnum;

/**
 * Excel exports for the numbers OperationsOverviewWidget and the trend charts summarise.
 *
 * Every export shares one date-range form rather than each opening its own modal, because an
 * owner pulling a monthly report almost always wants the same window across all three — refill
 * volume, revenue, and attendance read together, not three separately-configured downloads.
 *
 * The heavy lifting (which rows, in what order) lives in `app/Exports/*`, not here, so a change
 * to a report's columns never touches this page at all.
 */
class Reports extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Laporan';

    protected static ?string $navigationLabel = 'Laporan & Ekspor';

    protected static ?string $title = 'Laporan & Ekspor Excel';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        return Auth::user()?->role === Role::ADMINISTRATOR;
    }

    public function mount(): void
    {
        // 30 hari terakhir termasuk hari ini — cakupan yang paling sering diminta owner untuk
        // rekap bulanan, tanpa membuat siapa pun mengetik tanggal untuk kasus paling umum.
        $this->form->fill([
            'from' => Carbon::today()->subDays(29)->toDateString(),
            'to' => Carbon::today()->toDateString(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(2)->schema([
                    DatePicker::make('from')
                        ->label('Dari Tanggal')
                        ->required()
                        ->native(false),
                    DatePicker::make('to')
                        ->label('Sampai Tanggal')
                        ->required()
                        ->native(false)
                        ->afterOrEqual('from'),
                ]),
            ])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('form')
                    ->footer([
                        Actions::make([
                            Action::make('exportRefills')
                                ->label('Ekspor Refill Requests')
                                ->icon('heroicon-o-arrow-down-tray')
                                ->color('primary')
                                ->action('exportRefills'),
                            Action::make('exportRevenue')
                                ->label('Ekspor Pendapatan')
                                ->icon('heroicon-o-arrow-down-tray')
                                ->color('success')
                                ->action('exportRevenue'),
                            Action::make('exportAttendance')
                                ->label('Ekspor Kehadiran')
                                ->icon('heroicon-o-arrow-down-tray')
                                ->color('gray')
                                ->action('exportAttendance'),
                        ]),
                    ]),
            ]);
    }

    public function exportRefills(): BinaryFileResponse
    {
        [$from, $to] = $this->range();

        return Excel::download(new RefillRequestsExport($from, $to), $this->filename('refill-requests', $from, $to));
    }

    public function exportRevenue(): BinaryFileResponse
    {
        [$from, $to] = $this->range();

        return Excel::download(new RevenueExport($from, $to), $this->filename('pendapatan', $from, $to));
    }

    public function exportAttendance(): BinaryFileResponse
    {
        [$from, $to] = $this->range();

        return Excel::download(new AttendanceExport($from, $to), $this->filename('kehadiran', $from, $to));
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     *
     * `getState()` validates the schema as part of resolving it — including `to`'s
     * `afterOrEqual('from')` rule — so a reversed range never reaches an export as a silent,
     * confusingly empty file; it surfaces as the same inline field error a submit would show.
     */
    private function range(): array
    {
        $data = $this->form->getState();

        return [
            Carbon::parse($data['from'])->startOfDay(),
            Carbon::parse($data['to'])->endOfDay(),
        ];
    }

    private function filename(string $prefix, Carbon $from, Carbon $to): string
    {
        return sprintf('%s_%s_%s.xlsx', $prefix, $from->toDateString(), $to->toDateString());
    }
}
