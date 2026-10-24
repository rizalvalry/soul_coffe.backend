<x-filament-panels::page>
    <div class="bsi-erp bsi-sheet">
    <div class="bsi-toolbar">
        <label>Bulan <input type="month" wire:model.live="month"></label>
        <label>Karyawan
            <select wire:model.live="employeeId">
                <option value="">Semua karyawan</option>
                @foreach ($this->employees() as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </label>
        <x-filament::button wire:click="exportExcel" icon="heroicon-o-arrow-down-tray">Ekspor Excel</x-filament::button>
    </div>
    @if ($employeeId !== '')
        <p>Filter karyawan cuma menampilkan transaksi harian yang mencantumkan karyawan itu. Penyesuaian akun tanpa karyawan tidak ikut.</p>
    @endif
    @php($matrix = $this->matrix())
    <div class="bsi-scroll">
        <table class="bsi-grid">
            <thead><tr>
                <th>Kategori</th>
                @foreach ($matrix['days'] as $day)<th>{{ $day }}</th>@endforeach
                <th>Total jumlah</th><th>Total rupiah</th>
            </tr></thead>
            <tbody>
                @forelse ($matrix['rows'] as $row)
                    <tr>
                        <th>{{ $row['category'] }}</th>
                        @foreach ($matrix['days'] as $day)
                            <td class="bsi-num">
                                @if (isset($row['cells'][$day]))
                                    {{ number_format($row['cells'][$day]['quantity'], 0, ',', '.') }}<br>
                                    Rp {{ number_format($row['cells'][$day]['amount_minor'], 0, ',', '.') }}
                                @else — @endif
                            </td>
                        @endforeach
                        <td class="bsi-num">{{ number_format($row['quantity'], 0, ',', '.') }}</td>
                        <td class="bsi-num">Rp {{ number_format($row['amount_minor'], 0, ',', '.') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="{{ count($matrix['days']) + 3 }}">Belum ada biaya harian di bulan ini.</td></tr>
                @endforelse
            </tbody>
            <tfoot><tr>
                <th>Total</th>
                @foreach ($matrix['days'] as $day)
                    <td class="bsi-num">
                        @php($daily = collect($matrix['rows'])->sum(fn ($row) => $row['cells'][$day]['amount_minor'] ?? 0))
                        {{ $daily ? 'Rp '.number_format($daily, 0, ',', '.') : '—' }}
                    </td>
                @endforeach
                <td class="bsi-num">{{ number_format($matrix['totals']['quantity'], 0, ',', '.') }}</td>
                <td class="bsi-num">Rp {{ number_format($matrix['totals']['amount_minor'], 0, ',', '.') }}</td>
            </tr></tfoot>
        </table>
    </div>
    </div>
</x-filament-panels::page>
