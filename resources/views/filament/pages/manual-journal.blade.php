<x-filament-panels::page>
    <div class="bsi">
        <div class="bsi-note"><p>Isi minimal dua baris. Jumlah debit dan kredit harus sama, dalam rupiah utuh.</p></div>
        {{ $this->form }}
        @foreach (['entry_date', 'description', 'lines'] as $field)
            @error($field) <div class="bsi-note" role="alert">{{ $message }}</div> @enderror
        @endforeach
        @foreach ($lines as $index => $line)
            @error("lines.$index") <div class="bsi-note" role="alert">Baris {{ $index + 1 }}: {{ $message }}</div> @enderror
        @endforeach
        <div class="bsi-sheet">
            <div class="bsi-toolbar bsi-toolbar--end">
                <div>Debit: <strong>Rp {{ number_format($this->total('debit_minor'), 0, ',', '.') }}</strong></div>
                <div>Kredit: <strong>Rp {{ number_format($this->total('credit_minor'), 0, ',', '.') }}</strong></div>
                <div role="status">{{ $this->total('debit_minor') === 0 ? 'Isi nominal dulu' : ($this->total('debit_minor') === $this->total('credit_minor') ? 'Seimbang' : 'Belum seimbang') }}</div>
                <x-filament::button type="button" wire:click="submit" wire:loading.attr="disabled">Posting Jurnal</x-filament::button>
            </div>
        </div>
    </div>
</x-filament-panels::page>
