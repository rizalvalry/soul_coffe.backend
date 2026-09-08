<?php

namespace App\Filament\Resources\PinResetRequests;

use App\Enums\PanelModule;
use App\Filament\Concerns\MatrixGoverned;
use App\Filament\Resources\PinResetRequests\Pages\ListPinResetRequests;
use App\Filament\Resources\PinResetRequests\Tables\PinResetRequestsTable;
use App\Models\PinResetRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * The Administrator's inbox for "lupa PIN".
 *
 * The navigation badge is the notification: a count of PENDING requests sits next to the menu
 * item on every page of the panel, in danger red, so an administrator who signed in for another
 * reason still sees that somebody is locked out. The mobile push (PinResetRequested) reaches them
 * away from the desk; this reaches them at it.
 *
 * No create and no edit: a request is raised by the person who is locked out, and the only thing
 * an administrator does to it is resolve or reject it — both through the actions in
 * PinResetRequestsTable, which carry the side effects (new password, PIN cleared, sessions
 * revoked) that a free-form edit form could not guarantee.
 */
class PinResetRequestResource extends Resource
{
    use MatrixGoverned;

    protected static ?string $model = PinResetRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLockOpen;

    protected static string|UnitEnum|null $navigationGroup = 'Operasional';

    protected static ?string $navigationLabel = 'Permintaan Reset PIN';

    protected static ?string $modelLabel = 'Permintaan Reset PIN';

    protected static ?string $pluralModelLabel = 'Permintaan Reset PIN';

    protected static ?int $navigationSort = 0;

    public static function panelModule(): PanelModule
    {
        return PanelModule::PIN_RESET_REQUESTS;
    }

    public static function table(Table $table): Table
    {
        return PinResetRequestsTable::configure($table);
    }

    /** Pending count, shown beside the menu item. Null hides the badge entirely when clear. */
    public static function getNavigationBadge(): ?string
    {
        // Whether anyone but an administrator sees this resource is now a matrix decision
        // (MatrixGoverned), but the badge is resolved for the navigation of whoever is signed in
        // — CONTENT_CREATOR included — so the query is guarded rather than assumed.
        if (! static::canViewAny()) {
            return null;
        }

        $pending = PinResetRequest::query()->pending()->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Pengguna menunggu kata sandi baru dari Administrator';
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPinResetRequests::route('/'),
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
}
