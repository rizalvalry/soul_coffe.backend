<?php

namespace App\Filament\Resources\Products\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->label('Kode')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->helperText('Dipakai sistem sebagai identitas tetap produk, mis. SOUL-COFFEE.'),

                // The tile the mobile "Request Cups" screen shows. Until this existed those
                // images were bundled in the APK, so adding a product meant shipping a release —
                // see the add_image_to_products migration.
                FileUpload::make('image_path')
                    ->label('Gambar Produk')
                    ->image()
                    ->imageEditor()
                    // Square, because that is the shape of the tile in the app; cropping here
                    // beats letting the phone letterbox a wide photo into a small square.
                    ->imageCropAspectRatio('1:1')
                    ->imageResizeTargetWidth(720)
                    ->imageResizeTargetHeight(720)
                    ->imageResizeMode('cover')
                    ->maxSize(4096)
                    ->disk('public')
                    ->directory('products')
                    ->visibility('public')
                    ->helperText('Persegi, maksimal 4 MB. Dipakai di layar Request Cups aplikasi. Boleh dikosongkan.')
                    ->columnSpanFull(),

                TextInput::make('name')
                    ->label('Nama Produk')
                    ->required()
                    ->maxLength(255),

                Select::make('unit')
                    ->label('Satuan')
                    // The schema pins these two (§3.1, Q3); a free-text unit would break the
                    // qty arithmetic that assumes whole, indivisible units.
                    ->options(['cup' => 'cup', 'pack' => 'pack'])
                    ->required()
                    ->native(false),

                TextInput::make('sort_order')
                    ->label('Urutan')
                    ->numeric()
                    ->minValue(0)
                    ->required()
                    ->helperText('Mengikuti urutan formulir kertas — jangan diurutkan ulang tanpa alasan operasional.'),

                Toggle::make('is_sellable')
                    ->label('Dijual')
                    ->default(true)
                    ->helperText('Nonaktif untuk barang pendukung yang tidak dijual (mis. es batu).'),

                Toggle::make('is_active')
                    ->label('Aktif')
                    ->default(true),
            ]);
    }
}
