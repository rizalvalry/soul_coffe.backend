<?php

namespace App\Filament\Resources\Journals;

use App\Enums\PanelModule;
use App\Filament\Resources\Journals\Pages\ListJournals;
use App\Filament\Resources\Journals\Pages\ViewJournal;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Services\Access\PermissionMatrix;
use App\Services\JournalService;
use App\Services\Menu\MenuLabels;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class JournalResource extends Resource
{
    protected static ?string $model = JournalEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|UnitEnum|null $navigationGroup = 'Akuntansi';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Jurnal Umum';

    protected static ?string $modelLabel = 'Jurnal';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return MenuLabels::group('Akuntansi');
    }

    public static function canViewAny(): bool
    {
        return PermissionMatrix::can(Auth::user(), PanelModule::JOURNALS, 'view');
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['postedBy:id,name', 'lines.account:id,code,name']);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('number')->label('Nomor')->searchable()->sortable(),
            TextColumn::make('entry_date')->label('Tanggal')->date('d M Y')->sortable(),
            TextColumn::make('description')->label('Uraian')->searchable()->wrap(),
            TextColumn::make('source_type')->label('Sumber'),
            TextColumn::make('postedBy.name')->label('Diposting oleh')->placeholder('Sistem'),
        ])->filters([
            Filter::make('tanggal')->schema([
                DatePicker::make('from')->label('Dari'), DatePicker::make('to')->label('Sampai'),
            ])->query(fn (Builder $query, array $data): Builder => $query
                ->when($data['from'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('entry_date', '>=', $date))
                ->when($data['to'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('entry_date', '<=', $date))),
            Filter::make('account')->schema([
                Select::make('account_id')->label('Akun')->options(fn (): array => Account::query()->orderBy('code')
                    ->get()->mapWithKeys(fn (Account $account): array => [$account->id => $account->code.' '.$account->name])->all())->searchable(),
            ])->query(fn (Builder $query, array $data): Builder => $query->when($data['account_id'] ?? null,
                fn (Builder $query, $id): Builder => $query->whereHas('lines', fn (Builder $lines): Builder => $lines->where('account_id', $id)))),
            Filter::make('sumber')->schema([TextInput::make('source_type')->label('Sumber')])
                ->query(fn (Builder $query, array $data): Builder => $query->when($data['source_type'] ?? null,
                    fn (Builder $query, $source): Builder => $query->where('source_type', $source))),
        ])->recordActions([
            ViewAction::make(),
            Action::make('reverse')->label('Balik jurnal')->color('danger')->requiresConfirmation()
                ->visible(fn (JournalEntry $record): bool => PermissionMatrix::can(Auth::user(), PanelModule::JOURNAL_REVERSALS, 'edit')
                    && ! in_array($record->source_type, ['payroll_run', 'payroll_run_payment_retry', 'payroll_run_accrual_retry'], true)
                    && $record->reverses_entry_id === null && ! $record->reversal()->exists())
                ->schema([Textarea::make('reason')->label('Alasan')->required()->maxLength(225)])
                ->action(function (JournalEntry $record, array $data): void {
                    abort_unless(PermissionMatrix::can(Auth::user(), PanelModule::JOURNAL_REVERSALS, 'edit'), 403);
                    app(JournalService::class)->reverse($record, $data['reason']);
                }),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Jurnal')->columns(3)->schema([
                TextEntry::make('number')->label('Nomor'),
                TextEntry::make('entry_date')->label('Tanggal')->date('d M Y'),
                TextEntry::make('purpose')->label('Tujuan'),
                TextEntry::make('description')->label('Uraian')->columnSpanFull(),
                TextEntry::make('source_type')->label('Sumber'),
                TextEntry::make('source_id')->label('ID sumber'),
                TextEntry::make('reverses_entry_id')->label('Membalik jurnal')->placeholder('-'),
            ]),
            Section::make('Baris debit dan kredit')->schema([
                RepeatableEntry::make('lines')->label('')->columns(4)->schema([
                    TextEntry::make('account.code')->label('Kode akun'),
                    TextEntry::make('account.name')->label('Akun'),
                    TextEntry::make('debit_minor')->label('Debit')->formatStateUsing(fn (int $state): string => 'Rp '.number_format($state, 0, ',', '.')),
                    TextEntry::make('credit_minor')->label('Kredit')->formatStateUsing(fn (int $state): string => 'Rp '.number_format($state, 0, ',', '.')),
                    TextEntry::make('memo')->label('Catatan')->placeholder('-')->columnSpanFull(),
                ]),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListJournals::route('/'), 'view' => ViewJournal::route('/{record}')];
    }
}
