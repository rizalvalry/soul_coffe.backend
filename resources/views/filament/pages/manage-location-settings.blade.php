<x-filament-panels::page>
    @php($editable = $this->canEdit())
    <div class="bsi-cms-page">
        <x-filament::section heading="Bawaan" description="Berlaku kalau radius dapur atau titik jualan belum diisi. Perubahan langsung dipakai tanpa deploy.">
            <form wire:submit="saveDefaults" class="bsi-cms-form">
                @error('defaults') <p class="bsi-cms-danger">{{ $message }}</p> @enderror
                <div class="bsi-cms-form-grid">
                    <label class="bsi-cms-form-field">Radius bawaan absen (m)
                        <input type="number" min="5" max="5000" wire:model="defaults.absen_radius_m" @disabled(! $editable) class="bsi-input">
                        @error('absen_radius_m') <span class="bsi-cms-danger">{{ $message }}</span> @enderror
                    </label>
                    <label class="bsi-cms-form-field">Radius CustomerApps (m)
                        <input type="number" min="100" max="20000" wire:model="defaults.customer_radius_m" @disabled(! $editable) class="bsi-input">
                        @error('customer_radius_m') <span class="bsi-cms-danger">{{ $message }}</span> @enderror
                    </label>
                    <label class="bsi-cms-check"><input type="checkbox" wire:model="defaults.absen_requires_gps" @disabled(! $editable)> Absen wajib GPS</label>
                </div>
                @if($editable)
                    <label class="bsi-cms-form-field">Alasan perubahan (minimal 5 karakter)
                        <textarea wire:model="defaultReason" class="bsi-input"></textarea>
                        @error('reason') <span class="bsi-cms-danger">{{ $message }}</span> @enderror
                    </label>
                    <x-filament::button type="submit">Simpan bawaan</x-filament::button>
                @endif
            </form>
        </x-filament::section>

        <x-filament::section heading="Dapur Pusat" description="Absen Produksi dan Rider di dapur ini terdampak. Radius dapur juga dipakai saat Closing dan status di dapur.">
            <div class="bsi-cms-table-scroll"><table class="bsi-cms-table"><thead><tr><th>Dapur</th><th>Titik peta</th><th>Radius absen (m)</th><th>Alasan</th><th></th></tr></thead><tbody>
                @foreach(\App\Models\CentralKitchen::query()->where('is_active', true)->orderBy('name')->get() as $kitchen)
                    <tr wire:key="kitchen-{{ $kitchen->id }}">
                        <td>{{ $kitchen->name }}</td>
                        <td>{{ $kitchen->lat !== null && $kitchen->lng !== null ? 'Sudah diisi' : 'Belum diisi' }}</td>
                        <td><input type="number" min="5" max="5000" wire:model="kitchenRadii.{{ $kitchen->id }}" @disabled(! $editable || ($kitchenUseDefault[$kitchen->id] ?? false)) class="bsi-input bsi-cms-radius">
                            <label class="bsi-cms-check"><input type="checkbox" wire:model.live="kitchenUseDefault.{{ $kitchen->id }}" @disabled(! $editable)> Pakai bawaan</label></td>
                        <td>@if($editable)<input type="text" minlength="5" wire:model="kitchenReasons.{{ $kitchen->id }}" placeholder="Alasan perubahan" class="bsi-input bsi-cms-reason">@endif</td>
                        <td>@if($editable)<x-filament::button size="sm" wire:click="saveKitchen({{ $kitchen->id }})">Simpan</x-filament::button>@endif</td>
                    </tr>
                @endforeach
            </tbody></table></div>
            @error('radius') <span class="bsi-cms-danger">{{ $message }}</span> @enderror
            @error('reason') <span class="bsi-cms-danger">{{ $message }}</span> @enderror
        </x-filament::section>

        <x-filament::section heading="Titik jualan" description="Absen dan Closing Rider di titik ini terdampak.">
            <div class="bsi-cms-table-scroll"><table class="bsi-cms-table"><thead><tr><th>Lokasi</th><th>Titik peta</th><th>Radius (m)</th><th>Alasan</th><th></th></tr></thead><tbody>
                @foreach(\App\Models\Location::query()->orderBy('name')->get() as $location)
                    <tr wire:key="location-{{ $location->id }}">
                        <td>{{ $location->name }}</td>
                        <td>{{ $location->lat !== null && $location->lng !== null ? 'Sudah diisi' : 'Belum diisi' }}</td>
                        <td><input type="number" min="5" max="5000" wire:model="locationRadii.{{ $location->id }}" @disabled(! $editable || ($locationUseDefault[$location->id] ?? false)) class="bsi-input bsi-cms-radius">
                            <label class="bsi-cms-check"><input type="checkbox" wire:model.live="locationUseDefault.{{ $location->id }}" @disabled(! $editable)> Pakai bawaan</label></td>
                        <td>@if($editable)<input type="text" minlength="5" wire:model="locationReasons.{{ $location->id }}" placeholder="Alasan perubahan" class="bsi-input bsi-cms-reason">@endif</td>
                        <td>@if($editable)<x-filament::button size="sm" wire:click="saveLocation({{ $location->id }})">Simpan</x-filament::button>@endif</td>
                    </tr>
                @endforeach
            </tbody></table></div>
            @error('radius') <span class="bsi-cms-danger">{{ $message }}</span> @enderror
            @error('reason') <span class="bsi-cms-danger">{{ $message }}</span> @enderror
        </x-filament::section>
    </div>
</x-filament-panels::page>
