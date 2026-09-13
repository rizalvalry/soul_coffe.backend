<?php

namespace App\Filament\Resources\DirectSales\Schemas;

use App\Models\Cart;
use App\Models\Product;
use App\Models\StaffAssignment;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;

class DirectSaleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('cart_id')
                    ->label('Gerobak')
                    ->options(fn (): array => static::cartOptions())
                    ->searchable()
                    ->required()
                    ->helperText('Pilih gerobak yang bertugas di depan kantor hari ini, atau gerobak yang sedang tidak ditugaskan dan berada di dapur.'),

                Repeater::make('lines')
                    ->label('Produk Terjual')
                    ->schema([
                        Select::make('product_id')
                            ->label('Produk')
                            ->options(fn (): array => Product::query()
                                ->where('is_active', true)
                                ->where('is_sellable', true)
                                ->orderBy('sort_order')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->required()
                            ->live(),

                        TextInput::make('qty')
                            ->label('Jumlah')
                            ->numeric()
                            ->minValue(1)
                            ->required()
                            ->live(),
                    ])
                    ->columns(2)
                    ->minItems(1)
                    ->required()
                    ->addActionLabel('Tambah Produk')
                    ->columnSpanFull(),

                Select::make('payment_method')
                    ->label('Metode Bayar')
                    ->options([
                        'cash' => 'Tunai',
                        'qris' => 'QRIS',
                        'transfer' => 'Transfer',
                    ])
                    ->default('cash')
                    ->required(),

                Textarea::make('note')
                    ->label('Catatan')
                    ->maxLength(500)
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Each cart is labeled with today's StaffAssignment status, the same lookup
     * SaleService::record() already does — but as information for Finance to choose from, not a
     * requirement: a cart with no assignment today is offered exactly as freely as one that has
     * one.
     *
     * @return array<int,string>
     */
    public static function cartOptions(): array
    {
        $today = Carbon::today()->toDateString();

        $assignedStaffNameByCart = StaffAssignment::query()
            ->with('user:id,name')
            ->whereDate('operating_date', $today)
            ->get()
            ->keyBy('cart_id')
            ->map(fn (StaffAssignment $assignment): ?string => $assignment->user?->name);

        return Cart::query()
            ->where('status', 'active')
            ->orderBy('code')
            ->get(['id', 'code'])
            ->mapWithKeys(function (Cart $cart) use ($assignedStaffNameByCart): array {
                $staffName = $assignedStaffNameByCart->get($cart->id);

                $label = $staffName !== null
                    ? sprintf('%s — bertugas hari ini (%s)', $cart->code, $staffName)
                    : sprintf('%s — tidak ditugaskan hari ini', $cart->code);

                return [$cart->id => $label];
            })
            ->all();
    }
}
