<x-filament-panels::page>
    <div class="bsi">
        @can ('manage-accounting-periods')
        <div class="bsi-sheet">
            <div class="bsi-sheet__head"><h2 class="bsi-title">Tutup periode bulanan</h2></div>
            <div class="bsi-toolbar">
                <div class="bsi-field">
                    <label class="bsi-label" for="accounting-year">Tahun</label>
                    <input id="accounting-year" class="bsi-input" type="number" min="2000" max="9999" wire:model="year">
                </div>
                <div class="bsi-field">
                    <label class="bsi-label" for="accounting-month">Bulan</label>
                    <select id="accounting-month" class="bsi-select" wire:model="month">
                        @for ($month = 1; $month <= 12; $month++)
                            <option value="{{ $month }}">{{ $month }}</option>
                        @endfor
                    </select>
                </div>
                <button type="button" class="bsi-btn bsi-btn--ink" wire:click="closeSelected" wire:loading.attr="disabled">Tutup Periode</button>
            </div>
        </div>
        @endcan
        <div class="bsi-sheet">
            <div class="bsi-sheet__head"><h2 class="bsi-title">Riwayat periode</h2></div>
            <div class="bsi-scroll">
                <table class="bsi-grid">
                    <thead><tr><th>Periode</th><th>Status</th><th>Ditutup oleh</th><th>Tindakan</th></tr></thead>
                    <tbody>
                        @foreach ($this->periods() as $period)
                            <tr>
                                <td>{{ sprintf('%04d-%02d', $period->year, $period->month) }}</td>
                                <td>{{ $period->status === 'CLOSED' ? 'Ditutup' : 'Terbuka' }}</td>
                                <td>{{ $period->closedBy?->name ?? '-' }}</td>
                                <td>
                                    @can ('manage-accounting-periods')
                                    @if ($period->status === 'CLOSED')
                                        <button type="button" class="bsi-btn" wire:click="setStatus({{ $period->year }}, {{ $period->month }}, 'OPEN')" wire:loading.attr="disabled">Buka kembali</button>
                                    @else
                                        <button type="button" class="bsi-btn" wire:click="setStatus({{ $period->year }}, {{ $period->month }}, 'CLOSED')" wire:loading.attr="disabled">Tutup</button>
                                    @endif
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-filament-panels::page>
