{{--
    Penamaan Menu, in the BSI Black & White system (see public/css/bsi-bw.css).

    One row per menu: the built-in name on the left as a reference, the editable name on the
    right. Blanking a field is how you go back to the built-in name, which is why the built-in one
    stays visible rather than being replaced by whatever was typed over it.
--}}
<x-filament-panels::page>
    <div class="bsi">
        <div class="bsi-note">
            <span class="bsi-kicker">Yang berubah, dan yang tidak</span>
            <p>
                Halaman ini <strong>hanya mengubah nama yang tampil</strong>. Kunci setiap menu
                (<em>carts</em>, <em>sales</em>, <em>attendance</em>, …) tetap sama — itulah yang
                dipakai oleh hak akses, alamat halaman, dan seluruh alur bisnis. Jadi mengganti
                “Gerobak” menjadi “Armada” tidak memutus satu pun izin yang sudah diberikan dan
                tidak mengubah apa pun pada proses refill, absensi, atau penjualan.
            </p>
            <p>
                Kosongkan sebuah kolom untuk mengembalikannya ke nama bawaan. Nama bawaannya selalu
                terlihat di kolom kiri.
            </p>
        </div>

        <div class="bsi-sheet">
            <div class="bsi-sheet__head">
                <div class="bsi-sheet__head-left">
                    <span class="bsi-kicker">Judul kelompok di sidebar</span>
                    <h2 class="bsi-title">Kelompok menu</h2>
                </div>
                <span class="bsi-serif">{{ count($this->groups) }} kelompok</span>
            </div>

            <div class="bsi-scroll">
                <table class="bsi-grid">
                    <thead>
                        <tr>
                            <th class="bsi-name">Nama bawaan</th>
                            <th>Nama yang dipakai</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->groups as $group => $label)
                            <tr>
                                <td class="bsi-name">{{ $group }}</td>
                                <td>
                                    <input
                                        type="text"
                                        class="bsi-input"
                                        maxlength="120"
                                        wire:model="groups.{{ $group }}"
                                        placeholder="{{ $group }}"
                                        aria-label="Nama untuk kelompok {{ $group }}"
                                    >
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
                    <span class="bsi-kicker">Setiap menu di panel</span>
                    <h2 class="bsi-title">Nama menu</h2>
                </div>
                <span class="bsi-serif">{{ count($this->modules) }} menu</span>
            </div>

            <div class="bsi-scroll">
                <table class="bsi-grid">
                    <thead>
                        <tr>
                            <th class="bsi-name">Nama bawaan</th>
                            <th>Kunci (tidak berubah)</th>
                            <th>Nama yang dipakai</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->modules as $key => $label)
                            <tr>
                                <td class="bsi-name">{{ $this->defaultFor($key) }}</td>
                                <td><span class="bsi-key">{{ $key }}</span></td>
                                <td>
                                    <input
                                        type="text"
                                        class="bsi-input"
                                        maxlength="120"
                                        wire:model="modules.{{ $key }}"
                                        placeholder="{{ $this->defaultFor($key) }}"
                                        aria-label="Nama untuk menu {{ $this->defaultFor($key) }}"
                                    >
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="bsi-toolbar">
            <button type="button" class="bsi-btn bsi-btn--ink" wire:click="save" wire:loading.attr="disabled">
                Simpan penamaan
            </button>

            <button
                type="button"
                class="bsi-btn"
                wire:click="resetAll"
                wire:confirm="Kembalikan semua nama menu ke bawaan aplikasi?"
                wire:loading.attr="disabled"
            >
                Kembalikan semua ke bawaan
            </button>

            <div class="bsi-toolbar__spacer"></div>

            <span class="bsi-muted" wire:loading>Menyimpan…</span>
        </div>
    </div>
</x-filament-panels::page>
