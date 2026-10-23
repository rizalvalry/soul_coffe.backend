<?php

namespace App\Filament\Pages;

use App\Enums\PanelModule;
use App\Exports\PayrollRunExport;
use App\Models\EmployeeAdvance;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Services\Access\PermissionMatrix;
use App\Services\EmployeeAdvanceService;
use App\Services\JournalService;
use App\Services\Menu\MenuLabels;
use App\Services\PayrollRunService;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use UnitEnum;

class PayrollRuns extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Akuntansi';

    protected static ?string $navigationLabel = 'Payroll Runs';

    protected static ?string $title = 'Payroll Runs';

    protected string $view = 'filament.pages.payroll-runs';

    public string $newPeriod = '';

    public ?int $selectedRunId = null;

    public string $paymentDate = '';

    public string $paymentAccountId = '';

    public string $cancellationReason = '';

    public array $advanceOverride = ['advance_id' => '', 'amount_minor' => 0];

    public int $paymentGenerationId = 0;

    public int $accrualGenerationId = 0;

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
        $this->newPeriod = now()->format('Y-m');
        $this->paymentDate = now()->format('Y-m-d');
    }

    public function createRunForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Buat run payroll')->schema([
                TextInput::make('newPeriod')->label('Periode')->type('month')->required(),
            ]),
        ]);
    }

    public function paymentForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Pembayaran')->schema([
                DatePicker::make('paymentDate')->label('Tanggal bayar')->required(),
                Select::make('paymentAccountId')->label('Akun bayar')
                    ->options(fn (): array => collect($this->paymentAccounts())
                        ->mapWithKeys(fn ($account): array => [$account->id => $account->code.' · '.$account->name])->all())
                    ->required(),
            ])->columns(['default' => 1, 'md' => 2]),
        ]);
    }

    public function cancellationForm(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('cancellationReason')->label('Alasan pembatalan')->maxLength(225)->required(),
        ]);
    }

    public function advanceOverrideForm(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('advance_id')->label('Kasbon karyawan')
                ->options(fn (): array => EmployeeAdvance::query()->with('user')
                    ->withSum('movements as balance_minor', 'amount_minor')->where('status', 'ACTIVE')
                    ->orderBy('user_id')->get()->mapWithKeys(fn (EmployeeAdvance $advance): array => [
                        $advance->id => $advance->user->name.' · #'.$advance->id.' · sisa Rp '.number_format($advance->balance_minor ?? 0, 0, ',', '.'),
                    ])->all())->searchable()->required(),
            TextInput::make('amount_minor')->label('Potongan payroll (Rp)')->numeric()->rule('integer')->minValue(0)->required(),
        ])->statePath('advanceOverride');
    }

    public function saveAdvanceOverride(): void
    {
        abort_unless(PermissionMatrix::can(Auth::user(), PanelModule::EMPLOYEE_ADVANCES, 'edit'), 403);
        $data = $this->validate([
            'advanceOverride.advance_id' => ['required', 'integer', 'exists:employee_advances,id'],
            'advanceOverride.amount_minor' => ['required', 'integer', 'min:0'],
        ])['advanceOverride'];
        $run = $this->selected();
        DB::transaction(function () use ($run, $data): void {
            app(EmployeeAdvanceService::class)->setPayrollOverride($run,
                EmployeeAdvance::query()->findOrFail($data['advance_id']), (int) $data['amount_minor'], Auth::user());
            app(PayrollRunService::class)->recalculate($run, Auth::user());
        }, 3);
        $this->notify('Potongan kasbon diperbarui dan payroll dihitung ulang');
    }

    public function createRun(): void
    {
        Gate::authorize('manage-payroll');
        $period = $this->validate(['newPeriod' => ['required', 'date_format:Y-m']])['newPeriod'];
        $run = app(PayrollRunService::class)->create($period, Auth::user());
        $this->selectedRunId = $run->id;
        $this->resetTable();
        $this->syncJournalVersions($run);
        $this->notify('Run payroll siap direview');
    }

    public function selectRun(int $id): void
    {
        Gate::authorize('manage-payroll');
        $run = PayrollRun::query()->findOrFail($id);
        $this->selectedRunId = $run->id;
        $this->resetTable();
        $this->syncJournalVersions($run);
    }

    public function recalculate(): void
    {
        app(PayrollRunService::class)->recalculate($this->selected(), Auth::user());
        $this->notify('Hitungan payroll diperbarui');
    }

    public function approve(): void
    {
        app(PayrollRunService::class)->approve($this->selected(), Auth::user(), $this->accrualGenerationId);
        $this->syncJournalVersions($this->selected());
        $this->notify('Payroll sudah di-approve');
    }

    public function retrySlips(): void
    {
        app(PayrollRunService::class)->retrySlips($this->selected(), Auth::user());
        $this->notify('Slip PDF sudah dicek');
    }

    public function cancelPayment(): void
    {
        $reason = $this->validate(['cancellationReason' => ['required', 'string', 'max:225']])['cancellationReason'];
        app(PayrollRunService::class)->cancelPayment($this->selected(), Auth::user(), $reason, $this->paymentGenerationId);
        $this->cancellationReason = '';
        $this->notify('Pembayaran sudah dibatalkan');
    }

    public function cancelRun(): void
    {
        $reason = $this->validate(['cancellationReason' => ['required', 'string', 'max:225']])['cancellationReason'];
        app(PayrollRunService::class)->cancelRun($this->selected(), Auth::user(), $reason, $this->accrualGenerationId);
        $this->cancellationReason = '';
        $this->notify('Run sudah dibatalkan');
    }

    public function reopenRun(): void
    {
        app(PayrollRunService::class)->reopenRun($this->selected(), Auth::user(), $this->accrualGenerationId);
        $this->notify('Run siap dihitung ulang');
    }

    public function markPaid(): void
    {
        Gate::authorize('manage-payroll');
        $data = $this->validate([
            'paymentDate' => ['required', 'date_format:Y-m-d'],
            'paymentAccountId' => ['required', 'integer', 'exists:accounts,id'],
        ]);
        app(PayrollRunService::class)->markPaid($this->selected(), Auth::user(), $data['paymentDate'],
            (int) $data['paymentAccountId'], $this->paymentGenerationId);
        $this->syncJournalVersions($this->selected());
        $this->notify('Payroll sudah ditandai Paid');
    }

    public function export(): BinaryFileResponse
    {
        Gate::authorize('manage-payroll');
        $run = $this->selected();

        return Excel::download(new PayrollRunExport($run), 'rekap-payroll-'.$run->period_start->format('Y-m').'.xlsx');
    }

    public function runs(): array
    {
        Gate::authorize('manage-payroll');

        return PayrollRun::query()->orderByDesc('period_start')->limit(36)->get()->all();
    }

    public function selectedItems(): array
    {
        Gate::authorize('manage-payroll');

        return $this->selectedRunId ? $this->selected()->items()->with('user')->orderBy('user_id')->get()->all() : [];
    }

    public function table(Table $table): Table
    {
        Gate::authorize('manage-payroll');

        return $table->query(PayrollItem::query()->where('run_id', $this->selectedRunId ?? 0)->with('user'))
            ->columns([
                TextColumn::make('user.name')->label('Karyawan'),
                TextColumn::make('gross')->label('Bruto')->getStateUsing(fn (PayrollItem $record): int => (int) $record->snapshot['gross_pay_minor'])
                    ->numeric(decimalPlaces: 0, locale: 'id')->prefix('Rp ')->alignEnd(),
                TextColumn::make('bpjs')->label('BPJS pegawai')->getStateUsing(fn (PayrollItem $record): int => (int) $record->snapshot['bpjs_employee_total_minor'])
                    ->numeric(decimalPlaces: 0, locale: 'id')->prefix('Rp ')->alignEnd(),
                TextColumn::make('pph')->label('PPh 21')->getStateUsing(fn (PayrollItem $record): int => (int) $record->snapshot['pph21_minor'])
                    ->numeric(decimalPlaces: 0, locale: 'id')->prefix('Rp ')->alignEnd(),
                TextColumn::make('take_home')->label('Take-home pay')->getStateUsing(fn (PayrollItem $record): int => (int) $record->snapshot['take_home_minor'])
                    ->numeric(decimalPlaces: 0, locale: 'id')->prefix('Rp ')->alignEnd(),
                TextColumn::make('components')->label('Komponen')
                    ->getStateUsing(fn (PayrollItem $record): string => collect($record->snapshot['components'])
                        ->map(fn (array $component): string => $component['name'].': Rp '.number_format($component['amount_minor'], 0, ',', '.'))
                        ->implode(', '))->wrap(),
                TextColumn::make('slip_path')->label('Slip')->formatStateUsing(fn (?string $state): string => $state ? 'Unduh PDF' : '—')
                    ->url(fn (PayrollItem $record): ?string => $record->slip_path ? route('admin.payroll-slips.download', $record) : null),
            ]);
    }

    public function selectedStatus(): ?string
    {
        Gate::authorize('manage-payroll');

        return $this->selectedRunId ? $this->selected()->status : null;
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            'DRAFT' => 'Draft',
            'APPROVED' => 'Disetujui',
            'PAID' => 'Paid',
            'CANCELLED' => 'Dibatalkan',
            default => $status,
        };
    }

    public function paymentAccounts(): array
    {
        Gate::authorize('manage-payroll');

        return DB::table('accounts')->whereIn('code', ['1-1100', '1-1200', '1-1300'])->where('is_active', true)
            ->orderBy('code')->get(['id', 'code', 'name'])->all();
    }

    private function selected(): PayrollRun
    {
        Gate::authorize('manage-payroll');

        return PayrollRun::query()->findOrFail($this->selectedRunId);
    }

    private function syncJournalVersions(PayrollRun $run): void
    {
        $journal = app(JournalService::class);
        $this->paymentGenerationId = $journal->latestPayrollPayment($run)?->id ?? 0;
        $this->accrualGenerationId = $journal->latestPayrollAccrual($run)?->id ?? 0;
    }

    private function notify(string $text): void
    {
        Notification::make()->title($text)->success()->send();
    }
}
