<x-filament-panels::page>
    <div class="bsi">
        @can ('manage-accounting-periods')
        <div>
            {{ $this->periodForm }}
            <div class="bsi-sheet">
                <div class="bsi-sheet__head"><h2 class="bsi-title">Pemeriksaan sebelum tutup periode</h2></div>
                <ul class="p-4 space-y-2">
                    @foreach ($this->readiness() as $item)
                        <li>
                            <strong>{{ match ($item['status']) { 'blocking' => 'Perlu dibereskan', 'warning' => 'Perlu dicek', default => 'Oke' } }}</strong>
                            · {{ $item['description'] }}
                            <a href="{{ $this->readinessUrl($item['code']) }}" class="underline">Buka daftar</a>
                        </li>
                    @endforeach
                </ul>
            </div>
            <div class="bsi-toolbar bsi-toolbar--end"><x-filament::button type="button" wire:click="closeSelected" wire:loading.attr="disabled">Tutup Periode</x-filament::button></div>
        </div>
        @endcan
        <div class="bsi-sheet">
            <div class="bsi-sheet__head"><h2 class="bsi-title">Riwayat periode</h2></div>
            <div class="bsi-scroll">{{ $this->table }}</div>
        </div>
    </div>
</x-filament-panels::page>
