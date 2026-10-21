<?php

namespace App\Filament\Resources\Accounts;

use App\Enums\Role;
use App\Filament\Resources\Accounts\Pages\CreateAccount;
use App\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Models\Account;
use App\Services\Menu\MenuLabels;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

class AccountResource extends Resource
{
    protected static ?string $model = Account::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static string|UnitEnum|null $navigationGroup = 'Akuntansi';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Bagan Akun';

    protected static ?string $modelLabel = 'Akun';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return MenuLabels::group('Akuntansi');
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->role->grants(Role::FINANCE) ?? false;
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->role->grants(Role::MANAGEMENT) ?? false;
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

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')->label('Kode')->required()->maxLength(32)->unique(ignoreRecord: true),
            TextInput::make('name')->label('Nama akun')->required()->maxLength(255),
            Select::make('type')->label('Jenis')->required()->options([
                'ASSET' => 'Aset', 'LIABILITY' => 'Liabilitas', 'EQUITY' => 'Ekuitas',
                'REVENUE' => 'Pendapatan', 'COGS' => 'HPP', 'EXPENSE' => 'Beban',
            ]),
            Select::make('parent_id')->label('Akun induk')->options(fn (): array => Account::query()
                ->where('is_active', true)->orderBy('code')->get()
                ->mapWithKeys(fn (Account $account): array => [$account->id => $account->code.' '.$account->name])->all())
                ->searchable(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('code')->columns([
            TextColumn::make('code')->label('Kode')->searchable()->sortable(),
            TextColumn::make('name')->label('Nama')->searchable(),
            TextColumn::make('type')->label('Jenis')->badge(),
            TextColumn::make('normal_balance')->label('Saldo normal'),
            TextColumn::make('parent.code')->label('Induk')->placeholder('-'),
            TextColumn::make('is_active')->label('Status')->formatStateUsing(fn (bool $state): string => $state ? 'Aktif' : 'Nonaktif'),
            TextColumn::make('is_system')->label('Sistem')->formatStateUsing(fn (bool $state): string => $state ? 'Ya' : 'Tidak'),
        ])->recordActions([
            Action::make('deactivate')->label('Nonaktifkan')->requiresConfirmation()
                ->visible(fn (Account $record): bool => static::canCreate() && ! $record->is_system && $record->is_active)
                ->action(function (Account $record): void {
                    Gate::authorize('manage-accounting-periods');
                    if ($record->is_system) {
                        abort(403);
                    }
                    $record->update(['is_active' => false]);
                }),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListAccounts::route('/'), 'create' => CreateAccount::route('/create')];
    }
}
