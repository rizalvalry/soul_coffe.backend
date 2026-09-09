<?php

namespace App\Filament\Resources\AbsenExemptions;

use App\Enums\PanelModule;
use App\Filament\Concerns\MatrixGoverned;
use App\Filament\Resources\AbsenExemptions\Pages\CreateAbsenExemption;
use App\Filament\Resources\AbsenExemptions\Pages\EditAbsenExemption;
use App\Filament\Resources\AbsenExemptions\Pages\ListAbsenExemptions;
use App\Filament\Resources\AbsenExemptions\Schemas\AbsenExemptionForm;
use App\Filament\Resources\AbsenExemptions\Tables\AbsenExemptionsTable;
use App\Models\AttendanceExemption;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * "Izin Absen Luar Lokasi" — permission for one gerobak code to clock in away from the kitchen.
 *
 * WHY THIS MENU EXISTS AT ALL
 * ---------------------------
 * The absen geofence is a hard rule: more than a few metres from the Dapur Pusat and the clock-in
 * is refused. Real days break that rule for good reasons — a wedding, a car free day, an acara
 * running all week, a mess far from the office, a cart trading in Blok M rather than round the
 * corner. A rule with no legitimate way through does not get obeyed, it gets worked around, and
 * the workaround is someone clocking in on somebody else's behalf. So the exception is a first
 * class record: who granted it, for which cart, from when to when, and why.
 *
 * Administrator and Finance both manage it, which is what was asked for. Finance is granted the
 * module by default in the same migration that creates the table — the people who reconcile the
 * money are the people who know which cart is at an event this week.
 */
class AbsenExemptionResource extends Resource
{
    use MatrixGoverned;

    protected static ?string $model = AttendanceExemption::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static string|UnitEnum|null $navigationGroup = 'Absensi';

    protected static ?string $navigationLabel = 'Izin Absen Luar Lokasi';

    protected static ?string $modelLabel = 'Izin Absen';

    protected static ?string $pluralModelLabel = 'Izin Absen Luar Lokasi';

    protected static ?int $navigationSort = 2;

    public static function panelModule(): PanelModule
    {
        return PanelModule::ABSEN_EXEMPTIONS;
    }

    public static function form(Schema $schema): Schema
    {
        return AbsenExemptionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AbsenExemptionsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAbsenExemptions::route('/'),
            'create' => CreateAbsenExemption::route('/create'),
            'edit' => EditAbsenExemption::route('/{record}/edit'),
        ];
    }
}
