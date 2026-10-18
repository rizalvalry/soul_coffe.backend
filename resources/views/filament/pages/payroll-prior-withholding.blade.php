<x-filament-panels::page>
    <p>Isi PPh yang sudah dipotong dari payroll sebelumnya. Sertakan alasan, misalnya data dari sistem lama.</p>

    <form wire:submit="save">
        {{ $this->form }}
        <div class="bsi-toolbar bsi-toolbar--end">
            <button type="submit" class="bsi-btn bsi-btn--ink" wire:loading.attr="disabled">Simpan</button>
        </div>
    </form>

    <div class="bsi">
        <div class="bsi-sheet">
            <div class="bsi-sheet__head"><h2 class="bsi-title">Data yang sudah disimpan</h2><span class="bsi-muted">Geser tabel ke samping buat lihat detailnya.</span></div>
            <div class="bsi-scroll">
                {{ $this->table }}
            </div>
        </div>
    </div>
</x-filament-panels::page>
