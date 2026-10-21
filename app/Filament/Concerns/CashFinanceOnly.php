<?php

namespace App\Filament\Concerns;

use App\Enums\PanelModule;
use App\Services\Access\PermissionMatrix;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

trait CashFinanceOnly
{
    public static function canViewAny(): bool
    {
        return static::allowed();
    }

    public static function canView(Model $record): bool
    {
        return static::allowed();
    }

    public static function canCreate(): bool
    {
        return PermissionMatrix::can(Auth::user(), PanelModule::CASH, 'edit');
    }

    public static function canEdit(Model $record): bool
    {
        return PermissionMatrix::can(Auth::user(), PanelModule::CASH, 'edit');
    }

    public static function canDelete(Model $record): bool
    {
        return PermissionMatrix::can(Auth::user(), PanelModule::CASH, 'edit');
    }

    public static function canDeleteAny(): bool
    {
        return PermissionMatrix::can(Auth::user(), PanelModule::CASH, 'edit');
    }

    protected static function allowed(): bool
    {
        return PermissionMatrix::can(Auth::user(), PanelModule::CASH, 'view');
    }
}
