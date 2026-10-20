<?php

namespace App\Filament\Pages;

use App\Exports\ManagementReportExport;
use App\Models\CashAccount;
use App\Models\TaxRemittance;
use App\Models\TaxSettingVersion;
use App\Services\Menu\MenuLabels;
use App\Services\TaxService;
use BackedEnum;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

class TaxSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'Akuntansi';

    protected static ?int $navigationSort = 12;

    protected static ?string $navigationLabel = 'Pengaturan Pajak';

    protected static ?string $title = 'Pengaturan Pajak';

    protected string $view = 'filament.pages.tax-settings';

    public bool $showPbjtForm = false;

    public bool $showPphForm = false;

    public array $pbjtInput = [];

    public array $pphInput = [];

    public array $remitInput = [];

    public string $month = '';

    public string $voidReason = '';

    public ?int $voidRemittanceId = null;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return MenuLabels::group('Akuntansi');
    }

    public static function canAccess(): bool
    {
        return Gate::allows('manage-tax-settings');
    }

    public function mount(): void
    {
        Gate::authorize('manage-tax-settings');
        $this->month = today()->format('Y-m');
        $this->fillSettings();
        $this->resetRemittance();
    }

    public function pbjtForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Ubah tarif PBJT')->schema([
                Toggle::make('is_active')->label('Aktifkan PBJT'),
                TextInput::make('rate_percent')->label('Tarif (%)')->required()->helperText('Contoh: 10 untuk 10%, atau 10.5 untuk 10,5%.'),
                Select::make('price_mode')->label('Harga jual')->options(['INCLUDED' => 'Sudah termasuk pajak'])->required()
                    ->helperText('Harga Rp 11.000 dengan tarif 10% berarti Rp 10.000 penjualan + Rp 1.000 pajak. Mode harga ditambah pajak menyusul saat aplikasi Rider mendukungnya.'),
                DatePicker::make('effective_from')->label('Berlaku mulai')->required(),
                DatePicker::make('effective_until')->label('Berlaku sampai (opsional)'),
                TextInput::make('monthly_threshold_minor')->label('Ambang omzet bulanan (Rp, opsional)')->integer()->minValue(0),
                Textarea::make('legal_note')->label('Catatan dasar hukum')->maxLength(2000)->columnSpanFull(),
                Textarea::make('reason')->label('Alasan perubahan')->required()->minLength(5)->maxLength(2000)->columnSpanFull(),
            ])->columns(['default' => 1, 'md' => 2]),
        ])->statePath('pbjtInput');
    }

    public function pphForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Ubah tarif PPh Badan')->schema([
                Toggle::make('is_active')->label('Aktifkan PPh Badan'),
                Select::make('scheme')->label('Skema')->options([
                    'FINAL' => 'Final atas omzet', 'GENERAL' => 'Tarif umum (perkiraan laba)',
                ])->required(),
                TextInput::make('rate_percent')->label('Tarif (%)')->required(),
                DatePicker::make('effective_from')->label('Berlaku mulai')->required(),
                DatePicker::make('effective_until')->label('Berlaku sampai (opsional)'),
                Textarea::make('legal_note')->label('Catatan dasar hukum')->maxLength(2000)->columnSpanFull(),
                Textarea::make('reason')->label('Alasan perubahan')->required()->minLength(5)->maxLength(2000)->columnSpanFull(),
            ])->columns(['default' => 1, 'md' => 2]),
        ])->statePath('pphInput');
    }

    public function remittanceForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Setor pajak')->schema([
                Select::make('tax_type')->label('Pajak')->options(['PBJT' => 'PBJT', 'PPH' => 'PPh Badan'])->required(),
                DatePicker::make('paid_on')->label('Tanggal setor')->required(),
                TextInput::make('amount_minor')->label('Nominal (Rp)')->integer()->minValue(1)->required(),
                TextInput::make('receipt_number')->label('Nomor bukti setor')->required()->maxLength(255),
                Select::make('cash_account_id')->label('Dari akun kas/bank')->options(fn (): array => CashAccount::query()
                    ->with('account')->where('is_active', true)->get()
                    ->filter(fn (CashAccount $cash): bool => in_array($cash->account?->code, ['1-1100', '1-1200', '1-1300'], true))
                    ->mapWithKeys(fn (CashAccount $cash): array => [$cash->id => $cash->name])->all())->required(),
            ])->columns(['default' => 1, 'md' => 2]),
        ])->statePath('remitInput');
    }

    public function openPbjtForm(): void
    {
        Gate::authorize('manage-tax-settings');
        $this->showPbjtForm = true;
    }

    public function openPphForm(): void
    {
        Gate::authorize('manage-tax-settings');
        $this->showPphForm = true;
    }

    public function savePbjt(): void
    {
        $this->saveVersion('PBJT', $this->pbjtInput);
        $this->showPbjtForm = false;
    }

    public function savePph(): void
    {
        $this->saveVersion('PPH', $this->pphInput);
        $this->showPphForm = false;
    }

    private function saveVersion(string $type, array $input): void
    {
        Gate::authorize('manage-tax-settings');
        $rate = (string) ($input['rate_percent'] ?? '');
        if (preg_match('/\A(?:\d{1,2}|100)(?:\.\d{1,2})?\z/D', $rate) !== 1) {
            throw ValidationException::withMessages(['rate_percent' => 'Isi tarif 0 sampai 100 dengan maksimal 2 angka desimal, ya.']);
        }
        [$whole, $fraction] = array_pad(explode('.', $rate, 2), 2, '');
        $bps = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
        app(TaxService::class)->saveSetting(Auth::user(), [
            'tax_type' => $type, 'is_active' => (bool) ($input['is_active'] ?? false),
            'rate_bps' => $bps, 'price_mode' => $type === 'PBJT' ? ($input['price_mode'] ?? null) : null,
            'scheme' => $type === 'PPH' ? ($input['scheme'] ?? null) : null,
            'effective_from' => $input['effective_from'] ?? null,
            'effective_until' => $input['effective_until'] ?? null,
            'legal_note' => $input['legal_note'] ?? null,
            'monthly_threshold_minor' => $type === 'PBJT' ? ($input['monthly_threshold_minor'] ?? null) : null,
            'reason' => $input['reason'] ?? null,
        ]);
        $this->fillSettings();
        Notification::make()->success()->title('Versi tarif baru tersimpan')->send();
    }

    public function report(string $type): array
    {
        Gate::authorize('manage-tax-settings');

        return app(TaxService::class)->monthlyReport($type, $this->month);
    }

    public function recordFinalPph(): void
    {
        Gate::authorize('manage-tax-settings');
        app(TaxService::class)->postFinalPph(Auth::user(), $this->month);
        Notification::make()->success()->title('Utang PPh Final tercatat')->send();
    }

    public function saveRemittance(): void
    {
        Gate::authorize('manage-tax-settings');
        app(TaxService::class)->remit(Auth::user(), $this->remitInput);
        $this->resetRemittance();
        Notification::make()->success()->title('Setoran pajak tercatat')->send();
    }

    public function voidRemittance(): void
    {
        Gate::authorize('manage-tax-settings');
        $remittance = TaxRemittance::query()->findOrFail($this->voidRemittanceId);
        app(TaxService::class)->voidRemittance(Auth::user(), $remittance, $this->voidReason);
        $this->voidRemittanceId = null;
        $this->voidReason = '';
        Notification::make()->success()->title('Setoran pajak dibatalkan')->send();
    }

    public function versions(string $type): mixed
    {
        Gate::authorize('manage-tax-settings');

        return TaxSettingVersion::query()->with('creator')->where('tax_type', $type)
            ->orderByDesc('effective_from')->get();
    }

    public function remittances(): mixed
    {
        Gate::authorize('manage-tax-settings');

        return TaxRemittance::query()->with('cashAccount')->orderByDesc('id')->limit(25)->get();
    }

    public function exportExcel(string $type): BinaryFileResponse
    {
        Gate::authorize('manage-tax-settings');

        return Excel::download(new ManagementReportExport([$type => $this->reportSection($type)]),
            'laporan-'.strtolower($type).'-'.$this->month.'.xlsx');
    }

    public function exportPdf(string $type): StreamedResponse
    {
        Gate::authorize('manage-tax-settings');
        $section = $this->reportSection($type);
        $pdf = Pdf::loadView('reports.tax-pdf', ['section' => $section, 'month' => $this->month]);

        return response()->streamDownload(fn () => print ($pdf->output()),
            'laporan-'.strtolower($type).'-'.$this->month.'.pdf', ['Content-Type' => 'application/pdf']);
    }

    private function reportSection(string $type): array
    {
        $report = $this->report($type);
        $rows = [
            ['Omzet bruto', $report['gross_minor']],
            [$type === 'PBJT' ? 'Dasar pengenaan' : 'Omzet setelah PBJT', $report['base_minor']],
            ['Utang tercatat dari jurnal', $report['due_minor']],
            ['Sudah disetor bulan ini', $report['paid_minor']],
        ];
        if ($type === 'PBJT' && $report['threshold_minor'] !== null) {
            $rows[] = ['Ambang omzet (informasi)', $report['threshold_minor']];
            $rows[] = ['Selisih dari ambang', $report['gross_minor'] - $report['threshold_minor']];
        }
        if ($type === 'PPH') {
            $rows[] = ['Perkiraan laba skema umum', $report['profit_minor']];
            $rows[] = ['Perkiraan PPh Final', $report['final_estimate_minor']];
            $rows[] = ['Perkiraan PPh umum', $report['general_estimate_minor']];
        }

        return ['title' => $type === 'PBJT' ? 'Pajak Restoran (PBJT)' : 'Pajak Penghasilan Badan',
            'headings' => ['Uraian', 'Nominal (Rp)'], 'rows' => $rows, 'money' => [1]];
    }

    private function fillSettings(): void
    {
        $pbjt = TaxSettingVersion::query()->where('tax_type', 'PBJT')->orderByDesc('effective_from')->first();
        $pph = TaxSettingVersion::query()->where('tax_type', 'PPH')->orderByDesc('effective_from')->first();
        $this->pbjtForm->fill($this->formDefaults($pbjt) + ['price_mode' => 'INCLUDED', 'monthly_threshold_minor' => $pbjt?->monthly_threshold_minor]);
        $this->pphForm->fill($this->formDefaults($pph) + ['scheme' => $pph?->scheme ?? 'FINAL']);
    }

    private function formDefaults(?TaxSettingVersion $setting): array
    {
        $rate = $setting?->rate_bps ?? 0;
        $latestDate = $setting?->effective_from?->toDateString();

        return [
            'is_active' => $setting?->is_active ?? false,
            'rate_percent' => rtrim(rtrim(sprintf('%d.%02d', intdiv($rate, 100), $rate % 100), '0'), '.'),
            'effective_from' => $latestDate && $latestDate >= today()->toDateString()
                ? $setting->effective_from->copy()->addDay()->toDateString() : today()->toDateString(),
            'effective_until' => null, 'legal_note' => $setting?->legal_note,
            'reason' => '',
        ];
    }

    private function resetRemittance(): void
    {
        $this->remittanceForm->fill([
            'tax_type' => 'PBJT', 'paid_on' => today()->toDateString(),
            'amount_minor' => '', 'receipt_number' => '', 'cash_account_id' => '',
        ]);
        $this->remitInput['idempotency_key'] = (string) Str::uuid();
    }
}
