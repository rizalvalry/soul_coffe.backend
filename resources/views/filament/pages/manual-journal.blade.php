<x-filament-panels::page>
    <div class="bsi">
        <div class="bsi-note"><p>Isi minimal dua baris. Jumlah debit dan kredit harus sama, dalam rupiah utuh.</p></div>
        <div class="bsi-sheet">
            <div class="bsi-toolbar">
                <div class="bsi-field">
                    <label class="bsi-label" for="journal-date">Tanggal</label>
                    <input id="journal-date" class="bsi-input" type="date" wire:model="entryDate">
                    @error('entry_date') <span role="alert">{{ $message }}</span> @enderror
                </div>
                <div class="bsi-field">
                    <label class="bsi-label" for="journal-description">Keterangan</label>
                    <input id="journal-description" class="bsi-input" type="text" maxlength="255" wire:model="description">
                    @error('description') <span role="alert">{{ $message }}</span> @enderror
                </div>
            </div>
            <div class="bsi-scroll">
                @php($accounts = $this->accounts())
                @php($carts = $this->carts())
                <table class="bsi-grid">
                    <thead><tr><th>Akun</th><th>Debit (Rp)</th><th>Kredit (Rp)</th><th>Coffee Bike (opsional)</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($lines as $index => $line)
                            <tr wire:key="manual-line-{{ $index }}">
                                <td>
                                    <select class="bsi-select" wire:model="lines.{{ $index }}.account_id" aria-label="Akun baris {{ $index + 1 }}">
                                        <option value="">Pilih akun</option>
                                        @foreach ($accounts as $account)
                                            <option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</option>
                                        @endforeach
                                    </select>
                                    @error("lines.$index.account_id") <span role="alert">{{ $message }}</span> @enderror
                                    @error("lines.$index") <span role="alert">{{ $message }}</span> @enderror
                                </td>
                                <td>
                                    <input class="bsi-input" type="number" min="0" step="1" wire:model.live.debounce.150ms="lines.{{ $index }}.debit_minor" aria-label="Debit baris {{ $index + 1 }}">
                                    @error("lines.$index.debit_minor") <span role="alert">{{ $message }}</span> @enderror
                                </td>
                                <td>
                                    <input class="bsi-input" type="number" min="0" step="1" wire:model.live.debounce.150ms="lines.{{ $index }}.credit_minor" aria-label="Kredit baris {{ $index + 1 }}">
                                    @error("lines.$index.credit_minor") <span role="alert">{{ $message }}</span> @enderror
                                </td>
                                <td>
                                    <select class="bsi-select" wire:model="lines.{{ $index }}.cart_id" aria-label="Coffee Bike baris {{ $index + 1 }}">
                                        <option value="">Tanpa Coffee Bike</option>
                                        @foreach ($carts as $cart)
                                            <option value="{{ $cart->id }}">{{ $cart->code }}</option>
                                        @endforeach
                                    </select>
                                    @error("lines.$index.cart_id") <span role="alert">{{ $message }}</span> @enderror
                                </td>
                                <td><button type="button" class="bsi-btn" wire:click="removeLine({{ $index }})" @disabled(count($lines) <= 2)>Hapus</button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @error('lines') <div class="bsi-note" role="alert">{{ $message }}</div> @enderror
            <div class="bsi-toolbar">
                <div>Debit: <strong>Rp {{ number_format($this->total('debit_minor'), 0, ',', '.') }}</strong></div>
                <div>Kredit: <strong>Rp {{ number_format($this->total('credit_minor'), 0, ',', '.') }}</strong></div>
                <div role="status">{{ $this->total('debit_minor') === 0 ? 'Isi nominal dulu' : ($this->total('debit_minor') === $this->total('credit_minor') ? 'Seimbang' : 'Belum seimbang') }}</div>
                <button type="button" class="bsi-btn" wire:click="addLine" @disabled(count($lines) >= 1000)>Tambah Baris</button>
                <button type="button" class="bsi-btn bsi-btn--ink" wire:click="submit" wire:loading.attr="disabled">Posting Jurnal</button>
            </div>
        </div>
    </div>
</x-filament-panels::page>
