{{--
    Stok Terpusat, in the BSI Black & White system (see public/css/bsi-bw.css).

    Read-only by nature: stock moves by brewing, handing over, refilling and closing out. A
    number typed into a report would be a second way to change stock that the append-only
    ledger (R6) could not explain.

    Every class here is a `bsi-` class from our own stylesheet — this project has no panel build
    step, so a Tailwind utility written here would simply never apply.
--}}
<x-filament-panels::page>
    @php
        $snap = $this->snapshot();
        $products = $snap['products'];
        $colspan = 2 + $products->count();
    @endphp

    <div class="bsi">
        {{-- The three numbers the question is really about: how many cups exist, how many are
             still at the kitchen, how many are already out on carts. --}}
        <div class="bsi-stat-row">
            <div class="bsi-stat">
                <span class="bsi-kicker">Total seluruh cups</span>
                <span class="bsi-stat__value">{{ number_format($snap['grand'], 0, ',', '.') }}</span>
                <span class="bsi-stat__hint">dapur + seluruh gerobak</span>
            </div>
            <div class="bsi-stat">
                <span class="bsi-kicker">Masih di dapur</span>
                <span class="bsi-stat__value">{{ number_format($snap['kitchen_grand'], 0, ',', '.') }}</span>
                <span class="bsi-stat__hint">belum dibagikan ke gerobak</span>
            </div>
            <div class="bsi-stat">
                <span class="bsi-kicker">Sudah di gerobak</span>
                <span class="bsi-stat__value">{{ number_format($snap['cart_grand'], 0, ',', '.') }}</span>
                <span class="bsi-stat__hint">tersebar di gerobak aktif</span>
            </div>
        </div>

        <div class="bsi-note">
            <span class="bsi-kicker">Cara membacanya</span>
            <p>
                Angka di sini <strong>dihitung dari buku besar stok</strong>, bukan dari penghitung
                yang bisa diketik — jadi tidak mungkin berbeda dari pergerakan yang membentuknya.
                Stok bergerak lewat aktivitas: barista menyeduh (Add Stock), menyerahkan ke gerobak,
                refill, dan tutup gerobak.
            </p>
            <p>
                Gerobak berstatus <em>maintenance</em> tetap dihitung — cups bisa saja masih ada di
                atasnya. Yang berstatus <em>retired</em> tidak lagi ditampilkan.
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
                        <h2 class="bsi-title">Stok per kode gerobak</h2>
                    </div>
                    <span class="bsi-serif">{{ number_format($snap['cart_grand'], 0, ',', '.') }} cups</span>
                </div>

                <div class="bsi-scroll">
                    <table class="bsi-grid">
                        <thead>
                            <tr>
                                <th class="bsi-name">Kode Gerobak</th>
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
                                            <p class="bsi-empty__title">Belum ada gerobak</p>
                                            <p>Tambahkan lewat menu Master Data → Gerobak.</p>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                        @if ($snap['carts']->isNotEmpty())
                            <tfoot>
                                <tr>
                                    <td class="bsi-name bsi-foot">Subtotal gerobak</td>
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
    </div>
</x-filament-panels::page>
