<x-filament-panels::page>
    <div class="bsi">
        @if ($this->maySeeReport())
            <div class="bsi-sheet">
                <div class="bsi-toolbar">
                    <label>Bulan <input type="month" wire:model.live="month"></label>
                    <label>Dapur
                        <select wire:model.live="kitchenId">
                            <option value="">Semua dapur</option>
                            @foreach ($this->kitchens() as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                        </select>
                    </label>
                    <label>Coffee Bike
                        <select wire:model.live="cartId">
                            <option value="">Semua Coffee Bike</option>
                            @foreach ($this->carts() as $id => $code)<option value="{{ $id }}">{{ $code }}</option>@endforeach
                        </select>
                    </label>
                    <x-filament::button wire:click="exportExcel" icon="heroicon-o-arrow-down-tray">Ekspor Excel</x-filament::button>
                </div>
            </div>
            @foreach ($this->sections() as $section)
                <div class="bsi-sheet">
                    <div class="bsi-sheet__head"><h2 class="bsi-title">{{ $section['title'] }}</h2></div>
                    <div class="bsi-scroll">
                        <table class="bsi-grid">
                            <thead><tr>@foreach ($section['headings'] as $heading)<th>{{ $heading }}</th>@endforeach</tr></thead>
                            <tbody>
                                @forelse ($section['rows'] as $row)
                                    <tr>@foreach ($row as $index => $value)
                                        <td class="{{ is_int($value) ? 'bsi-num' : '' }}">
                                            {{ is_int($value) && in_array($index, $section['money'], true)
                                                ? 'Rp '.number_format($value, 0, ',', '.') : $value }}
                                        </td>
                                    @endforeach</tr>
                                @empty
                                    <tr><td colspan="{{ count($section['headings']) }}">Belum ada barang terbuang di bulan ini.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            @endforeach
            @php($history = $this->classifications())
            <div class="bsi-sheet">
                <div class="bsi-sheet__head"><h2 class="bsi-title">Riwayat waste dan ID koreksi</h2></div>
                <div class="bsi-scroll">
                    <table class="bsi-grid">
                        <thead><tr><th>ID ledger</th><th>Tanggal</th><th>Produk</th><th>Jumlah</th><th>Alasan</th></tr></thead>
                        <tbody>
                            @forelse ($history as $classification)
                                <tr>
                                    <td>{{ $classification->stock_ledger_id }}</td>
                                    <td>{{ $classification->movement->created_at->format('d M Y') }}</td>
                                    <td>{{ $classification->movement->product?->name ?? $classification->movement->rawMaterial?->name }}</td>
                                    <td class="bsi-num">{{ -$classification->movement->qty_delta }}</td>
                                    <td>{{ $classification->reason_code->label() }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5">Belum ada waste di bulan ini.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                {{ $history->links() }}
            </div>
        @else
            <div class="bsi-sheet">Catat pemakaian konten/lapak lewat tombol di atas ya.</div>
        @endif
    </div>
</x-filament-panels::page>
