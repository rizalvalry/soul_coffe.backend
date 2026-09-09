<?php

namespace App\Filament\Resources\Sales\Schemas;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SaleInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Transaksi')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('occurred_at')->label('Waktu')->dateTime('d M Y H:i:s'),
                        TextEntry::make('cart.code')->label('Gerobak')->badge(),
                        TextEntry::make('staff.name')->label('Staff'),
                        TextEntry::make('location.name')->label('Area')->placeholder('-'),
                        TextEntry::make('total_qty')->label('Total cups'),
                        TextEntry::make('total_amount_minor')
                            ->label('Total nilai')
                            ->formatStateUsing(fn (int $state): string => 'Rp '.number_format($state, 0, ',', '.')),
                        TextEntry::make('payment_method')
                            ->label('Pembayaran')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => match ($state) {
                                'cash' => 'Tunai',
                                'qris' => 'QRIS',
                                'transfer' => 'Transfer',
                                default => $state,
                            }),
                        TextEntry::make('note')->label('Catatan')->placeholder('-')->columnSpan(2),
                    ]),

                Section::make('Rincian cups')
                    ->schema([
                        // Prices are the ones pinned at submit time (R10) — reading them off the
                        // product today would rewrite last month's revenue after a price change.
                        RepeatableEntry::make('lines')
                            ->label('')
                            ->columns(4)
                            ->schema([
                                TextEntry::make('product.name')->label('Produk'),
                                TextEntry::make('qty')->label('Jumlah'),
                                TextEntry::make('unit_price_minor')
                                    ->label('Harga satuan saat transaksi')
                                    ->formatStateUsing(fn (int $state): string => 'Rp '.number_format($state, 0, ',', '.')),
                                TextEntry::make('subtotal_minor')
                                    ->label('Subtotal')
                                    ->formatStateUsing(fn (int $state): string => 'Rp '.number_format($state, 0, ',', '.')),
                            ]),
                    ]),

                Section::make('Tinjauan')
                    ->columns(2)
                    ->description('Penandaan hanya berarti "perlu dilihat". Transaksinya sudah tercatat dan stok gerobak sudah berkurang.')
                    ->schema([
                        TextEntry::make('is_suspect')
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(fn (bool $state): string => $state ? 'Perlu ditinjau' : 'Normal')
                            ->color(fn (bool $state): string => $state ? 'danger' : 'gray'),
                        TextEntry::make('suspect_reason')->label('Alasan')->placeholder('-'),
                    ]),

                Section::make('Perangkat & lokasi')
                    ->columns(3)
                    ->collapsed()
                    ->schema([
                        TextEntry::make('gps_lat')->label('Lintang')->placeholder('-'),
                        TextEntry::make('gps_lng')->label('Bujur')->placeholder('-'),
                        TextEntry::make('gps_unavailable')
                            ->label('GPS')
                            // E10: a missing fix is recorded, never a reason to refuse a sale.
                            ->formatStateUsing(fn (bool $state): string => $state ? 'Tidak tersedia saat transaksi' : 'Tersedia'),
                        TextEntry::make('device_id')->label('Perangkat')->placeholder('-'),
                        TextEntry::make('uuid')->label('UUID transaksi')->copyable(),
                        TextEntry::make('idempotency_key')->label('Idempotency key')->placeholder('-'),
                    ]),
            ]);
    }
}
