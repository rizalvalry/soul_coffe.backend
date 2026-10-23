<x-filament-panels::page>
    <div class="bsi">
        <div>
            <form wire:submit="applyRange">
                {{ $this->rangeForm }}
                <div class="bsi-toolbar bsi-toolbar--end"><x-filament::button type="submit">Tampilkan</x-filament::button></div>
            </form>
            @foreach (['period', 'anchorDate', 'fromDate', 'toDate', 'range'] as $field)
                @error($field)<p class="bsi-cms-danger">{{ $message }}</p>@enderror
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
