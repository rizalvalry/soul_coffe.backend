<?php

namespace App\Filament\Pages;

use App\Enums\PanelModule;
use App\Models\Account;
use App\Models\Cart;
use App\Services\Access\PermissionMatrix;
use App\Services\FinancialReportSettingsService;
use App\Services\Menu\MenuLabels;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use UnitEnum;

class FinancialReportSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Akuntansi';

    protected static ?string $navigationLabel = 'Atur Laporan Keuangan';

    protected static ?int $navigationSort = 21;

    protected string $view = 'filament.pages.financial-report-settings';

    public array $mappingInput = [];

    public array $budgetInput = [];

    public array $targetInput = [];

    public static function canAccess(): bool
    {
        return Auth::user() && PermissionMatrix::can(Auth::user(), PanelModule::MANAGEMENT_REPORTS, 'edit');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return MenuLabels::group('Akuntansi');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->mappingInput = ['account_id' => null, 'category' => 'OPERASI'];
        $this->budgetInput = ['month' => today()->format('Y-m'), 'account_id' => null,
            'cart_id' => null, 'amount_minor' => 0];
        $this->targetInput = ['metric' => 'hpp_ratio_bps', 'target_value' => 0];
    }

    public function mappingForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Kelompok arus kas')->schema([
                Select::make('account_id')->label('Akun lawan kas/bank')->options(fn (): array => Account::query()
                    ->whereNotIn('code', ['1-1100', '1-1200', '1-1300'])->orderBy('code')
                    ->get()->mapWithKeys(fn ($account): array => [$account->id => $account->code.' · '.$account->name])->all())
                    ->searchable()->required(),
                Select::make('category')->label('Kelompok')->options([
                    'OPERASI' => 'Operasi', 'INVESTASI' => 'Investasi', 'PENDANAAN' => 'Pendanaan',
                ])->required(),
            ])->columns(['default' => 1, 'md' => 2]),
        ])->statePath('mappingInput');
    }

    public function budgetForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Anggaran bulanan')->schema([
                TextInput::make('month')->label('Bulan')->type('month')->required(),
                Select::make('account_id')->label('Akun pendapatan/beban')->options(fn (): array => Account::query()
                    ->whereIn('type', ['REVENUE', 'EXPENSE'])->orderBy('code')
                    ->get()->mapWithKeys(fn ($account): array => [$account->id => $account->code.' · '.$account->name])->all())
                    ->searchable()->required(),
                Select::make('cart_id')->label('Coffee Bike (opsional)')->options(fn (): array => Cart::query()
                    ->orderBy('code')->pluck('code', 'id')->all())->searchable(),
                TextInput::make('amount_minor')->label('Anggaran (Rp)')->integer()->minValue(0)->required(),
            ])->columns(['default' => 1, 'md' => 2]),
        ])->statePath('budgetInput');
    }

    public function targetForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Target KPI')->schema([
                Select::make('metric')->label('Metrik')->options(FinancialReportSettingsService::KPI_METRICS)->required(),
                TextInput::make('target_value')->label('Target (bps, Rp, atau hari sesuai metrik)')
                    ->helperText('Untuk rasio, 100 basis poin = 1%.')->integer()->minValue(0)->required(),
            ])->columns(['default' => 1, 'md' => 2]),
        ])->statePath('targetInput');
    }

    public function saveMapping(): void
    {
        app(FinancialReportSettingsService::class)->saveCashFlowMapping(Auth::user(),
            (int) ($this->mappingInput['account_id'] ?? 0), (string) ($this->mappingInput['category'] ?? ''));
        Notification::make()->success()->title('Kelompok arus kas tersimpan')->send();
    }

    public function saveBudget(): void
    {
        app(FinancialReportSettingsService::class)->saveBudget(Auth::user(), $this->budgetInput);
        Notification::make()->success()->title('Anggaran tersimpan')->send();
    }

    public function saveTarget(): void
    {
        $target = Validator::make($this->targetInput, [
            'metric' => ['required', 'string'],
            'target_value' => ['required', 'integer', 'min:0'],
        ])->validate();
        app(FinancialReportSettingsService::class)->saveKpiTarget(Auth::user(),
            $target['metric'], (int) $target['target_value']);
        Notification::make()->success()->title('Target KPI tersimpan')->send();
    }

    public function mappings(): mixed
    {
        abort_unless(static::canAccess(), 403);

        return DB::table('cash_flow_mappings as m')->join('accounts as a', 'a.id', '=', 'm.account_id')
            ->orderBy('a.code')->get(['a.code', 'a.name', 'm.category']);
    }

    public function budgets(): mixed
    {
        abort_unless(static::canAccess(), 403);

        return DB::table('financial_budgets as b')->join('accounts as a', 'a.id', '=', 'b.account_id')
            ->leftJoin('carts as c', 'c.id', '=', 'b.cart_id')->orderByDesc('b.month')
            ->limit(50)->get(['b.month', 'a.code', 'a.name', 'c.code as cart_code', 'b.amount_minor']);
    }

    public function targets(): mixed
    {
        abort_unless(static::canAccess(), 403);

        return DB::table('financial_kpi_targets')->orderBy('metric')->get();
    }
}
