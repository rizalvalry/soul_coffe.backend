<?php

namespace App\Filament\Resources\Locations\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class LocationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nama Lokasi')
                    ->required()
                    ->maxLength(255),

                TextInput::make('lat')
                    ->label('Latitude')
                    ->numeric()
                    ->required()
                    // decimal(10,7) cannot hold anything outside this range anyway, and a
                    // swapped lat/lng pair is the classic way a geofence ends up in the sea.
                    ->minValue(-90)
                    ->maxValue(90)
                    ->step(0.0000001),

                TextInput::make('lng')
                    ->label('Longitude')
                    ->numeric()
                    ->required()
                    ->minValue(-180)
                    ->maxValue(180)
                    ->step(0.0000001),

                TextInput::make('geofence_m')
                    ->label('Radius Geofence (meter)')
                    ->numeric()
                    ->minValue(1)
                    ->default(100)
                    ->required(),

                Textarea::make('notes')
                    ->label('Catatan')
                    ->rows(3)
                    ->columnSpanFull(),
            ]);
    }
}
