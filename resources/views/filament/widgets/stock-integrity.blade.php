<x-filament-widgets::widget>
    @php $integrity = $this->reconciliation(); @endphp
    <div class="bsi">
        <div class="bsi-sheet">
            <div class="bsi-sheet__head">
                <div class="bsi-sheet__head-left">
                    <span class="bsi-kicker">Pemeriksaan terakhir</span>
                    <h2 class="bsi-title">Integritas Stok</h2>
                </div>
                <span class="bsi-serif">{{ $integrity === null ? 'Belum dicek' : ($integrity['status'] === 'BALANCED' ? 'Seimbang' : 'Perlu dicek') }}</span>
            </div>
            @if ($integrity !== null)
                <div class="bsi-note">
                    <p>Dicek {{ \Illuminate\Support\Carbon::parse($integrity['checked_at'])->locale('id')->translatedFormat('d M Y H:i') }}.</p>
                    @if ($integrity['pending_cost_rows'] > 0)
                        <p>{{ $integrity['pending_cost_rows'] }} baris biaya stok masih tertunda. Cek menu Biaya Stok Tertunda ya.</p>
                    @endif
                    @foreach ($integrity['findings'] as $finding)
                        <p>{{ $finding['locationLabel'] }} · {{ $finding['itemLabel'] }} · ledger {{ number_format($finding['ledger'], 0, ',', '.') }} vs tampilan/buku besar {{ $finding['compared'] === null ? 'belum ada' : number_format($finding['compared'], 0, ',', '.') }} ({{ $finding['code'] }}).</p>
                        @if ($finding['code'] === 'BOOK_MISMATCH')
                            <p>Riwayat lokasi/produk untuk ditelusuri: {{ $finding['stockCandidates'] ?: 'belum ada riwayat ledger' }}. Sumber selisih belum pasti.</p>
                        @endif
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-filament-widgets::widget>
