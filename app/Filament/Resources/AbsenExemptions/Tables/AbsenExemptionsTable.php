<?php

namespace App\Filament\Resources\AbsenExemptions\Tables;

use App\Enums\AbsenExemptionMode;
use App\Models\AttendanceExemption;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class AbsenExemptionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['cart:id,code', 'createdBy:id,name']))
            ->defaultSort('effective_from', 'desc')
            ->columns([
                TextColumn::make('cart.code')
                    ->label('Gerobak')
                    ->badge()
                    ->searchable()
                    ->sortable(),

                TextColumn::make('mode')
                    ->label('Bentuk izin')
                    ->badge()
                    ->formatStateUsing(fn (AbsenExemptionMode $state): string => $state->label())
                    ->color(fn (AbsenExemptionMode $state): string => $state === AbsenExemptionMode::ANYWHERE ? 'warning' : 'primary')
                    ->description(fn (AttendanceExemption $record): string => $record->mode->description()),

                TextColumn::make('effective_from')
                    ->label('Mulai')
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('effective_until')
                    ->label('Sampai')
                    ->date('d M Y')
                    ->placeholder('tanpa batas')
                    ->sortable(),

                // Whether it applies TODAY, which is the only thing anyone opens this screen to
                // check. Computed rather than stored: a stored flag would need a nightly job and
                // would be wrong between midnight and whenever that job ran.
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (AttendanceExemption $record): string => static::isActive($record) ? 'Berlaku hari ini' : 'Tidak berlaku')
                    ->color(fn (AttendanceExemption $record): string => static::isActive($record) ? 'success' : 'gray'),

                TextColumn::make('reason')
                    ->label('Alasan')
                    ->wrap()
                    ->limit(90),

                TextColumn::make('createdBy.name')
                    ->label('Dibuat oleh')
                    ->placeholder('-')
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('d M Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('berlaku')
                    ->label('Hanya yang berlaku hari ini')
                    ->default()
                    ->query(fn (Builder $query): Builder => $query
                        ->whereDate('effective_from', '<=', today())
                        ->where(fn (Builder $q) => $q
                            ->whereNull('effective_until')
                            ->orWhereDate('effective_until', '>=', today()))),

                SelectFilter::make('mode')
                    ->label('Bentuk izin')
                    ->options(fn (): array => collect(AbsenExemptionMode::cases())
                        ->mapWithKeys(fn (AbsenExemptionMode $mode): array => [$mode->value => $mode->label()])
                        ->all()),

                SelectFilter::make('cart')
                    ->label('Gerobak')
                    ->relationship('cart', 'code')
                    ->searchable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->label('Cabut'),
            ]);
    }

    private static function isActive(AttendanceExemption $record): bool
    {
        $today = Carbon::today();

        return $record->effective_from->lte($today)
            && ($record->effective_until === null || $record->effective_until->gte($today));
    }
}
