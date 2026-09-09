<?php

namespace App\Filament\Resources\DeliveryIncidents;

use App\Enums\IncidentStatus;
use App\Enums\PanelModule;
use App\Filament\Concerns\MatrixGoverned;
use App\Filament\Resources\DeliveryIncidents\Pages\ListDeliveryIncidents;
use App\Filament\Resources\DeliveryIncidents\Tables\DeliveryIncidentsTable;
use App\Models\DeliveryIncident;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * "Insiden Pengiriman" — the decision desk for cups broken on the way to a cart.
 *
 * A rider reports from the road; somebody here decides. The badge is the notification that
 * matters, because a rider is standing next to their bike waiting for the answer: a count of
 * open reports sits beside the menu item in danger red on every page of the panel.
 *
 * No create and no edit. An incident is a report from the field with a photograph attached, and
 * the only thing done to it is a decision — through the two actions in DeliveryIncidentsTable,
 * which carry the effects (cups written off the kitchen ledger, the run cancelled or its
 * prepared quantities reduced) that a free-form edit form could not guarantee.
 */
class DeliveryIncidentResource extends Resource
{
    use MatrixGoverned;

    protected static ?string $model = DeliveryIncident::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|UnitEnum|null $navigationGroup = 'Operasional';

    protected static ?string $navigationLabel = 'Insiden Pengiriman';

    protected static ?string $modelLabel = 'Insiden Pengiriman';

    protected static ?string $pluralModelLabel = 'Insiden Pengiriman';

    protected static ?int $navigationSort = 1;

    public static function panelModule(): PanelModule
    {
        return PanelModule::DELIVERY_INCIDENTS;
    }

    public static function table(Table $table): Table
    {
        return DeliveryIncidentsTable::configure($table);
    }

    public static function getNavigationBadge(): ?string
    {
        // Guarded rather than assumed: the badge is resolved for whoever is signed in, including
        // roles the matrix has not granted this module.
        if (! static::canViewAny()) {
            return null;
        }

        $open = DeliveryIncident::query()->where('status', IncidentStatus::REPORTED)->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Rider menunggu keputusan: batalkan pengantaran atau lanjut sebagian';
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDeliveryIncidents::route('/'),
        ];
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
}
