<?php

namespace App\Filament\Resources\Users\Tables;

use App\Enums\PanelModule;
use App\Enums\Role;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nik')
                    ->label('NIK')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Nama')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('national_id')
                    ->label('No. KTP')
                    ->formatStateUsing(fn (?string $state): ?string => PermissionMatrix::can(Auth::user(), PanelModule::PAYROLL, 'edit') ? $state : User::maskSensitiveValue($state)),
                TextColumn::make('bank_account_number')
                    ->label('No. Rekening')
                    ->formatStateUsing(fn (?string $state): ?string => PermissionMatrix::can(Auth::user(), PanelModule::PAYROLL, 'edit') ? $state : User::maskSensitiveValue($state)),
                TextColumn::make('phone_e164')
                    ->label('Nomor HP')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('role')
                    ->label('Role')
                    ->badge()
                    ->formatStateUsing(fn (Role $state, User $record): string => ($record->is_super_admin ? 'Super Administrator' : $state->label())
                        .($record->isManagement() ? ' · Management' : ''))
                    ->sortable(),
                TextColumn::make('kitchen.name')
                    ->label('Dapur')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('uniform_size')
                    ->label('SIZE')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('monthly_libur_quota')
                    ->label('Jatah klibur')
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('role')
            ->filters([
                SelectFilter::make('role')
                    ->label('Role')
                    ->options(collect(Role::cases())->reject(fn (Role $role): bool => $role === Role::MANAGEMENT)->mapWithKeys(
                        fn (Role $role) => [$role->value => $role->label()]
                    )),
                TernaryFilter::make('is_management')
                    ->label('Status Management')
                    ->placeholder('Semua')
                    ->trueLabel('Management saja')
                    ->falseLabel('Bukan Management')
                    ->queries(
                        true: fn ($query) => $query->where('is_active', true)
                            ->where(fn ($q) => $q->where('is_management', true)->orWhere('is_super_admin', true)),
                        false: fn ($query) => $query->where(fn ($q) => $q->where('is_active', false)
                            ->orWhere(fn ($inner) => $inner->where('is_management', false)->where('is_super_admin', false))),
                    ),
                TernaryFilter::make('is_active')
                    ->label('Status akun')
                    ->placeholder('Semua')
                    ->trueLabel('Aktif saja')
                    ->falseLabel('Nonaktif saja'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
        // Deliberately no delete action: users are referenced by refill requests, staff
        // assignments and audit rows. Deactivating (is_active) is the reversible way to
        // retire an account without orphaning that history.
    }
}
