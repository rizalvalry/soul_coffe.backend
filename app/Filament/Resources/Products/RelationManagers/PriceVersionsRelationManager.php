<?php

namespace App\Filament\Resources\Products\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Price history for a product.
 *
 * R10 says a price is never edited in place: a change is a new row, and the price in effect is
 * the newest row whose effective_from has passed. That rule is why this manager offers Create
 * and nothing else — no edit action, no delete action. Rewriting a past price would silently
 * restate what a settlement was already calculated against.
 */
class PriceVersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'priceVersions';

    protected static ?string $title = 'Riwayat Harga';

    protected static ?string $modelLabel = 'versi harga';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('cost_price_minor')
                    ->label('HPP (Rp)')
                    ->numeric()
                    ->minValue(0)
                    ->required()
                    ->prefix('Rp')
                    // R9: whole rupiah, scale 0 — no decimals anywhere in the money path.
                    ->step(1),

                TextInput::make('sell_price_minor')
                    ->label('Harga Jual (Rp)')
                    ->numeric()
                    ->minValue(0)
                    ->required()
                    ->prefix('Rp')
                    ->step(1),

                DateTimePicker::make('effective_from')
                    ->label('Berlaku Mulai')
                    // Seconds are kept deliberately. Hiding them floors the value to :00, so a
                    // correction entered in the same minute as the version it replaces lands
                    // *before* it and silently never takes effect — the row is written, the
                    // price does not change, and nothing reports an error.
                    ->seconds()
                    ->default(now())
                    ->required()
                    ->helperText('Harga yang dipakai sistem adalah versi terbaru yang waktunya sudah lewat.'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('effective_from')
                    ->label('Berlaku Mulai')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
                TextColumn::make('cost_price_minor')
                    ->label('HPP')
                    ->money('IDR', 0),
                TextColumn::make('sell_price_minor')
                    ->label('Harga Jual')
                    ->money('IDR', 0),
                TextColumn::make('created_at')
                    ->label('Dicatat')
                    ->dateTime('d M Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('effective_from', 'desc')
            ->headerActions([
                CreateAction::make()->label('Tambah versi harga'),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
