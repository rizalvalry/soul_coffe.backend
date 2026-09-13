<?php

namespace App\Filament\Resources\PurchaseOrders\Schemas;

use App\Models\RawMaterial;
use App\Models\Supplier;
use App\Models\CentralKitchen;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class PurchaseOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('supplier_id')
                    ->label('Pemasok')
                    ->options(fn (): array => Supplier::query()
                        ->where('is_active', true)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->required(),

                Select::make('kitchen_id')
                    ->label('Dapur Pusat')
                    ->options(fn (): array => CentralKitchen::query()
                        ->where('is_active', true)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->required(),

                Repeater::make('lines')
                    ->label('Bahan Baku Dipesan')
                    ->schema([
                        Select::make('raw_material_id')
                            ->label('Bahan Baku')
                            ->options(fn (): array => RawMaterial::query()
                                ->where('is_active', true)
                                ->orderBy('sort_order')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->required(),

                        TextInput::make('qty_ordered')
                            ->label('Jumlah Dipesan')
                            ->numeric()
                            ->minValue(1)
                            ->required(),

                        TextInput::make('unit_cost_minor')
                            ->label('Harga Satuan (Rp)')
                            ->numeric()
                            ->minValue(1)
                            ->required(),
                    ])
                    ->columns(3)
                    ->minItems(1)
                    ->required()
                    ->addActionLabel('Tambah Bahan Baku')
                    ->columnSpanFull(),
            ]);
    }
}
