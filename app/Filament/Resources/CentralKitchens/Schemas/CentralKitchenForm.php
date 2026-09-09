<?php

namespace App\Filament\Resources\CentralKitchens\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Schema;

class CentralKitchenForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nama Dapur')
                    ->required()
                    ->maxLength(255),

                Textarea::make('address')
                    ->label('Alamat')
                    ->required()
                    ->rows(3)
                    ->columnSpanFull(),

                // The absen geofence. Picked on the map exactly as a selling point is — same
                // component, same behaviour — because "where is this place" is one question and
                // deserves one answer everywhere it is asked.
                //
                // Leaving the pin empty is a real option and means "no geofence at this kitchen":
                // absen there behaves as it did before this feature existed. That is what lets
                // the rule be switched on one kitchen at a time, and switched back off by
                // clearing the pin.
                ViewField::make('map')
                    ->label('Titik Dapur Pusat')
                    ->view('filament.forms.map-picker')
                    ->viewData(['latPath' => 'data.lat', 'lngPath' => 'data.lng'])
                    ->dehydrated(false)
                    ->columnSpanFull(),

                TextInput::make('lat')
                    ->label('Latitude')
                    ->numeric()
                    ->helperText('Terisi otomatis dari peta. Kosongkan kalau dapur ini belum memakai aturan absen berbasis lokasi.')
                    ->minValue(-90)
                    ->maxValue(90)
                    ->step(0.0000001),

                TextInput::make('lng')
                    ->label('Longitude')
                    ->numeric()
                    ->minValue(-180)
                    ->maxValue(180)
                    ->step(0.0000001),

                TextInput::make('geofence_m')
                    ->label('Radius absen (meter)')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(5000)
                    ->default(10)
                    ->required()
                    ->helperText('Staff dan barista hanya bisa absen dalam radius ini dari titik di atas. Untuk hari yang tidak biasa — event, car free day, mess jauh, jualan di Blok M — buat pengecualian per gerobak di menu Izin Absen.'),

                TimePicker::make('open_at')
                    ->label('Jam Buka')
                    ->seconds(false)
                    ->required(),

                TimePicker::make('close_at')
                    ->label('Jam Tutup')
                    ->seconds(false)
                    ->required()
                    ->after('open_at'),

                Toggle::make('is_active')
                    ->label('Aktif')
                    ->default(true),
            ]);
    }
}
