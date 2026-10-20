<x-filament-panels::page>
    <x-filament::section heading="Nilai ulang persediaan tertunda">
        <p class="mb-3">Isi HPP baru di Produk &amp; Harga dulu ya. Nilai stok yang tertunda akan dibukukan hari ini.</p>
        <div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr><th>Produk</th><th>Gerak tertunda</th><th>Perkiraan nilai gerak</th><th>Dampak saldo stok</th><th>Belum ada HPP baru</th><th>Aksi</th></tr></thead><tbody>
        @forelse ($this->pending() as $row)
            <tr class="border-t"><td>{{ $row['product'] }}</td><td>{{ $row['count'] }}</td><td>Rp {{ number_format($row['estimated_minor'], 0, ',', '.') }}</td><td>Rp {{ number_format($row['stock_impact_minor'], 0, ',', '.') }}</td><td>{{ $row['unpriced_count'] }}</td><td>
                <x-filament::button size="sm" wire:click="revalueProduct({{ $row['product_id'] }})" wire:confirm="Nilai ulang gerak stok produk ini sekarang?">Nilai ulang persediaan tertunda</x-filament::button>
            </td></tr>
        @empty
            <tr><td colspan="6">Belum ada biaya stok yang tertunda.</td></tr>
        @endforelse
        </tbody></table></div>
    </x-filament::section>
</x-filament-panels::page>
