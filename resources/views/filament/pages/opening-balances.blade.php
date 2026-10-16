<x-filament-panels::page>
    <div class="bsi">
        @if ($opening = $this->activeOpening())
            <div class="bsi-note">
                <p>Saldo awal sudah diposting pada {{ $opening->entry_date->format('d M Y') }} sebagai
                    <a href="{{ \App\Filament\Resources\Journals\JournalResource::getUrl('view', ['record' => $opening]) }}">{{ $opening->number }}</a>.
                    Untuk menggantinya, balik jurnal tersebut lebih dulu dari daftar Jurnal Umum.</p>
            </div>
        @else
            <div class="bsi-note"><p>Isi saldo per tanggal cutover. Debit dan kredit harus sama. Angka dalam rupiah utuh.</p></div>
            @error('opening') <div class="bsi-note" role="alert">{{ $message }}</div> @enderror
            <div class="bsi-sheet">
                <div class="bsi-toolbar">
                    <div class="bsi-field">
                        <label class="bsi-label" for="opening-date">Tanggal cutover</label>
                        <input id="opening-date" class="bsi-input" type="date" wire:model="entryDate">
                        @error('entry_date') <span role="alert">{{ $message }}</span> @enderror
                    </div>
                </div>
                <div class="bsi-scroll">
                    <table class="bsi-grid">
                        <thead><tr><th>Akun</th><th>Debit (Rp)</th><th>Kredit (Rp)</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($lines as $index => $line)
                                <tr wire:key="opening-line-{{ $index }}">
                                    <td>
                                        <select class="bsi-select" wire:model="lines.{{ $index }}.account_id" aria-label="Akun baris {{ $index + 1 }}">
                                            <option value="">Pilih akun</option>
                                            @foreach ($this->accounts() as $account)
                                                <option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</option>
                                            @endforeach
                                        </select>
                                        @error("lines.$index.account_id") <span role="alert">{{ $message }}</span> @enderror
                                    </td>
                                    <td><input class="bsi-input" type="number" min="0" step="1" wire:model="lines.{{ $index }}.debit_minor" aria-label="Debit baris {{ $index + 1 }}"></td>
                                    <td><input class="bsi-input" type="number" min="0" step="1" wire:model="lines.{{ $index }}.credit_minor" aria-label="Kredit baris {{ $index + 1 }}"></td>
                                    <td><button type="button" class="bsi-btn" wire:click="removeLine({{ $index }})" @disabled(count($lines) <= 2)>Hapus</button></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @error('lines') <span role="alert">{{ $message }}</span> @enderror
                <div class="bsi-toolbar">
                    <button type="button" class="bsi-btn" wire:click="addLine">Tambah Baris</button>
                    <button type="button" class="bsi-btn bsi-btn--ink" wire:click="submit" wire:loading.attr="disabled">Posting Saldo Awal</button>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
