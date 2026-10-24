<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Enums\PanelModule;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\LocationPriceVersion;
use App\Services\Access\PermissionMatrix;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LocationPriceVersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'locationPriceVersions';

    protected static ?string $title = 'Harga per Lokasi Berjualan';

    protected static ?string $modelLabel = 'harga lokasi';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('location_id')
                ->label('Lokasi Berjualan')
                ->options(fn (): array => Location::query()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()->required(),
            TextInput::make('sell_price_minor')
                ->label('Harga Jual (Rp)')
                ->integer()->minValue(0)->step(1)->required()->prefix('Rp')
                ->rule(fn (Get $get) => function (string $attribute, mixed $value, callable $fail) use ($get): void {
                    $date = $get('effective_from');
                    if ($date && ! $this->getOwnerRecord()->priceVersionAt(Carbon::parse($date)->endOfDay())) {
                        $fail('Isi harga produk dan HPP yang berlaku dulu sebelum harga lokasi ya.');
                    }
                }),
            DatePicker::make('effective_from')
                ->label('Berlaku Mulai')
                ->minDate(now()->addDay()->toDateString())
                ->default(now()->addDay()->toDateString())->required()
                ->helperText('Harga baru mulai berlaku paling cepat besok ya.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('location.name')->label('Lokasi Berjualan'),
            TextColumn::make('sell_price_minor')->label('Harga Jual')->money('IDR', 0),
            TextColumn::make('effective_from')->label('Berlaku Mulai')->date('d M Y')->sortable(),
            TextColumn::make('created_at')->label('Dicatat')->dateTime('d M Y H:i:s'),
        ])->defaultSort('effective_from', 'desc')
            ->headerActions([
                CreateAction::make()->label('Tambah harga lokasi')
                    ->databaseTransaction()
                    ->authorize(fn (): bool => self::canCreatePrice())
                    ->visible(fn (): bool => self::canCreatePrice())
                    ->mutateDataUsing(function (array $data): array {
                        $effectiveDate = Carbon::parse($data['effective_from']);
                        if ($effectiveDate->toDateString() <= today()->toDateString()) {
                            throw ValidationException::withMessages([
                                'effective_from' => 'Harga lokasi baru mulai berlaku paling cepat besok ya.',
                            ]);
                        }
                        if (! $this->getOwnerRecord()->priceVersionAt($effectiveDate->endOfDay())) {
                            throw ValidationException::withMessages([
                                'sell_price_minor' => 'Isi harga produk dan HPP yang berlaku dulu sebelum harga lokasi ya.',
                            ]);
                        }

                        return $data;
                    })
                    ->after(function (LocationPriceVersion $record): void {
                        AuditLog::recordAction(Auth::user(), 'location_price.created', $record, null, [
                            'product_id' => $record->product_id,
                            'location_id' => $record->location_id,
                            'sell_price_minor' => $record->sell_price_minor,
                            'effective_from' => $record->effective_from?->toIso8601String(),
                        ]);
                    }),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }

    protected function getCreateAuthorizationResponse(): Response
    {
        return self::canCreatePrice() ? parent::getCreateAuthorizationResponse() : Response::deny();
    }

    public function mountTableAction(string $name, ?string $record = null, array $arguments = []): mixed
    {
        if ($name === 'create') {
            abort_unless(self::canCreatePrice(), 403);
        }

        return parent::mountTableAction($name, $record, $arguments);
    }

    private static function canCreatePrice(): bool
    {
        return PermissionMatrix::can(Auth::user(), PanelModule::INVENTORY_COSTS, 'edit')
            && PermissionMatrix::can(Auth::user(), PanelModule::PRODUCTS, 'edit');
    }
}
