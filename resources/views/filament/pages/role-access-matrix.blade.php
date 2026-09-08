{{--
    One role at a time, on purpose: the full 6-roles × 15-modules × 4-abilities grid is 360
    checkboxes on one screen, which is a screen nobody reads carefully. Picking the role first
    turns the same job into a short, checkable list.
--}}
<x-filament-panels::page>
    <div class="max-w-xl">
        <label for="role" class="block text-sm font-medium text-gray-950 dark:text-white">Peran</label>
        <select
            id="role"
            wire:model.live="role"
            class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm dark:border-white/20 dark:bg-white/5 dark:text-white sm:text-sm"
        >
            @foreach ($this->editableRoles() as $roleCase)
                <option value="{{ $roleCase->value }}">{{ $roleCase->label() }}</option>
            @endforeach
        </select>
    </div>

    <div class="rounded-lg bg-gray-50 p-4 text-sm text-gray-600 dark:bg-white/5 dark:text-gray-300">
        <p>
            <strong class="text-gray-950 dark:text-white">Administrator tidak ada dalam daftar ini.</strong>
            Peran itu selalu punya akses penuh ke seluruh menu dan tidak bisa dibatasi dari sini —
            supaya tidak ada cara untuk mengunci diri sendiri keluar dari halaman ini.
        </p>
        <p class="mt-2">
            Peran yang tidak diberi centang apa pun tidak melihat menu tersebut sama sekali.
            Mencentang <em>Tambah</em>, <em>Ubah</em>, atau <em>Hapus</em> otomatis menyertakan
            <em>Lihat</em>.
        </p>
        <p class="mt-2">
            Catatan: selain Administrator, hanya <em>Content Creator</em> (khusus News Feed) yang
            saat ini bisa masuk ke panel ini. Memberi centang di bawah kepada peran operasional
            (Finance, Barista, Rider, Staff) menyiapkan hak menunya, tetapi pintu masuk panel untuk
            peran tersebut masih perlu dibuka terpisah.
        </p>
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
        <table class="w-full text-sm">
            <thead>
                <tr class="bg-gray-50 dark:bg-white/5">
                    <th class="px-4 py-3 text-left font-medium text-gray-950 dark:text-white">Menu</th>
                    @foreach (\App\Filament\Pages\RoleAccessMatrix::ABILITIES as $key => $label)
                        <th class="px-4 py-3 text-center font-medium text-gray-950 dark:text-white">{{ $label }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($this->modules() as $module)
                    @php $offered = $this->abilitiesFor($module); @endphp
                    <tr>
                        <td class="px-4 py-3">
                            <span class="font-medium text-gray-950 dark:text-white">{{ $module->label() }}</span>
                            @if ($module->isReadOnly())
                                <span class="ml-2 text-xs text-gray-500 dark:text-gray-400">(hanya baca)</span>
                            @endif
                        </td>

                        @foreach (\App\Filament\Pages\RoleAccessMatrix::ABILITIES as $ability => $label)
                            <td class="px-4 py-3 text-center">
                                @if (array_key_exists($ability, $offered))
                                    <input
                                        type="checkbox"
                                        wire:model="grants.{{ $module->value }}.{{ $ability }}"
                                        aria-label="{{ $module->label() }} — {{ $label }}"
                                        class="h-5 w-5 cursor-pointer rounded border-gray-300 text-primary-600 focus:ring-primary-500 dark:border-white/20 dark:bg-white/5"
                                    />
                                @else
                                    <span class="text-gray-300 dark:text-gray-600">—</span>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div>
        <x-filament::button wire:click="save" icon="heroicon-o-check">
            Simpan Matriks
        </x-filament::button>
    </div>
</x-filament-panels::page>
