<x-filament-panels::page>
    <div class="bsi-cms-page">
    <x-filament::section heading="Nilai ulang persediaan tertunda">
        <p class="mb-3">Isi HPP baru di Produk &amp; Harga dulu ya. Nilai stok yang tertunda akan dibukukan hari ini.</p>
        <div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr><th>Produk</th><th class="bsi-num">Gerak tertunda</th><th class="bsi-num">Perkiraan nilai gerak</th><th class="bsi-num">Dampak saldo stok</th><th class="bsi-num">Belum ada HPP baru</th><th>Aksi</th></tr></thead><tbody>
        @forelse ($this->pending() as $row)
            <tr class="border-t"><td>{{ $row['product'] }}</td><td class="bsi-num">{{ $row['count'] }}</td><td class="bsi-num">Rp {{ number_format($row['estimated_minor'], 0, ',', '.') }}</td><td class="bsi-num">Rp {{ number_format($row['stock_impact_minor'], 0, ',', '.') }}</td><td class="bsi-num">{{ $row['unpriced_count'] }}</td><td>
                <x-filament::button size="sm" wire:click="revalueProduct({{ $row['product_id'] }})" wire:confirm="Nilai ulang gerak stok produk ini sekarang?">Nilai ulang persediaan tertunda</x-filament::button>
            </td></tr>
        @empty
            <tr><td colspan="6">Belum ada biaya stok yang tertunda.</td></tr>
        @endforelse
        </tbody></table></div>
    </x-filament::section>
    </div>
</x-filament-panels::page>
