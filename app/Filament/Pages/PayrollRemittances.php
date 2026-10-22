<?php

namespace App\Filament\Pages;

use App\Exports\PayrollWithholdingExport;
use App\Models\Account;
use App\Models\PayrollRemittance;
use App\Services\Menu\MenuLabels;
use App\Services\PayrollRemittanceService;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
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
use UnitEnum;

class PayrollRemittances extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'Akuntansi';

    protected static ?string $navigationLabel = 'Setor PPh 21 & BPJS';

    protected static ?int $navigationSort = 19;

    protected string $view = 'filament.pages.payroll-remittances';

    public array $input = [];

    public ?int $voidId = null;

    public string $voidReason = '';

    public static function canAccess(): bool
    {
        return Gate::allows('manage-payroll');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return MenuLabels::group('Akuntansi');
    }

    public function mount(): void
    {
        Gate::authorize('manage-payroll');
        $this->input = [
            'month' => today()->format('Y-m'), 'kind' => 'PPH21', 'program' => null,
            'paid_on' => today()->toDateString(), 'amount_minor' => null,
            'receipt_number' => '', 'account_id' => null,
            'idempotency_key' => (string) Str::uuid(),
        ];
    }

    public function remittanceForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Setor PPh 21 / BPJS')->schema([
                TextInput::make('month')->label('Masa')->type('month')->required(),
                Select::make('kind')->label('Jenis')->options(['PPH21' => 'PPh 21', 'BPJS' => 'BPJS'])->live()->required(),
                Select::make('program')->label('Program BPJS (kosong = gabungan)')->options([
                    'jht' => 'JHT', 'jp' => 'JP', 'jkk' => 'JKK', 'jkm' => 'JKM', 'health' => 'Kesehatan',
                ])->visible(fn (): bool => ($this->input['kind'] ?? null) === 'BPJS'),
                DatePicker::make('paid_on')->label('Tanggal setor')->required(),
                TextInput::make('amount_minor')->label('Nominal (Rp)')->integer()->minValue(1)->required(),
                TextInput::make('receipt_number')->label('NTPN / nomor bukti setor')->required()->maxLength(255),
                Select::make('account_id')->label('Akun kas/bank')->options(fn (): array => Account::query()
                    ->whereIn('code', ['1-1100', '1-1200', '1-1300'])->where('is_active', true)
                    ->orderBy('code')->pluck('name', 'id')->all())->required(),
            ])->columns(['default' => 1, 'md' => 2]),
        ])->statePath('input');
    }

    public function status(): array
    {
        Gate::authorize('manage-payroll');

        return app(PayrollRemittanceService::class)->status(Auth::user(),
            $this->input['month'] ?? today()->format('Y-m'),
            $this->input['kind'] ?? 'PPH21',
            ($this->input['kind'] ?? 'PPH21') === 'BPJS' ? ($this->input['program'] ?? null) : null);
    }

    public function prefill(): void
    {
        Gate::authorize('manage-payroll');
        $status = $this->status();
        if ($status['unallocated_minor'] > 0) {
            throw ValidationException::withMessages(['amount_minor' => 'Setoran gabungan belum punya rincian program. Pilih gabungan untuk lihat sisanya ya.']);
        }
        $this->input['amount_minor'] = max(0, $status['difference_minor']);
    }

    public function save(): void
    {
        Gate::authorize('manage-payroll');
        $input = $this->input;
        if ($input['kind'] === 'PPH21') {
            $input['program'] = null;
        }
        app(PayrollRemittanceService::class)->remit(Auth::user(), $input);
        $this->input['idempotency_key'] = (string) Str::uuid();
        $this->input['receipt_number'] = '';
        $this->input['amount_minor'] = null;
        Notification::make()->success()->title('Setoran tercatat')->send();
    }

    public function void(): void
    {
        Gate::authorize('manage-payroll');
        app(PayrollRemittanceService::class)->void(Auth::user(),
            PayrollRemittance::query()->findOrFail($this->voidId), $this->voidReason);
        $this->voidId = null;
        $this->voidReason = '';
        Notification::make()->success()->title('Setoran dibatalkan')->send();
    }

    public function remittances(): mixed
    {
        Gate::authorize('manage-payroll');

        return PayrollRemittance::query()->orderByDesc('id')->limit(50)->get();
    }

    public function exportMonthly(): BinaryFileResponse
    {
        Gate::authorize('manage-payroll');
        $month = $this->status()['month'];

        return Excel::download(new PayrollWithholdingExport($month), 'rekap-pph21-'.$month.'.xlsx');
    }

    public function exportAnnual(): BinaryFileResponse
    {
        Gate::authorize('manage-payroll');
        $year = substr($this->status()['month'], 0, 4);

        return Excel::download(new PayrollWithholdingExport($year, true), 'rekap-a1-'.$year.'.xlsx');
    }
}
