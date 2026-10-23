<x-filament-panels::page>
    @php($report = $this->report())
    <div class="bsi-cms-page">
    <div class="flex flex-wrap gap-4">
        <label>Bulan biaya standar <input type="month" wire:model.live="month" class="bsi-input"></label>
        <label>Tanggal mulai selisih stok <input type="date" wire:model.live="startDate" class="bsi-input"></label>
    </div>
    <x-filament::section heading="Selisih biaya standar vs resep">
        <p class="mb-3">Ditandai kalau selisihnya lebih dari {{ config('soul.recipe_cost_variance_threshold_percent', 10) }}%.</p>
        <div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr><th>Produk</th><th class="bsi-num">Standar</th><th class="bsi-num">Resep terkini</th><th class="bsi-num">Selisih</th><th class="bsi-num">%</th></tr></thead><tbody>
        @foreach ($report['costs'] as $row)
            <tr class="border-t {{ $row['flagged'] ? 'bsi-cms-danger font-semibold' : '' }}"><td>{{ $row['product'] }}</td><td class="bsi-num">{{ $row['standard_minor'] === null ? '—' : 'Rp '.number_format($row['standard_minor'], 0, ',', '.') }}</td><td class="bsi-num">{{ $row['recipe_minor'] === null ? '—' : 'Rp '.number_format($row['recipe_minor'], 0, ',', '.') }}</td><td class="bsi-num">{{ $row['difference_minor'] === null ? '—' : 'Rp '.number_format($row['difference_minor'], 0, ',', '.') }}</td><td class="bsi-num">{{ $row['difference_percent'] === null ? '—' : $row['difference_percent'].'%' }}</td></tr>
        @endforeach
        </tbody></table></div>
    </x-filament::section>
    <x-filament::section heading="Stok fisik vs buku besar per tanggal mulai">
        <p class="mb-3">Selisih historis tidak diisi otomatis. Akuntan bisa buat jurnal penyesuaian manual dari angka ini.</p>
        <div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr><th>Akun</th><th class="bsi-num">Stok fisik × biaya</th><th class="bsi-num">Saldo buku</th><th class="bsi-num">Selisih</th><th class="bsi-num">Biaya fallback</th></tr></thead><tbody>
        @foreach ($report['gap'] as $row)
            <tr class="border-t"><td>{{ $row['account'] }}</td><td class="bsi-num">Rp {{ number_format($row['physical_minor'], 0, ',', '.') }}</td><td class="bsi-num">Rp {{ number_format($row['book_minor'], 0, ',', '.') }}</td><td class="bsi-num">Rp {{ number_format($row['gap_minor'], 0, ',', '.') }}</td><td class="bsi-num">{{ $row['fallback_rows'] }} baris</td></tr>
        @endforeach
        </tbody></table></div>
    </x-filament::section>
    <x-filament::section heading="Biaya stok tertunda">
        <p class="mb-3">Isi HPP baru di Produk &amp; Harga, lalu pilih "Nilai ulang persediaan tertunda" pada produk itu. Nilainya dibukukan hari penyelesaian.</p>
        <div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr><th>Produk</th><th class="bsi-num">Gerak tertunda</th><th class="bsi-num">Perkiraan nilai gerak</th><th class="bsi-num">Dampak saldo stok</th><th class="bsi-num">Belum ada HPP baru</th><th>Aksi</th></tr></thead><tbody>
        @forelse ($report['pending'] as $row)
            <tr class="border-t"><td>{{ $row['product'] }}</td><td class="bsi-num">{{ $row['count'] }}</td><td class="bsi-num">Rp {{ number_format($row['estimated_minor'], 0, ',', '.') }}</td><td class="bsi-num">Rp {{ number_format($row['stock_impact_minor'], 0, ',', '.') }}</td><td class="bsi-num">{{ $row['unpriced_count'] }}</td><td>
                @can('revalue-inventory')
                    <x-filament::button size="sm" wire:click="revalueProduct({{ $row['product_id'] }})" wire:confirm="Nilai ulang gerak stok produk ini sekarang?">Nilai ulang persediaan tertunda</x-filament::button>
                @endcan
            </td></tr>
        @empty
            <tr><td colspan="6">Belum ada biaya stok yang tertunda.</td></tr>
        @endforelse
        </tbody></table></div>
    </x-filament::section>
    </div>
</x-filament-panels::page>
