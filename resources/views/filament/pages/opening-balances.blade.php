<x-filament-panels::page>
    <div class="bsi">
        @if ($opening = $this->activeOpening())
            <div class="bsi-note">
                <p>Saldo awal sudah diposting pada {{ $opening->entry_date->format('d M Y') }} sebagai
                    <a href="{{ \App\Filament\Resources\Journals\JournalResource::getUrl('view', ['record' => $opening]) }}">{{ $opening->number }}</a>.
                    @can ('manage-accounting-periods')
                        Untuk menggantinya, balik jurnal tersebut lebih dulu dari daftar Jurnal Umum.
                    @endcan</p>
            </div>
        @else
            @can ('manage-accounting-periods')
                <div class="bsi-note"><p>Isi saldo per tanggal cutover. Debit dan kredit harus sama. Angka dalam rupiah utuh.</p></div>
                @error('opening') <div class="bsi-note" role="alert">{{ $message }}</div> @enderror
                {{ $this->form }}
                @error('entry_date') <div class="bsi-note" role="alert">{{ $message }}</div> @enderror
                @error('lines') <div class="bsi-note" role="alert">{{ $message }}</div> @enderror
                <div class="bsi-toolbar bsi-toolbar--end">
                    <x-filament::button type="button" wire:click="submit" wire:loading.attr="disabled">Posting Saldo Awal</x-filament::button>
                </div>
            @else
                <div class="bsi-note"><p>Saldo awal belum diposting.</p></div>
            @endcan
        @endif
    </div>
</x-filament-panels::page>
