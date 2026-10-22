<x-filament-panels::page>
    @php($editable = $this->canEdit())
    <div class="space-y-6">
        <x-filament::section heading="Bawaan" description="Berlaku kalau radius dapur atau titik jualan belum diisi. Perubahan langsung dipakai tanpa deploy.">
            <form wire:submit="saveDefaults" class="space-y-4">
                @error('defaults') <p class="text-red-600">{{ $message }}</p> @enderror
                <div class="grid gap-4 md:grid-cols-3">
                    <label>Radius bawaan absen (m)
                        <input type="number" min="5" max="5000" wire:model="defaults.absen_radius_m" @disabled(! $editable) class="w-full rounded-lg border p-2 dark:bg-gray-900">
                        @error('absen_radius_m') <span class="text-red-600">{{ $message }}</span> @enderror
                    </label>
                    <label>Radius CustomerApps (m)
                        <input type="number" min="100" max="20000" wire:model="defaults.customer_radius_m" @disabled(! $editable) class="w-full rounded-lg border p-2 dark:bg-gray-900">
                        @error('customer_radius_m') <span class="text-red-600">{{ $message }}</span> @enderror
                    </label>
                    <label class="flex items-center gap-2"><input type="checkbox" wire:model="defaults.absen_requires_gps" @disabled(! $editable)> Absen wajib GPS</label>
                </div>
                @if($editable)
                    <label class="block">Alasan perubahan (minimal 5 karakter)
                        <textarea wire:model="defaultReason" class="w-full rounded-lg border p-2 dark:bg-gray-900"></textarea>
                        @error('reason') <span class="text-red-600">{{ $message }}</span> @enderror
                    </label>
                    <x-filament::button type="submit">Simpan bawaan</x-filament::button>
                @endif
            </form>
        </x-filament::section>

        <x-filament::section heading="Dapur Pusat" description="Absen Produksi dan Rider di dapur ini terdampak. Radius dapur juga dipakai saat Closing dan status di dapur.">
            <div class="overflow-x-auto"><table class="w-full text-left"><thead><tr><th class="p-2">Dapur</th><th class="p-2">Titik peta</th><th class="p-2">Radius absen (m)</th><th class="p-2">Alasan</th><th class="p-2"></th></tr></thead><tbody>
                @foreach(\App\Models\CentralKitchen::query()->where('is_active', true)->orderBy('name')->get() as $kitchen)
                    <tr wire:key="kitchen-{{ $kitchen->id }}" class="border-t">
                        <td class="p-2">{{ $kitchen->name }}</td>
                        <td class="p-2">{{ $kitchen->lat !== null && $kitchen->lng !== null ? 'Sudah diisi' : 'Belum diisi' }}</td>
                        <td class="p-2"><input type="number" min="5" max="5000" wire:model="kitchenRadii.{{ $kitchen->id }}" @disabled(! $editable || ($kitchenUseDefault[$kitchen->id] ?? false)) class="w-28 rounded-lg border p-2 dark:bg-gray-900">
                            <label class="block"><input type="checkbox" wire:model.live="kitchenUseDefault.{{ $kitchen->id }}" @disabled(! $editable)> Pakai bawaan</label></td>
                        <td class="p-2">@if($editable)<input type="text" minlength="5" wire:model="kitchenReasons.{{ $kitchen->id }}" placeholder="Alasan perubahan" class="w-full rounded-lg border p-2 dark:bg-gray-900">@endif</td>
                        <td class="p-2">@if($editable)<x-filament::button size="sm" wire:click="saveKitchen({{ $kitchen->id }})">Simpan</x-filament::button>@endif</td>
                    </tr>
                @endforeach
            </tbody></table></div>
            @error('radius') <span class="text-red-600">{{ $message }}</span> @enderror
            @error('reason') <span class="text-red-600">{{ $message }}</span> @enderror
        </x-filament::section>

        <x-filament::section heading="Titik jualan" description="Absen dan Closing Rider di titik ini terdampak.">
            <div class="overflow-x-auto"><table class="w-full text-left"><thead><tr><th class="p-2">Lokasi</th><th class="p-2">Titik peta</th><th class="p-2">Radius (m)</th><th class="p-2">Alasan</th><th class="p-2"></th></tr></thead><tbody>
                @foreach(\App\Models\Location::query()->orderBy('name')->get() as $location)
                    <tr wire:key="location-{{ $location->id }}" class="border-t">
                        <td class="p-2">{{ $location->name }}</td>
                        <td class="p-2">{{ $location->lat !== null && $location->lng !== null ? 'Sudah diisi' : 'Belum diisi' }}</td>
                        <td class="p-2"><input type="number" min="5" max="5000" wire:model="locationRadii.{{ $location->id }}" @disabled(! $editable || ($locationUseDefault[$location->id] ?? false)) class="w-28 rounded-lg border p-2 dark:bg-gray-900">
                            <label class="block"><input type="checkbox" wire:model.live="locationUseDefault.{{ $location->id }}" @disabled(! $editable)> Pakai bawaan</label></td>
                        <td class="p-2">@if($editable)<input type="text" minlength="5" wire:model="locationReasons.{{ $location->id }}" placeholder="Alasan perubahan" class="w-full rounded-lg border p-2 dark:bg-gray-900">@endif</td>
                        <td class="p-2">@if($editable)<x-filament::button size="sm" wire:click="saveLocation({{ $location->id }})">Simpan</x-filament::button>@endif</td>
                    </tr>
                @endforeach
            </tbody></table></div>
            @error('radius') <span class="text-red-600">{{ $message }}</span> @enderror
            @error('reason') <span class="text-red-600">{{ $message }}</span> @enderror
        </x-filament::section>
    </div>
</x-filament-panels::page>
