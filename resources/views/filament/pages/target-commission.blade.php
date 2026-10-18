<x-filament-panels::page>
    <div class="bsi">
        <div class="bsi-toolbar">
            <div class="bsi-field">
                <label class="bsi-label" for="commission-period">Periode</label>
                <input id="commission-period" class="bsi-input" type="month" wire:model.live="period">
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="bsi-table" style="width:100%">
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
                        <td>{{ $row['today_has_closing'] ? $row['today_cups'] : 'Belum Closing' }}</td>
                        <td>Rp {{ number_format($row['today_commission_minor'], 0, ',', '.') }}</td>
                        <td>{{ $row['period_cups'] }}</td>
                        <td>Rp {{ number_format($row['period_commission_minor'], 0, ',', '.') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        @if ($this->canManage())
            <x-filament::section heading="Atur tier Rider">
                <div class="bsi-field">
                    <label class="bsi-label" for="commission-date">Berlaku mulai</label>
                    <input id="commission-date" class="bsi-input" type="date" wire:model="effectiveFrom">
                    @error('effectiveFrom') <p>{{ $message }}</p> @enderror
                </div>
                <div class="bsi-field">
                    <label class="bsi-label" for="commission-rider">Rider yang mau diatur</label>
                    <select id="commission-rider" class="bsi-input" wire:model="selectedRiderId">
                        <option value="">Pilih Rider</option>
                        @foreach ($this->riders() as $rider)
                            <option value="{{ $rider->id }}">{{ $rider->name }}</option>
                        @endforeach
                    </select>
                    <button type="button" class="bsi-btn" wire:click="loadRider">Muat tier</button>
                </div>
                <p>Pilih semua Rider tujuan kalau mau terapkan tier sekaligus.</p>
                <div style="display:flex;gap:1rem;flex-wrap:wrap">
                    @foreach ($this->riders() as $rider)
                        <label><input type="checkbox" value="{{ $rider->id }}" wire:model="targetRiderIds"> {{ $rider->name }}</label>
                    @endforeach
                </div>
                @error('targetRiderIds') <p>{{ $message }}</p> @enderror
                @foreach ($tiers as $index => $tier)
                    <div style="display:flex;gap:1rem;align-items:end" wire:key="tier-{{ $index }}">
                        <div class="bsi-field"><label class="bsi-label">Mulai cup</label><input class="bsi-input" type="number" min="1" step="1" wire:model="tiers.{{ $index }}.min_cups"></div>
                        <div class="bsi-field"><label class="bsi-label">Rp per cup</label><input class="bsi-input" type="number" min="0" step="1" wire:model="tiers.{{ $index }}.amount_per_cup_minor"></div>
                        <button type="button" class="bsi-btn" wire:click="removeTier({{ $index }})">Hapus baris</button>
                    </div>
                @endforeach
                @error('tiers') <p>{{ $message }}</p> @enderror
                @error('tiers.*.min_cups') <p>{{ $message }}</p> @enderror
                @error('tiers.*.amount_per_cup_minor') <p>{{ $message }}</p> @enderror
                <button type="button" class="bsi-btn" wire:click="addTier">Tambah tier</button>
                <button type="button" class="bsi-btn" wire:click="saveTiers">Simpan / terapkan ke Rider pilihan</button>
                <div class="bsi-field">
                    <label class="bsi-label" for="commission-source">Salin tier dari Rider lain</label>
                    <select id="commission-source" class="bsi-input" wire:model="sourceRiderId">
                        <option value="">Pilih Rider sumber</option>
                        @foreach ($this->riders() as $rider)
                            <option value="{{ $rider->id }}">{{ $rider->name }}</option>
                        @endforeach
                    </select>
                    <button type="button" class="bsi-btn" wire:click="copyTiers">Salin ke Rider pilihan</button>
                    @error('sourceRiderId') <p>{{ $message }}</p> @enderror
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
