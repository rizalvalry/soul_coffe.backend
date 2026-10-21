<?php

namespace App\Filament\Resources\RefillRequests;

use App\Enums\RefillStatus;
use App\Enums\Role;
use App\Filament\Resources\RefillRequests\Pages\ListRefillRequests;
use App\Filament\Resources\RefillRequests\Tables\RefillRequestsTable;
use App\Models\RefillRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class RefillRequestResource extends Resource
{
    protected static ?string $model = RefillRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static string|UnitEnum|null $navigationGroup = 'Operasional';

    protected static ?string $navigationLabel = 'Refill Kedaluwarsa';

    protected static ?string $modelLabel = 'Request Refill';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return Auth::user()?->role->grants(Role::FINANCE) ?? false;
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

    public static function table(Table $table): Table
    {
        return RefillRequestsTable::configure($table);
    }

    public static function getPages(): array
    {
        return ['index' => ListRefillRequests::route('/')];
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canViewAny()) {
            return null;
        }

        $count = RefillRequest::query()->where('status', RefillStatus::EXPIRED)
            ->whereDoesntHave('expiryDecision')->count();

        return $count > 0 ? (string) $count : null;
    }
}
