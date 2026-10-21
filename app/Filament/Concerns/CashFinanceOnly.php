<?php

namespace App\Filament\Concerns;

use App\Enums\Role;
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
        return static::allowed();
    }

    public static function canEdit(Model $record): bool
    {
        return static::allowed();
    }

    public static function canDelete(Model $record): bool
    {
        return static::allowed();
    }

    public static function canDeleteAny(): bool
    {
        return static::allowed();
    }

    protected static function allowed(): bool
    {
        return Auth::user()?->role->grants(Role::FINANCE) ?? false;
    }
}
