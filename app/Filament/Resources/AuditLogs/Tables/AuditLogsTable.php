<?php

namespace App\Filament\Resources\AuditLogs\Tables;

use App\Models\AuditLog;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AuditLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Waktu')
                    ->dateTime('d M Y H:i:s')
                    ->sortable(),
                TextColumn::make('actor.name')
                    ->label('Pelaku')
                    ->placeholder('sistem')
                    ->searchable(),
                TextColumn::make('actor_role')
                    ->label('Role')
                    ->badge()
                    ->placeholder('-'),
                TextColumn::make('action')
                    ->label('Aksi')
                    ->badge()
                    ->searchable(),
                TextColumn::make('subject_type')
                    ->label('Objek')
                    // Stored as a FQCN; the class basename is what a human is looking for.
                    ->formatStateUsing(fn (?string $state): string => $state ? class_basename($state) : '-')
                    ->searchable(),
                TextColumn::make('subject_id')
                    ->label('ID Objek')
                    ->searchable(),
                TextColumn::make('ip')
                    ->label('IP')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('device_id')
                    ->label('Perangkat')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('action')
                    ->label('Aksi')
                    ->options(fn (): array => AuditLog::query()
                        ->distinct()
                        ->orderBy('action')
                        ->pluck('action', 'action')
                        ->all()),
                SelectFilter::make('actor_role')
                    ->label('Role pelaku')
                    ->options(fn (): array => AuditLog::query()
                        ->whereNotNull('actor_role')
                        ->distinct()
                        ->orderBy('actor_role')
                        ->pluck('actor_role', 'actor_role')
                        ->all()),
                Filter::make('hari_ini')
                    ->label('Hari ini')
                    ->query(fn (Builder $query): Builder => $query->whereDate('created_at', today())),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            // No create, edit, delete or bulk actions anywhere: audit_log is append-only by
            // design (it has no updated_at at all). A trail an administrator can rewrite is
            // not a trail.
            ->toolbarActions([]);
    }
}
