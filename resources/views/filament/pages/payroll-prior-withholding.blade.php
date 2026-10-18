<x-filament-panels::page>
    <p>Isi bulan dan angka dari payroll sebelumnya supaya PPh Desember bisa dihitung lengkap. Sertakan alasan untuk karyawan pindahan atau data dari sistem lama.</p>
    <form wire:submit="save" class="grid gap-3 rounded-xl border p-4 md:grid-cols-2">
        <label>Karyawan <select wire:model="input.user_id" class="rounded border p-2"><option value="">Pilih</option>
            @foreach ($this->employees() as $employee)<option value="{{ $employee->id }}">{{ $employee->name }}</option>@endforeach
        </select></label>
        <label>Tahun <input type="number" wire:model="input.year" class="rounded border p-2"></label>
        <label>Bulan yang dicakup (contoh: 1,2,3) <input type="text" wire:model="input.months" class="rounded border p-2"></label>
        <label>Bruto kena pajak bulan tersebut (Rp) <input type="number" min="0" wire:model="input.gross_minor" class="rounded border p-2"></label>
        <label>JHT/JP pegawai bulan tersebut (Rp) <input type="number" min="0" wire:model="input.jht_jp_minor" class="rounded border p-2"></label>
        <label>PPh sudah dipotong (Rp) <input type="number" min="0" wire:model="input.amount_minor" class="rounded border p-2"></label>
        <label>Alasan <textarea wire:model="input.reason" class="rounded border p-2"></textarea></label>
        @foreach (['user_id', 'year', 'months', 'gross_minor', 'jht_jp_minor', 'amount_minor', 'reason'] as $field)
            @error('input.'.$field) <span class="text-red-600">{{ $message }}</span> @enderror
        @endforeach
        <button type="submit" class="rounded bg-primary-600 p-2 text-white">Simpan</button>
    </form>
</x-filament-panels::page>
