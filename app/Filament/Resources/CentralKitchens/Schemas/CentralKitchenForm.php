<?php

namespace App\Filament\Resources\CentralKitchens\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
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
