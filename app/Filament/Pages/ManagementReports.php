<?php

namespace App\Filament\Pages;

use App\Exports\ManagementReportExport;
use App\Services\Reporting\ManagementReportService;
use BackedEnum;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ManagementReports extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Laporan Management';

    protected static ?string $title = 'Laporan Management';

    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.pages.management-reports';

    public string $period = 'daily';

    public string $anchorDate = '';

    public string $fromDate = '';

    public string $toDate = '';

    public string $reportFrom = '';

    public string $reportTo = '';

    public static function canAccess(): bool
    {
        return Gate::allows('view-management-reports');
    }

    public function mount(): void
    {
        Gate::authorize('view-management-reports');
        $this->anchorDate = today()->toDateString();
        $this->fromDate = $this->anchorDate;
        $this->toDate = $this->anchorDate;
        $this->reportFrom = $this->anchorDate;
        $this->reportTo = $this->anchorDate;
    }

    public function rangeForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Pilih periode laporan')->schema([
                Select::make('period')->label('Periode')->options([
                    'daily' => 'Harian', 'weekly' => 'Mingguan (Senin–Minggu)',
                    'monthly' => 'Bulanan', 'custom' => 'Rentang bebas',
                ])->live()->required(),
                DatePicker::make('anchorDate')->label('Tanggal acuan')
                    ->visible(fn (): bool => $this->period !== 'custom')->required(),
                DatePicker::make('fromDate')->label('Dari')
                    ->visible(fn (): bool => $this->period === 'custom')->required(),
                DatePicker::make('toDate')->label('Sampai')
                    ->visible(fn (): bool => $this->period === 'custom')->required(),
            ])->columns(['default' => 1, 'md' => 2]),
        ]);
    }

    public function applyRange(): void
    {
        Gate::authorize('view-management-reports');
        $data = $this->validate([
            'period' => ['required', 'in:daily,weekly,monthly,custom'],
            'anchorDate' => ['required', 'date_format:Y-m-d'],
            'fromDate' => ['required', 'date_format:Y-m-d'],
            'toDate' => ['required', 'date_format:Y-m-d'],
        ]);
        [$from, $to] = $this->dates($data);
        app(ManagementReportService::class)->validateRange($from, $to);
        $this->fromDate = $from->toDateString();
        $this->toDate = $to->toDateString();
        $this->reportFrom = $this->fromDate;
        $this->reportTo = $this->toDate;
    }

    public function report(): array
    {
        Gate::authorize('view-management-reports');

        return app(ManagementReportService::class)->build(...$this->range());
    }

    public function exportExcel(string $section = 'all'): BinaryFileResponse
    {
        Gate::authorize('view-management-reports');
        $report = $this->report();
        $sections = $this->selectedSections($report, $section);

        return Excel::download(new ManagementReportExport($sections), $this->filename($section, $report, 'xlsx'));
    }

    public function exportPdf(string $section = 'all'): StreamedResponse
    {
        Gate::authorize('view-management-reports');
        $report = $this->report();
        $sections = $this->selectedSections($report, $section);

        $pdf = Pdf::loadView('reports.management-pdf', ['report' => $report, 'sections' => $sections])
            ->setPaper('a4', 'landscape');

        return response()->streamDownload(fn () => print ($pdf->output()),
            $this->filename($section, $report, 'pdf'), ['Content-Type' => 'application/pdf']);
    }

    private function range(): array
    {
        foreach (['reportFrom', 'reportTo'] as $field) {
            $value = $this->{$field};
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)
                || ! Carbon::hasFormat($value, 'Y-m-d')) {
                throw ValidationException::withMessages([$field => 'Isi tanggal yang valid, ya.']);
            }
        }
        $from = Carbon::parse($this->reportFrom)->startOfDay();
        $to = Carbon::parse($this->reportTo)->startOfDay();
        app(ManagementReportService::class)->validateRange($from, $to);

        return [$from, $to];
    }

    private function dates(array $data): array
    {
        if (! in_array($data['period'], ['daily', 'weekly', 'monthly', 'custom'], true)) {
            throw ValidationException::withMessages(['period' => 'Pilih jenis periode yang tersedia, ya.']);
        }
        foreach (['anchorDate', 'fromDate', 'toDate'] as $field) {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $data[$field])
                || ! Carbon::hasFormat($data[$field], 'Y-m-d')) {
                throw ValidationException::withMessages([$field => 'Isi tanggal yang valid, ya.']);
            }
        }
        $anchor = Carbon::parse($data['anchorDate'])->startOfDay();

        return match ($data['period']) {
            'daily' => [$anchor, $anchor->copy()],
            'weekly' => [$anchor->copy()->startOfWeek(Carbon::MONDAY), $anchor->copy()->endOfWeek(Carbon::SUNDAY)->startOfDay()],
            'monthly' => [$anchor->copy()->startOfMonth(), $anchor->copy()->endOfMonth()->startOfDay()],
            default => [Carbon::parse($data['fromDate'])->startOfDay(), Carbon::parse($data['toDate'])->startOfDay()],
        };
    }

    private function selectedSections(array $report, string $section): array
    {
        if ($section === 'all') {
            return $report['sections'];
        }
        if (! array_key_exists($section, $report['sections'])) {
            throw ValidationException::withMessages(['section' => 'Laporan ini belum tersedia, ya.']);
        }

        return [$section => $report['sections'][$section]];
    }

    private function filename(string $section, array $report, string $extension): string
    {
        return "laporan-management-{$section}-{$report['from']}-{$report['to']}.{$extension}";
    }
}
