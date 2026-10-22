<?php

namespace App\Filament\Pages;

use App\Models\Account;
use App\Models\PayrollComponentType;
use App\Models\ThrRun;
use App\Models\ThrSettingVersion;
use App\Models\User;
use App\Services\Menu\MenuLabels;
use App\Services\ThrService;
use BackedEnum;
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
use UnitEnum;

class ThrManagement extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static string|UnitEnum|null $navigationGroup = 'Akuntansi';

    protected static ?string $navigationLabel = 'THR';

    protected static ?int $navigationSort = 18;

    protected string $view = 'filament.pages.thr-management';

    public array $settingInput = [];

    public array $runInput = [];

    public string $provisionMonth = '';

    public ?int $selectedRunId = null;

    public string $paymentDate = '';

    public string $paymentAccountId = '';

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
        $this->settingInput = [
            'effective_from' => today()->toDateString(), 'component_type_ids' => [],
            'minimum_months' => 1, 'full_months' => 12, 'prorata_denominator' => 12,
            'reminder_days' => 7, 'reserve_enabled' => false, 'reason' => '',
        ];
        $this->runInput = [
            'holiday_date' => today()->toDateString(), 'planned_payment_date' => today()->toDateString(),
            'user_ids' => [], 'idempotency_key' => (string) Str::uuid(),
        ];
        $this->provisionMonth = today()->format('Y-m');
        $this->paymentDate = today()->toDateString();
    }

    public function settingForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Aturan THR versi baru')->schema([
                DatePicker::make('effective_from')->label('Berlaku mulai')->required(),
                Select::make('component_type_ids')->label('Tunjangan tetap dalam dasar THR')->multiple()
                    ->options(fn (): array => PayrollComponentType::query()->where('kind', 'EARNING')
                        ->where('taxable', true)->orderBy('name')->pluck('name', 'id')->all()),
                TextInput::make('minimum_months')->label('Minimal masa kerja (bulan)')->integer()->minValue(0)->required(),
                TextInput::make('full_months')->label('THR penuh mulai bulan kerja')->integer()->minValue(1)->required(),
                TextInput::make('prorata_denominator')->label('Penyebut pro-rata')->integer()->minValue(1)->required(),
                TextInput::make('reminder_days')->label('Ingatkan bayar sebelum hari raya (hari)')->integer()->minValue(0)->required(),
                Toggle::make('reserve_enabled')->label('Aktifkan cadangan bulanan'),
                Textarea::make('reason')->label('Alasan perubahan')->required()->columnSpanFull(),
            ])->columns(['default' => 1, 'md' => 2]),
        ])->statePath('settingInput');
    }

    public function runForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Buat run THR')->schema([
                DatePicker::make('holiday_date')->label('Tanggal hari raya')->required(),
                DatePicker::make('planned_payment_date')->label('Rencana tanggal bayar')->required(),
                Select::make('user_ids')->label('Karyawan')->multiple()->searchable()
                    ->options(fn (): array => User::query()->whereHas('employeeProfile', fn ($query) => $query->where('payroll_active', true))
                        ->orderBy('name')->pluck('name', 'id')->all())->required()->columnSpanFull(),
            ])->columns(['default' => 1, 'md' => 2]),
        ])->statePath('runInput');
    }

    public function saveSetting(): void
    {
        Gate::authorize('manage-payroll');
        app(ThrService::class)->saveSettings(Auth::user(), $this->settingInput);
        Notification::make()->success()->title('Aturan THR baru tersimpan')->send();
    }

    public function createRun(): void
    {
        Gate::authorize('manage-payroll');
        $run = app(ThrService::class)->create(Auth::user(), $this->runInput);
        $this->selectedRunId = $run->id;
        $this->runInput['idempotency_key'] = (string) Str::uuid();
        Notification::make()->success()->title('Run THR siap direview')->send();
    }

    public function postProvision(): void
    {
        Gate::authorize('manage-payroll');
        app(ThrService::class)->provision(Auth::user(), $this->provisionMonth);
        Notification::make()->success()->title('Cadangan THR bulan ini tercatat')->send();
    }

    public function selectRun(int $id): void
    {
        Gate::authorize('manage-payroll');
        $this->selectedRunId = ThrRun::query()->findOrFail($id)->id;
    }

    public function approve(): void
    {
        app(ThrService::class)->approve(Auth::user(), $this->selected());
        Notification::make()->success()->title('THR sudah di-approve')->send();
    }

    public function pay(): void
    {
        app(ThrService::class)->pay(Auth::user(), $this->selected(), $this->paymentDate, (int) $this->paymentAccountId);
        Notification::make()->success()->title('Pembayaran THR tercatat')->send();
    }

    public function retrySlips(): void
    {
        app(ThrService::class)->retrySlips(Auth::user(), $this->selected());
        Notification::make()->success()->title('Slip THR sudah dicek')->send();
    }

    public function runs(): mixed
    {
        Gate::authorize('manage-payroll');

        return ThrRun::query()->orderByDesc('holiday_date')->limit(36)->get();
    }

    public function selectedRun(): ?ThrRun
    {
        Gate::authorize('manage-payroll');

        return $this->selectedRunId ? $this->selected()->load('items.user') : null;
    }

    public function paymentReminder(ThrRun $run): array
    {
        Gate::authorize('manage-payroll');

        return app(ThrService::class)->paymentReminder($run);
    }

    public function latestSetting(): ?ThrSettingVersion
    {
        Gate::authorize('manage-payroll');

        return app(ThrService::class)->settingAt(today()->toDateString());
    }

    public function paymentAccounts(): array
    {
        Gate::authorize('manage-payroll');

        return Account::query()->whereIn('code', ['1-1100', '1-1200', '1-1300'])->where('is_active', true)
            ->orderBy('code')->pluck('name', 'id')->all();
    }

    private function selected(): ThrRun
    {
        Gate::authorize('manage-payroll');

        return ThrRun::query()->findOrFail($this->selectedRunId);
    }
}
