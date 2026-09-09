<?php

namespace App\Filament\Resources\Carts\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CartForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->label('Kode Sepeda')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->helperText('Kode dari formulir kertas, mis. 0018.'),

                TextInput::make('plate')
                    ->label('Plat')
                    ->maxLength(255),

                Select::make('status')
                    ->label('Status Unit')
                    // Fixed set from the schema: active | maintenance | retired.
                    ->options([
                        'active' => 'Aktif',
                        'maintenance' => 'Perbaikan',
                        'retired' => 'Tidak dipakai',
                    ])
                    ->default('active')
                    ->required()
                    ->native(false),

                Select::make('kitchen_id')
                    ->label('Dapur Pusat')
                    ->relationship('kitchen', 'name')
                    ->searchable()
                    ->preload()
                    ->helperText('Dapur yang memasok gerobak ini. Dipakai untuk pembatasan antar-dapur.'),

                // The exemption asked for on 2026-09-10. Wording matters here: an administrator
                // must not read this as a permission to sell more, because it has never had any
                // effect on whether a transaction is accepted.
                Toggle::make('high_volume_zone')
                    ->label('Zona ramai — jangan tandai transaksi besar')
                    ->default(false)
                    ->helperText(
                        'Transaksi di atas '.(int) config('soul.sale_suspect_qty_threshold', 15).
                        ' cups sekali input biasanya ditandai dan dilaporkan ke Administrator & Finance. '.
                        'Nyalakan untuk gerobak yang memang selalu ramai, supaya laporan itu tidak muncul '.
                        'terus-menerus. Transaksinya sendiri TETAP diterima, baik menyala maupun tidak — '.
                        'ini hanya soal notifikasi, bukan pembatasan.'
                    ),
            ]);
    }
}
