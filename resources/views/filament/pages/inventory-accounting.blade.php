<x-filament-panels::page>
    @php($report = $this->report())
    <div class="flex flex-wrap gap-4">
        <label>Bulan biaya standar <input type="month" wire:model.live="month" class="rounded border-gray-300"></label>
        <label>Tanggal mulai selisih stok <input type="date" wire:model.live="startDate" class="rounded border-gray-300"></label>
    </div>
    <x-filament::section heading="Selisih biaya standar vs resep">
        <p class="mb-3">Ditandai kalau selisihnya lebih dari {{ config('soul.recipe_cost_variance_threshold_percent', 10) }}%.</p>
        <div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr><th>Produk</th><th>Standar</th><th>Resep terkini</th><th>Selisih</th><th>%</th></tr></thead><tbody>
        @foreach ($report['costs'] as $row)
            <tr class="border-t {{ $row['flagged'] ? 'text-red-600 font-semibold' : '' }}"><td>{{ $row['product'] }}</td><td>{{ $row['standard_minor'] === null ? '—' : 'Rp '.number_format($row['standard_minor'], 0, ',', '.') }}</td><td>{{ $row['recipe_minor'] === null ? '—' : 'Rp '.number_format($row['recipe_minor'], 0, ',', '.') }}</td><td>{{ $row['difference_minor'] === null ? '—' : 'Rp '.number_format($row['difference_minor'], 0, ',', '.') }}</td><td>{{ $row['difference_percent'] === null ? '—' : $row['difference_percent'].'%' }}</td></tr>
        @endforeach
        </tbody></table></div>
    </x-filament::section>
    <x-filament::section heading="Stok fisik vs buku besar per tanggal mulai">
        <p class="mb-3">Selisih historis tidak diisi otomatis. Akuntan bisa buat jurnal penyesuaian manual dari angka ini.</p>
        <div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr><th>Akun</th><th>Stok fisik × biaya</th><th>Saldo buku</th><th>Selisih</th></tr></thead><tbody>
        @foreach ($report['gap'] as $row)
            <tr class="border-t"><td>{{ $row['account'] }}</td><td>Rp {{ number_format($row['physical_minor'], 0, ',', '.') }}</td><td>Rp {{ number_format($row['book_minor'], 0, ',', '.') }}</td><td>Rp {{ number_format($row['gap_minor'], 0, ',', '.') }}</td></tr>
        @endforeach
        </tbody></table></div>
    </x-filament::section>
</x-filament-panels::page>
