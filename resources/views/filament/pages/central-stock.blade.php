{{--
    Stok Terpusat, in the BSI Black & White system (see public/css/bsi-bw.css).

    The grid itself is still never edited directly: stock moves by brewing, handing over,
    refilling and closing out. The one write this page allows is the "Penyesuaian Stok" header
    action, which posts an explicit compensating ledger row through StockAdjustmentService
    instead of typing over a cell — see CentralStock's class docblock.

    Every class here is a `bsi-` class from our own stylesheet — this project has no panel build
    step, so a Tailwind utility written here would simply never apply.
--}}
<x-filament-panels::page>
    @php
        $snap = $this->snapshot();
        $products = $snap['products'];
        $rawMaterials = $snap['raw_materials'];
        $colspan = 2 + $products->count();
    @endphp

    <div class="bsi">
        @php $integrity = $this->reconciliation(); @endphp
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
        <div class="bsi-cms-card">
            <h2>Riwayat serah terima ke Coffee Bike</h2>
            <div class="bsi-cms-table-scroll"><table class="bsi-cms-table">
                <thead><tr><th>Waktu</th><th>Coffee Bike</th><th>Rider</th><th>Produk</th><th>Jumlah</th><th>Catatan lapangan</th></tr></thead>
                <tbody>
                    @forelse ($this->handoverHistory() as $handover)
                        <tr><td>{{ \Illuminate\Support\Carbon::parse($handover->created_at)->format('d M H:i') }}</td>
                            <td>{{ $handover->cart_code }}</td><td>{{ $handover->staff_name }}</td>
                            <td>{{ $handover->product_name }}</td><td>{{ $handover->qty }}</td><td>{{ $handover->note ?: '-' }}</td></tr>
                    @empty
                        <tr><td colspan="6">Belum ada serah terima.</td></tr>
                    @endforelse
                </tbody>
            </table></div>
        </div>
        {{-- The three numbers the question is really about: how many cups exist, how many are
             still at the kitchen, how many are already out on carts. --}}
        <div class="bsi-stat-row">
            <div class="bsi-stat">
                <span class="bsi-kicker">Total cups produk aktif</span>
                <span class="bsi-stat__value">{{ number_format($snap['grand'], 0, ',', '.') }}</span>
                <span class="bsi-stat__hint">dapur + seluruh Coffee Bike</span>
            </div>
            <div class="bsi-stat">
                <span class="bsi-kicker">Masih di dapur</span>
                <span class="bsi-stat__value">{{ number_format($snap['kitchen_grand'], 0, ',', '.') }}</span>
                <span class="bsi-stat__hint">belum dibagikan ke Coffee Bike</span>
            </div>
            <div class="bsi-stat">
                <span class="bsi-kicker">Sudah di Coffee Bike</span>
                <span class="bsi-stat__value">{{ number_format($snap['cart_grand'], 0, ',', '.') }}</span>
                <span class="bsi-stat__hint">tersebar di Coffee Bike aktif</span>
            </div>
        </div>

        <div class="bsi-note">
            <span class="bsi-kicker">Cara membacanya</span>
            <p>
                Angka produk aktif di sini <strong>dihitung dari buku besar stok</strong>, bukan dari penghitung
                yang bisa diketik — jadi tidak mungkin berbeda dari pergerakan yang membentuknya.
                Saldo produk lama tetap tercatat di buku besar untuk dicek Finance.
                Stok bergerak lewat aktivitas: Produksi menyeduh dan mengisi Coffee Bike (Add Stock),
                tim mengirim refill, lalu Runner menghitung sisa saat Tutup Coffee Bike.
            </p>
            <p>
                Coffee Bike berstatus <em>maintenance</em> tetap dihitung. Yang berstatus
                <em>retired</em> juga tetap tampil kalau punya riwayat stok, supaya saldonya bisa dicek.
            </p>
        </div>

        @if ($products->isEmpty())
            <div class="bsi-sheet">
                <div class="bsi-empty">
                    <p class="bsi-empty__title">Belum ada produk aktif</p>
                    <p>Tambahkan lewat menu Master Data → Produk &amp; Harga, lalu kembali ke sini.</p>
                </div>
            </div>
        @else
            <div class="bsi-sheet">
                <div class="bsi-sheet__head">
                    <div class="bsi-sheet__head-left">
                        <span class="bsi-kicker">Sebelum dibagikan</span>
                        <h2 class="bsi-title">Stok di dapur pusat</h2>
                    </div>
                    {{-- The one serif-italic moment on this view. --}}
                    <span class="bsi-serif">{{ number_format($snap['kitchen_grand'], 0, ',', '.') }} cups</span>
                </div>

                <div class="bsi-scroll">
                    <table class="bsi-grid">
                        <thead>
                            <tr>
                                <th class="bsi-name">Dapur Pusat</th>
                                @foreach ($products as $product)
                                    <th>{{ $product->name }}<br><span class="bsi-th-unit">{{ $product->unit }}</span></th>
                                @endforeach
                                <th class="bsi-sum-first">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($snap['kitchens'] as $row)
                                <tr>
                                    <td class="bsi-name">{{ $row['kitchen']->name }}</td>
                                    @foreach ($products as $product)
                                        @php $qty = $row['qty'][$product->id] ?? 0; @endphp
                                        <td class="bsi-num {{ $qty === 0 ? 'bsi-num--quiet' : '' }}">{{ number_format($qty, 0, ',', '.') }}</td>
                                    @endforeach
                                    <td class="bsi-sum-first bsi-num bsi-num--strong">{{ number_format($row['total'], 0, ',', '.') }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ $colspan }}">
                                        <div class="bsi-empty">
                                            <p class="bsi-empty__title">Belum ada dapur pusat aktif</p>
                                            <p>Tambahkan lewat menu Master Data → Dapur Pusat.</p>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                        @if ($snap['kitchens']->isNotEmpty())
                            <tfoot>
                                <tr>
                                    <td class="bsi-name bsi-foot">Subtotal dapur</td>
                                    @foreach ($products as $product)
                                        <td class="bsi-num bsi-num--strong bsi-foot">{{ number_format($snap['kitchen_totals'][$product->id] ?? 0, 0, ',', '.') }}</td>
                                    @endforeach
                                    <td class="bsi-sum-first bsi-num bsi-num--strong bsi-foot">{{ number_format($snap['kitchen_grand'], 0, ',', '.') }}</td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
            </div>

            <div class="bsi-sheet">
                <div class="bsi-sheet__head">
                    <div class="bsi-sheet__head-left">
                        <span class="bsi-kicker">Sesudah dibagikan</span>
                        <h2 class="bsi-title">Stok per kode Coffee Bike</h2>
                    </div>
                    <span class="bsi-serif">{{ number_format($snap['cart_grand'], 0, ',', '.') }} cups</span>
                </div>

                <div class="bsi-scroll">
                    <table class="bsi-grid">
                        <thead>
                            <tr>
                                <th class="bsi-name">Kode Coffee Bike</th>
                                @foreach ($products as $product)
                                    <th>{{ $product->name }}<br><span class="bsi-th-unit">{{ $product->unit }}</span></th>
                                @endforeach
                                <th class="bsi-sum-first">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($snap['carts'] as $row)
                                <tr>
                                    <td class="bsi-name">
                                        {{ $row['cart']->code }}
                                        @if ($row['cart']->status !== 'active')
                                            <span class="bsi-matrix__hint">{{ $row['cart']->status }}</span>
                                        @endif
                                    </td>
                                    @foreach ($products as $product)
                                        @php $qty = $row['qty'][$product->id] ?? 0; @endphp
                                        <td class="bsi-num {{ $qty === 0 ? 'bsi-num--quiet' : '' }}">{{ number_format($qty, 0, ',', '.') }}</td>
                                    @endforeach
                                    <td class="bsi-sum-first bsi-num bsi-num--strong">{{ number_format($row['total'], 0, ',', '.') }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ $colspan }}">
                                        <div class="bsi-empty">
                                            <p class="bsi-empty__title">Belum ada Coffee Bike</p>
                                            <p>Tambahkan lewat menu Master Data → Coffee Bike.</p>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                        @if ($snap['carts']->isNotEmpty())
                            <tfoot>
                                <tr>
                                    <td class="bsi-name bsi-foot">Subtotal Coffee Bike</td>
                                    @foreach ($products as $product)
                                        <td class="bsi-num bsi-num--strong bsi-foot">{{ number_format($snap['cart_totals'][$product->id] ?? 0, 0, ',', '.') }}</td>
                                    @endforeach
                                    <td class="bsi-sum-first bsi-num bsi-num--strong bsi-foot">{{ number_format($snap['cart_grand'], 0, ',', '.') }}</td>
                                </tr>
                                {{-- The grand total is the point of the page, so it gets the
                                     inverted-ink row: the system's emphasis, not a colour. --}}
                                <tr class="bsi-foot-total">
                                    <td class="bsi-name">Total seluruhnya</td>
                                    @foreach ($products as $product)
                                        <td class="bsi-num">{{ number_format($snap['grand_totals'][$product->id] ?? 0, 0, ',', '.') }}</td>
                                    @endforeach
                                    <td class="bsi-num">{{ number_format($snap['grand'], 0, ',', '.') }}</td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
            </div>
        @endif

        {{-- Raw materials sit in their own sheet rather than as extra columns above: they are
             counted in grams and millilitres, not cups, so adding them to the cups grid would put
             two different units under one "Total" column. There is no cart column — the
             raw-material store belongs to a kitchen, and a cart never holds ingredients. --}}
        @if ($rawMaterials->isNotEmpty())
            <div class="bsi-sheet">
                <div class="bsi-sheet__head">
                    <div class="bsi-sheet__head-left">
                        <span class="bsi-kicker">Sebelum diseduh</span>
                        <h2 class="bsi-title">Stok bahan baku di dapur</h2>
                    </div>
                    <span class="bsi-serif">{{ $rawMaterials->count() }} bahan</span>
                </div>

                <div class="bsi-scroll">
                    <table class="bsi-grid">
                        <thead>
                            <tr>
                                <th class="bsi-name">Dapur Pusat</th>
                                @foreach ($rawMaterials as $material)
                                    <th>{{ $material->name }}<br><span class="bsi-th-unit">{{ $material->unit }}</span></th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($snap['raw_material_rows'] as $row)
                                <tr>
                                    <td class="bsi-name">{{ $row['kitchen']->name }}</td>
                                    @foreach ($rawMaterials as $material)
                                        @php
                                            $qty = $row['qty'][$material->id] ?? 0;
                                            $below = $row['below'][$material->id] ?? false;
                                        @endphp
                                        <td class="bsi-num {{ $below ? 'bsi-num--alarm' : ($qty === 0 ? 'bsi-num--quiet' : '') }}">
                                            {{ number_format($qty, 0, ',', '.') }}
                                        </td>
                                    @endforeach
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ 1 + $rawMaterials->count() }}">
                                        <div class="bsi-empty">
                                            <p class="bsi-empty__title">Belum ada dapur pusat aktif</p>
                                            <p>Tambahkan lewat menu Master Data → Dapur Pusat.</p>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                        @if ($snap['raw_material_rows']->isNotEmpty())
                            <tfoot>
                                <tr class="bsi-foot-total">
                                    <td class="bsi-name">Total seluruh dapur</td>
                                    @foreach ($rawMaterials as $material)
                                        <td class="bsi-num">{{ number_format($snap['raw_material_totals'][$material->id] ?? 0, 0, ',', '.') }}</td>
                                    @endforeach
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>

                <div class="bsi-note">
                    <span class="bsi-kicker">Titik pemesanan ulang</span>
                    <p>
                        Angka yang <strong>ditandai</strong> sudah menyentuh atau turun di bawah titik
                        pemesanan ulang yang disetel di Master Data → Bahan Baku. Bahan yang titiknya
                        belum disetel tidak pernah ditandai — ambang yang kosong bukan berarti nol.
                    </p>
                    <p>
                        Bahan baku berkurang otomatis saat Produksi menyeduh, sesuai resep produk yang
                        diseduh, dan bertambah saat purchase order diterima.
                    </p>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
