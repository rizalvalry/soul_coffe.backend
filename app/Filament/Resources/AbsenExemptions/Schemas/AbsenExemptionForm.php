<?php

namespace App\Filament\Resources\AbsenExemptions\Schemas;

use App\Enums\AbsenExemptionMode;
use App\Models\Cart;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class AbsenExemptionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('cart_id')
                    ->label('Gerobak')
                    ->options(fn (): array => Cart::query()
                        ->whereIn('status', ['active', 'maintenance'])
                        ->orderBy('code')
                        ->pluck('code', 'id')
                        ->all())
                    ->searchable()
                    ->required()
                    ->helperText('Izin melekat pada gerobaknya, bukan pada orangnya — siapa pun yang ditugaskan ke gerobak ini pada tanggal tersebut ikut memakainya.'),

                Radio::make('mode')
                    ->label('Bentuk izin')
                    ->options(fn (): array => collect(AbsenExemptionMode::cases())
                        ->mapWithKeys(fn (AbsenExemptionMode $mode): array => [$mode->value => $mode->label()])
                        ->all())
                    ->descriptions(fn (): array => collect(AbsenExemptionMode::cases())
                        ->mapWithKeys(fn (AbsenExemptionMode $mode): array => [$mode->value => $mode->description()])
                        ->all())
                    ->default(AbsenExemptionMode::SELLING_LOCATION->value)
                    ->required()
                    ->columnSpanFull(),

                DatePicker::make('effective_from')
                    ->label('Berlaku mulai')
                    ->default(now())
                    ->required(),

                DatePicker::make('effective_until')
                    ->label('Berlaku sampai')
                    ->afterOrEqual('effective_from')
                    // Blank on purpose is a real answer: a mess far from the kitchen is not a
                    // one-week problem, and forcing an end date would only produce a fake one.
                    ->helperText('Kosongkan kalau belum ada tanggal selesainya. Izin tetap berlaku sampai dicabut.'),

                Textarea::make('reason')
                    ->label('Alasan')
                    ->required()
                    ->rows(3)
                    ->maxLength(500)
                    ->helperText('Wajib. Contoh: “Event Car Free Day Sudirman 12–14 Sep”, “Mess staff di Bekasi”, “Berjualan di Blok M selama acara”. Ini yang dibaca saat laporan absensi ditinjau.')
                    ->columnSpanFull(),
            ]);
    }
}
