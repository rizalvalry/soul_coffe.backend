<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Enums\PanelModule;
use App\Services\Access\PermissionMatrix;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

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
                    ->visible(fn (): bool => self::canViewCost())
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

                DatePicker::make('effective_from')
                    ->label('Berlaku Mulai')
                    ->minDate(fn (): string => $this->getOwnerRecord()->priceVersions()->exists()
                        ? today()->addDay()->toDateString() : today()->toDateString())
                    ->default(fn (): string => $this->getOwnerRecord()->priceVersions()->exists()
                        ? today()->addDay()->toDateString() : today()->toDateString())
                    ->required()
                    ->helperText('Harga pertama boleh mulai hari ini. Perubahan berikutnya mulai besok ya.'),
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
                    ->visible(fn (): bool => self::canViewCost())
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
                CreateAction::make()->label('Tambah versi harga')
                    ->authorize(fn (): bool => self::canCreatePriceVersion())
                    ->visible(fn (): bool => self::canCreatePriceVersion())
                    ->mutateDataUsing(function (array $data): array {
                        $firstPrice = ! $this->getOwnerRecord()->priceVersions()->exists();
                        $earliest = $firstPrice ? today()->toDateString() : today()->addDay()->toDateString();
                        if (Carbon::parse($data['effective_from'])->toDateString() < $earliest) {
                            throw ValidationException::withMessages([
                                'effective_from' => $firstPrice
                                    ? 'Harga pertama mulai paling cepat hari ini ya.'
                                    : 'Perubahan harga mulai paling cepat besok ya.',
                            ]);
                        }

                        return $data;
                    }),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }

    protected function getCreateAuthorizationResponse(): Response
    {
        return self::canCreatePriceVersion() ? parent::getCreateAuthorizationResponse() : Response::deny();
    }

    private static function canViewCost(): bool
    {
        return PermissionMatrix::can(Auth::user(), PanelModule::INVENTORY_COSTS, 'edit');
    }

    private static function canCreatePriceVersion(): bool
    {
        return self::canViewCost()
            && PermissionMatrix::can(Auth::user(), PanelModule::PRODUCTS, 'edit');
    }
}
