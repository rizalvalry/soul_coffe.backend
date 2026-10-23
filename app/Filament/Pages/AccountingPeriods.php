<?php

namespace App\Filament\Pages;

use App\Enums\PanelModule;
use App\Filament\Resources\ClearingReceipts\ClearingReceiptResource;
use App\Filament\Resources\FixedAssets\FixedAssetResource;
use App\Filament\Resources\Settlements\SettlementResource;
use App\Models\AccountingPeriod;
use App\Services\Access\PermissionMatrix;
use App\Services\AccountingPeriodService;
use App\Services\Menu\MenuLabels;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

class AccountingPeriods extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Akuntansi';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Periode Akuntansi';

    protected static ?string $title = 'Periode Akuntansi';

    protected string $view = 'filament.pages.accounting-periods';

    public int $year;

    public int $month;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return MenuLabels::group('Akuntansi');
    }

    public function mount(): void
    {
        $this->year = (int) now()->year;
        $this->month = (int) now()->month;
    }

    public function periodForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Tutup periode bulanan')->schema([
                TextInput::make('year')->label('Tahun')->numeric()->minValue(2000)->maxValue(9999)->required()->live(onBlur: true),
                Select::make('month')->label('Bulan')->options(array_combine(range(1, 12), range(1, 12)))->required()->live(),
            ])->columns(['default' => 1, 'md' => 2]),
        ]);
    }

    public static function canAccess(): bool
    {
        return PermissionMatrix::can(Auth::user(), PanelModule::ACCOUNTING_PERIODS, 'view');
    }

    public function periods(): mixed
    {
        return AccountingPeriod::query()->orderByDesc('year')->orderByDesc('month')->limit(36)->get();
    }

    public function readiness(): array
    {
        abort_unless(self::canAccess(), 403);

        return app(AccountingPeriodService::class)->closeReadiness($this->year, $this->month);
    }

    public function readinessUrl(string $code): string
    {
        return match ($code) {
            'settlements_pending', 'rider_closings_missing' => SettlementResource::getUrl(),
            'clearing_outstanding' => ClearingReceiptResource::getUrl(),
            'stock_cost_pending' => PendingInventoryCosts::getUrl(),
            'depreciation_unposted' => FixedAssetResource::getUrl(),
            'cash_settlement_balance' => GeneralLedger::getUrl(),
            'employee_advances_reconciliation' => EmployeeAdvances::getUrl(),
        };
    }

    public function table(Table $table): Table
    {
        abort_unless(self::canAccess(), 403);

        return $table->query(AccountingPeriod::query()->with('closedBy'))
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('year')->orderByDesc('month'))
            ->columns([
                TextColumn::make('period')->label('Periode')
                    ->getStateUsing(fn (AccountingPeriod $record): string => sprintf('%04d-%02d', $record->year, $record->month)),
                TextColumn::make('status')->label('Status')
                    ->formatStateUsing(fn (string $state): string => $state === 'CLOSED' ? 'Ditutup' : 'Terbuka')->badge(),
                TextColumn::make('closedBy.name')->label('Ditutup oleh')->placeholder('—'),
            ])
            ->recordActions([
                Action::make('toggle')->label(fn (AccountingPeriod $record): string => $record->status === 'CLOSED' ? 'Buka kembali' : 'Tutup')
                    ->visible(fn (): bool => Gate::allows('manage-accounting-periods'))
                    ->requiresConfirmation()
                    ->schema(fn (AccountingPeriod $record): array => $record->status === 'CLOSED'
                        ? [Textarea::make('reason')->label('Alasan buka ulang')->required()->maxLength(255)] : [])
                    ->action(fn (AccountingPeriod $record, array $data) => $this->setStatus($record->year, $record->month,
                        $record->status === 'CLOSED' ? 'OPEN' : 'CLOSED', $data['reason'] ?? null)),
            ]);
    }

    public function setStatus(int $year, int $month, string $status, ?string $reason = null): void
    {
        Gate::authorize('manage-accounting-periods');
        app(AccountingPeriodService::class)->setStatus($year, $month, $status, Auth::user(), $reason);
        Notification::make()->success()->title($status === 'CLOSED' ? 'Periode ditutup' : 'Periode dibuka')->send();
    }

    public function closeSelected(): void
    {
        $this->setStatus($this->year, $this->month, 'CLOSED');
    }
}
