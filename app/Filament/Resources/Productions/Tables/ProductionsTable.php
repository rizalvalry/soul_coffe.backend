<?php

namespace App\Filament\Resources\Productions\Tables;

use App\Enums\PanelModule;
use App\Models\Production;
use App\Models\ProductionLine;
use App\Services\Access\PermissionMatrix;
use App\Services\ProductionService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class ProductionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'kitchen:id,name', 'actor:id,name', 'lines.product:id,name',
            ]))
            ->defaultSort('produced_at', 'desc')
            ->columns([
                TextColumn::make('produced_at')
                    ->label('Waktu')
                    ->dateTime('d M Y H:i')
                    ->sortable(),

                TextColumn::make('kitchen.name')
                    ->label('Dapur Pusat'),

                TextColumn::make('actor.name')
                    ->label('Produksi'),

                TextColumn::make('lines')
                    ->label('Produk Diseduh')
                    ->wrap()
                    ->state(fn (Production $record): string => $record->lines
                        ->map(fn (ProductionLine $line): string => sprintf(
                            '%s x%d%s',
                            $line->product?->name ?? '-',
                            $line->qty_brewed,
                            $line->recipe_id ? '' : ' (Resep belum ada)',
                        ))
                        ->implode(', ')),

                TextColumn::make('cost')
                    ->label('Estimasi HPP Bahan Baku')
                    ->visible(fn (): bool => PermissionMatrix::can(Auth::user(), PanelModule::INVENTORY_COSTS, 'edit'))
                    ->state(fn (Production $record): int => $record->lines
                        ->sum(fn (ProductionLine $line): int => ($line->recipe_cost_minor ?? 0) * $line->qty_brewed))
                    ->money('IDR', 0),
                TextColumn::make('voided_at')->label('Status')
                    ->formatStateUsing(fn ($state): string => $state ? 'Dibatalkan' : 'Aktif'),
            ])->recordActions([
                Action::make('void')->label('Batalkan produksi')
                    ->visible(fn (Production $record): bool => $record->voided_at === null && Gate::allows('void-production'))
                    ->schema([Textarea::make('reason')->label('Alasan')->required()->maxLength(255)])
                    ->requiresConfirmation()
                    ->action(fn (Production $record, array $data) => app(ProductionService::class)->void($record, $data['reason'], Auth::user())),
            ]);
    }
}
