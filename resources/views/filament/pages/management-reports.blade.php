<x-filament-panels::page>
    <div class="bsi">
        <div class="bsi-sheet">
            <div class="bsi-sheet__head"><h2 class="bsi-title">Pilih periode laporan</h2></div>
            <form wire:submit="applyRange" x-data="{ period: @js($period) }" class="flex flex-wrap items-end gap-3">
                <label class="bsi-field"><span class="bsi-label">Periode</span>
                    <select wire:model="period" x-model="period" class="bsi-select">
                        <option value="daily">Harian</option>
                        <option value="weekly">Mingguan (Senin–Minggu)</option>
                        <option value="monthly">Bulanan</option>
                        <option value="custom">Rentang bebas</option>
                    </select>
                </label>
                <div x-show="period === 'custom'" class="flex flex-wrap gap-3">
                    <label class="bsi-field"><span class="bsi-label">Dari</span><input type="date" wire:model="fromDate" class="bsi-input"></label>
                    <label class="bsi-field"><span class="bsi-label">Sampai</span><input type="date" wire:model="toDate" class="bsi-input"></label>
                </div>
                <div x-show="period !== 'custom'">
                    <label class="bsi-field"><span class="bsi-label">Tanggal acuan</span><input type="date" wire:model="anchorDate" class="bsi-input"></label>
                </div>
                <button type="submit" class="bsi-btn bsi-btn--primary">Tampilkan</button>
            </form>
            @foreach (['period', 'anchorDate', 'fromDate', 'toDate', 'range'] as $field)
                @error($field)<p class="text-red-600">{{ $message }}</p>@enderror
            @endforeach
            <p class="mt-2 text-sm">Rentang maksimal 366 hari. Angka keuangan mengikuti jurnal; rincian produk, setoran, dan waste mengikuti data operasional.</p>
        </div>

        @php($report = $this->report())
        <div class="bsi-sheet">
            <div class="bsi-sheet__head"><h2 class="bsi-title">{{ $report['from'] }} s.d. {{ $report['to'] }}</h2></div>
            <div class="flex flex-wrap gap-2">
                <button type="button" wire:click="exportExcel('all')" class="bsi-btn">Unduh semua Excel</button>
                <button type="button" wire:click="exportPdf('all')" class="bsi-btn">Unduh semua PDF</button>
            </div>
        </div>

        @foreach ($report['sections'] as $key => $section)
            <div class="bsi-sheet">
                <div class="bsi-sheet__head"><h2 class="bsi-title">{{ $section['title'] }}</h2></div>
                <div class="flex flex-wrap gap-2 mb-3">
                    <button type="button" wire:click="exportExcel('{{ $key }}')" class="bsi-btn">Unduh Excel</button>
                    <button type="button" wire:click="exportPdf('{{ $key }}')" class="bsi-btn">Unduh PDF</button>
                </div>
                <div class="bsi-scroll">
                    <table class="bsi-grid">
                        <thead><tr>@foreach ($section['headings'] as $heading)<th>{{ $heading }}</th>@endforeach</tr></thead>
                        <tbody>
                            @forelse ($section['rows'] as $row)
                                <tr>
                                    @foreach ($row as $index => $value)
                                        <td class="{{ in_array($index, $section['money'], true) || is_int($value) ? 'bsi-num' : '' }}">
                                            {{ is_int($value) && in_array($index, $section['money'], true) ? 'Rp '.number_format($value, 0, ',', '.') : $value }}
                                        </td>
                                    @endforeach
                                </tr>
                            @empty
                                <tr><td colspan="{{ count($section['headings']) }}">Belum ada data di periode ini.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endforeach
    </div>
</x-filament-panels::page>
