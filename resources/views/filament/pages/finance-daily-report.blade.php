<x-filament-panels::page>
    <div class="bsi">
        <div class="bsi-sheet">
            <form wire:submit="applyFilters">
                {{ $this->filterForm }}
                <div class="bsi-toolbar bsi-toolbar--end"><x-filament::button type="submit">Tampilkan</x-filament::button></div>
            </form>
            @foreach (['date', 'bikeType', 'rankingPeriod'] as $field)
                @error($field)<p class="bsi-cms-danger">{{ $message }}</p>@enderror
            @endforeach
            <p class="mt-2 text-sm">Filter jenis berlaku buat Per Rider, Rekap, dan Peringkat. Target perusahaan tetap menghitung semua Coffee Bike.</p>
        </div>

        @php($report = $this->displayReport())
        @if ($report === null)
            <div class="bsi-sheet">Filter laporan kurang pas. Pilih tanggal dan jenis Coffee Bike lagi, ya.</div>
        @else
        <div class="bsi-sheet">
            <div class="bsi-sheet__head"><h2 class="bsi-title">Laporan {{ $report['date'] }}</h2></div>
            <div class="flex flex-wrap gap-2">
                @foreach (['per_rider' => 'Per Rider', 'rekap' => 'Rekap', 'target' => 'Target cup', 'peringkat' => 'Peringkat Rider'] as $key => $label)
                    <button type="button" wire:click="setTab('{{ $key }}')" class="bsi-btn" aria-current="{{ $tab === $key ? 'page' : 'false' }}">{{ $label }}</button>
                @endforeach
                <button type="button" wire:click="exportExcel('all')" class="bsi-btn">Unduh semua Excel</button>
            </div>
        </div>

        @if ($tab === 'target')
            @if ($this->canManage())
                <div class="bsi-sheet">
                    <div class="bsi-sheet__head"><h2 class="bsi-title">Atur target {{ $report['date'] }}</h2></div>
                    <div class="flex flex-wrap gap-3">
                        <label>Perusahaan (cup)<input type="number" min="0" max="1000000" wire:model="companyCups" class="bsi-input"></label>
                        <label>Rider (cup)<input type="number" min="0" max="1000000" wire:model="riderCups" class="bsi-input"></label>
                        <button type="button" wire:click="saveTargets" class="bsi-btn">Simpan target</button>
                    </div>
                    @error('companyCups')<p class="bsi-cms-danger">{{ $message }}</p>@enderror
                    @error('riderCups')<p class="bsi-cms-danger">{{ $message }}</p>@enderror
                </div>
            @endif
            <p class="text-sm">Akumulasi kekurangan berjalan dari awal bulan sampai tanggal yang sudah lewat. Hari Rider dihitung dari absen atau Closing; hari absen tanpa Closing belum mencapai target.</p>
        @endif

        @if ($tab === 'per_rider')
            <div class="flex flex-wrap gap-2"><button type="button" wire:click="exportExcel('per_rider')" class="bsi-btn">Unduh Per Rider</button></div>
            @forelse ($report['cards'] as $card)
                <div class="bsi-sheet">
                    <div class="bsi-sheet__head"><h2 class="bsi-title">{{ $card['rider'] }} · {{ $card['cart'] }} · {{ $card['location'] }}</h2></div>
                    <p class="text-sm">{{ $card['bike_type'] }} · Closing Rp {{ number_format($card['total_minor'], 0, ',', '.') }} · Setoran {{ $card['settlement_status'] }}</p>
                    <div class="bsi-scroll">
                        <table class="bsi-grid">
                            <thead><tr>@foreach ($report['sections']['per_rider']['headings'] as $heading)<th>{{ $heading }}</th>@endforeach</tr></thead>
                            <tbody>
                                @foreach ($card['rows'] as $row)
                                    <tr>@foreach ($row as $index => $value)
                                        <td class="{{ is_int($value) ? 'bsi-num' : '' }}">{{ is_int($value) && in_array($index, $report['sections']['per_rider']['money'], true) ? 'Rp '.number_format($value, 0, ',', '.') : ($value ?? '—') }}</td>
                                    @endforeach</tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @empty
                <div class="bsi-sheet">Belum ada Closing buat tanggal dan jenis Coffee Bike ini.</div>
            @endforelse
        @else
            @php($sectionKeys = match ($tab) { 'rekap' => ['rekap'], 'target' => ['target_bulanan', 'target_rider'], default => ['peringkat', 'porsi_produk', 'total_produk'] })
            @if ($tab === 'peringkat')<p class="text-sm">Periode {{ $report['ranking_range'][0] }} s.d. {{ $report['ranking_range'][1] }}</p>@endif
            @foreach ($sectionKeys as $key)
                @php($section = $report['sections'][$key])
                <div class="bsi-sheet">
                    <div class="bsi-sheet__head"><h2 class="bsi-title">{{ $section['title'] }}</h2></div>
                    <div class="flex flex-wrap gap-2 mb-3"><button type="button" wire:click="exportExcel('{{ $key }}')" class="bsi-btn">Unduh Excel</button></div>
                    <div class="bsi-scroll">
                        <table class="bsi-grid">
                            <thead><tr>@foreach ($section['headings'] as $heading)<th>{{ $heading }}</th>@endforeach</tr></thead>
                            <tbody>
                                @forelse ($section['rows'] as $row)
                                    <tr>@foreach ($row as $index => $value)<td class="{{ is_int($value) ? 'bsi-num' : '' }}">{{ is_int($value) && in_array($index, $section['money'], true) ? 'Rp '.number_format($value, 0, ',', '.') : ($value ?? '—') }}</td>@endforeach</tr>
                                @empty
                                    <tr><td colspan="{{ count($section['headings']) }}">Belum ada data di periode ini.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            @endforeach
        @endif
        @endif
    </div>
</x-filament-panels::page>
