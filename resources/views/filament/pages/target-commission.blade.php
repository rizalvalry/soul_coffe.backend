<x-filament-panels::page>
    <div class="bsi">
        {{ $this->periodForm }}

        <div class="bsi-sheet">
            <div class="bsi-sheet__head"><h2 class="bsi-title">Komisi Rider</h2></div>
            <div class="bsi-scroll">
            <table class="bsi-grid">
                <thead><tr><th>Rider</th><th>Tier berlaku hari ini</th><th>Cup hari ini</th><th>Komisi hari ini</th><th>Cup periode</th><th>Komisi periode</th></tr></thead>
                <tbody>
                @foreach ($this->rows() as $row)
                    <tr wire:key="commission-rider-{{ $row['id'] }}">
                        <td>{{ $row['name'] }}</td>
                        <td>
                            @forelse ($row['tiers'] as $tier)
                                ≥{{ $tier['min_cups'] }} cup: Rp {{ number_format($tier['amount_per_cup_minor'], 0, ',', '.') }}/cup<br>
                            @empty
                                Belum diatur
                            @endforelse
                        </td>
                        <td class="bsi-num">{{ $row['today_has_closing'] ? number_format($row['today_cups'], 0, ',', '.') : 'Belum Closing' }}</td>
                        <td class="bsi-num">Rp {{ number_format($row['today_commission_minor'], 0, ',', '.') }}</td>
                        <td class="bsi-num">{{ number_format($row['period_cups'], 0, ',', '.') }}</td>
                        <td class="bsi-num">Rp {{ number_format($row['period_commission_minor'], 0, ',', '.') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        </div>

        @if ($this->canManage())
            <x-filament::section heading="Atur tier Rider">
                {{ $this->riderForm }}
                <div class="bsi-toolbar bsi-toolbar--end bsi-commission-actions">
                    <x-filament::button color="gray" type="button" wire:click="loadRider">Muat tier</x-filament::button>
                </div>
                <p class="bsi-commission-intro">Pilih semua Rider tujuan kalau mau terapkan tier sekaligus.</p>
                {{ $this->tierForm }}
                <div class="bsi-toolbar bsi-toolbar--end bsi-commission-actions">
                    <x-filament::button type="button" wire:click="saveTiers">Simpan / terapkan ke Rider pilihan</x-filament::button>
                </div>
                @error('targetRiderIds') <p>{{ $message }}</p> @enderror
                @error('tiers') <p>{{ $message }}</p> @enderror
                @error('tiers.*.min_cups') <p>{{ $message }}</p> @enderror
                @error('tiers.*.amount_per_cup_minor') <p>{{ $message }}</p> @enderror
                {{ $this->copyForm }}
                <div class="bsi-toolbar bsi-toolbar--end bsi-commission-actions">
                    <x-filament::button color="gray" type="button" wire:click="copyTiers">Salin ke Rider pilihan</x-filament::button>
                </div>
                @error('sourceRiderId') <p>{{ $message }}</p> @enderror
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
