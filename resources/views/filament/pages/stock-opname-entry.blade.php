{{--
    Buat Stock Opname, in the BSI Black & White system (see public/css/bsi-bw.css).

    Three steps in order: pick where the count happened, load what the ledger currently says,
    type what was actually counted. The counted quantity starts equal to the system quantity for
    every row, so typing is only needed where reality disagrees.
--}}
<x-filament-panels::page>
    <div class="bsi">
        <div class="bsi-note">
            <span class="bsi-kicker">Cara kerja</span>
            <p>
                Pilih lokasi yang baru saja dihitung fisiknya, lalu <strong>Muat Stok</strong>.
                Setiap produk aktif akan tampil dengan angka yang tercatat di sistem saat ini.
                Ubah hanya angka yang berbeda dari hasil hitung fisik — yang sama boleh dibiarkan.
            </p>
            <p>
                Menyimpan di sini <strong>belum mengubah stok apa pun</strong>. Tersimpan sebagai
                draf dulu; koreksinya baru diposting ke buku besar stok setelah draf ini
                <strong>diterapkan</strong> dari menu Stock Opname — dan pada saat diterapkan,
                sistem membandingkan hitungan ini terhadap stok yang berjalan saat itu juga, bukan
                terhadap angka yang tersimpan di sini, kalau-kalau ada transaksi lain di antaranya.
            </p>
        </div>

        <div class="bsi-sheet">
            <div class="bsi-sheet__head">
                <div class="bsi-sheet__head-left">
                    <span class="bsi-kicker">Langkah 1</span>
                    <h2 class="bsi-title">Lokasi yang dihitung</h2>
                </div>
            </div>

            <div class="bsi-toolbar">
                <div class="bsi-field">
                    <label class="bsi-label" for="bsi-opname-kind">Tipe Lokasi</label>
                    <select id="bsi-opname-kind" class="bsi-select" wire:model.live="locationKind">
                        <option value="">— pilih —</option>
                        <option value="kitchen">Dapur Pusat</option>
                        <option value="cart">Gerobak</option>
                    </select>
                </div>

                @if ($this->locationKind === 'kitchen')
                    <div class="bsi-field">
                        <label class="bsi-label" for="bsi-opname-location">Dapur</label>
                        <select id="bsi-opname-location" class="bsi-select" wire:model.live="locationId">
                            <option value="">— pilih dapur —</option>
                            @foreach ($this->kitchenOptions() as $option)
                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                @elseif ($this->locationKind === 'cart')
                    <div class="bsi-field">
                        <label class="bsi-label" for="bsi-opname-location">Gerobak</label>
                        <select id="bsi-opname-location" class="bsi-select" wire:model.live="locationId">
                            <option value="">— pilih gerobak —</option>
                            @foreach ($this->cartOptions() as $option)
                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <div class="bsi-toolbar__spacer"></div>

                <button
                    type="button"
                    class="bsi-btn bsi-btn--ink"
                    wire:click="loadLocation"
                    wire:loading.attr="disabled"
                    @disabled($this->locationKind === '' || ! $this->locationId)
                >
                    Muat Stok
                </button>
            </div>
        </div>

        @if ($this->loaded)
            <div class="bsi-sheet">
                <div class="bsi-sheet__head">
                    <div class="bsi-sheet__head-left">
                        <span class="bsi-kicker">Langkah 2</span>
                        <h2 class="bsi-title">Hasil hitung fisik</h2>
                    </div>
                    <span class="bsi-serif">{{ count($this->lines) }} produk</span>
                </div>

                <div class="bsi-scroll">
                    <table class="bsi-grid">
                        <thead>
                            <tr>
                                <th class="bsi-name">Produk</th>
                                <th>Tercatat di sistem</th>
                                <th>Hasil hitung fisik</th>
                                <th>Selisih</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->lines as $index => $line)
                                @php $variance = ($line['counted_qty'] ?? 0) - $line['system_qty']; @endphp
                                <tr>
                                    <td class="bsi-name">{{ $line['product_name'] }}</td>
                                    <td class="bsi-num">{{ $line['system_qty'] }} {{ $line['unit'] }}</td>
                                    <td>
                                        <input
                                            type="number"
                                            min="0"
                                            class="bsi-input"
                                            wire:model.live="lines.{{ $index }}.counted_qty"
                                            aria-label="Hasil hitung {{ $line['product_name'] }}"
                                        >
                                    </td>
                                    <td class="bsi-num @if ($variance !== 0) bsi-num--alarm @endif">
                                        {{ $variance > 0 ? '+' : '' }}{{ $variance }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="bsi-sheet">
                <div class="bsi-sheet__head">
                    <div class="bsi-sheet__head-left">
                        <span class="bsi-kicker">Langkah 3</span>
                        <h2 class="bsi-title">Alasan</h2>
                    </div>
                </div>

                <div class="bsi-field" style="max-width: none;">
                    <label class="bsi-label" for="bsi-opname-reason">Kenapa hitungan ini dilakukan</label>
                    <textarea
                        id="bsi-opname-reason"
                        class="bsi-input"
                        rows="2"
                        maxlength="500"
                        wire:model="reason"
                        placeholder="Mis. hitungan rutin akhir pekan, dugaan kekurangan di gerobak 0018, serah terima antar barista"
                    ></textarea>
                </div>

                <div class="bsi-toolbar">
                    <div class="bsi-toolbar__spacer"></div>
                    <button type="button" class="bsi-btn bsi-btn--ink" wire:click="submit" wire:loading.attr="disabled">
                        Simpan sebagai Draf
                    </button>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
