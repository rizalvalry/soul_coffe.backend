<?php

namespace App\Filament\Resources\Partners;

use App\Enums\PanelModule;
use App\Filament\Concerns\MatrixGoverned;
use App\Filament\Resources\Partners\Pages\CreatePartner;
use App\Filament\Resources\Partners\Pages\EditPartner;
use App\Filament\Resources\Partners\Pages\ListPartners;
use App\Filament\Resources\Partners\Schemas\PartnerForm;
use App\Filament\Resources\Partners\Tables\PartnersTable;
use App\Models\Partner;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The left-hand columns of the absensi sheet — NIK, Nama Karyawan, SIZE — as editable master data.
 *
 * Separate from "Pengguna & Role" on purpose: a partner here may have no login at all, and the
 * sheet this module reproduces lists several who don't (rows 24-27 of the reference have neither
 * a NIK nor an account). Where they do have one, the form links it.
 */
class PartnerResource extends Resource
{
    use MatrixGoverned;

    protected static ?string $model = Partner::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = 'Absensi';

    protected static ?string $navigationLabel = 'Data Partner';

    protected static ?string $modelLabel = 'Partner';

    protected static ?string $pluralModelLabel = 'Partner';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function panelModule(): PanelModule
    {
        return PanelModule::PARTNERS;
    }

    public static function form(Schema $schema): Schema
    {
        return PartnerForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PartnersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPartners::route('/'),
            'create' => CreatePartner::route('/create'),
            'edit' => EditPartner::route('/{record}/edit'),
        ];
    }
}
