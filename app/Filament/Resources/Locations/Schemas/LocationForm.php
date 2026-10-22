<?php

namespace App\Filament\Resources\Locations\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

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

                // The point is picked on the map; the two numeric fields below are the record of
                // what was picked, and stay editable for anyone who already has exact
                // coordinates. See resources/views/filament/forms/map-picker.blade.php.
                ViewField::make('map')
                    ->label('Titik Lokasi')
                    ->view('filament.forms.map-picker')
                    ->viewData(['latPath' => 'data.lat', 'lngPath' => 'data.lng'])
                    ->dehydrated(false)
                    ->columnSpanFull(),

                TextInput::make('lat')
                    ->label('Latitude')
                    ->numeric()
                    ->required()
                    ->helperText('Terisi otomatis dari peta di atas.')
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
                    ->minValue(5)
                    ->maxValue(5000)
                    ->default(100)
                    ->required(fn (Get $get): bool => ! (bool) $get('use_default_radius'))
                    ->disabled(fn (Get $get): bool => (bool) $get('use_default_radius')),

                Toggle::make('use_default_radius')
                    ->label('Pakai radius bawaan dari Pengaturan Lokasi')
                    ->helperText('Kalau bawaan berubah, radius titik ini ikut berubah.')
                    ->visible(fn (?Model $record): bool => $record !== null)
                    ->live()
                    ->dehydrated(false),

                Textarea::make('radius_reason')
                    ->label('Alasan perubahan radius')
                    ->helperText('Isi minimal 5 karakter kalau kamu mengubah radius.')
                    ->visible(fn (?Model $record): bool => $record !== null)
                    ->dehydrated(false),

                Textarea::make('notes')
                    ->label('Catatan')
                    ->rows(3)
                    ->columnSpanFull(),
            ]);
    }
}
