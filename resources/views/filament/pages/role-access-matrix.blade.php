{{--
    The access matrix, in the BSI Black & White system (see public/css/bsi-bw.css).

    One role at a time, on purpose: the full 6-roles × 12-modules × 4-abilities grid
    is 300+ checkboxes on one screen, which is a screen nobody reads carefully.
    Picking the role first turns the same job into a short, checkable list.

    Every class here is a `bsi-` class from our own stylesheet — see the note at the
    top of attendance-sheet.blade.php for why no Tailwind utility appears.
--}}
<x-filament-panels::page>
    <div class="bsi">
        <div class="bsi-toolbar">
            <div class="bsi-field">
                <label class="bsi-label" for="bsi-role-select">Peran</label>
                <select id="bsi-role-select" class="bsi-select" wire:model.live="role">
                    @foreach ($this->editableRoles() as $roleCase)
                        <option value="{{ $roleCase->value }}">{{ $roleCase->label() }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="bsi-note">
            <span class="bsi-kicker">Yang perlu diketahui</span>
            <p>
                <strong>Administrator tidak ada dalam daftar ini.</strong> Peran itu selalu punya
                akses penuh ke seluruh menu dan tidak bisa dibatasi dari sini — supaya tidak ada
                cara untuk mengunci diri sendiri keluar dari halaman ini.
            </p>
            <p>
                Peran tanpa centang apa pun tidak melihat menu tersebut sama sekali. Mencentang
                <strong>Tambah</strong>, <strong>Ubah</strong>, atau <strong>Hapus</strong>
                otomatis menyertakan <strong>Lihat</strong>.
            </p>
            <p>
                Peran operasional otomatis bisa masuk panel begitu diberi minimal satu menu.
                Content Creator tetap khusus News Feed dan tidak diatur dari sini.
            </p>
        </div>

        <div class="bsi-sheet">
            <div class="bsi-sheet__head">
                <div class="bsi-sheet__head-left">
                    <span class="bsi-kicker">Hak akses menu</span>
                    <h2 class="bsi-title">Management users role</h2>
                </div>
                {{-- The one serif-italic moment: whose access is on screen. --}}
                <span class="bsi-serif">{{ \App\Enums\Role::from($this->role)->label() }}</span>
            </div>

            <table class="bsi-matrix">
                <thead>
                    <tr>
                        <th>Menu</th>
                        @foreach (\App\Filament\Pages\RoleAccessMatrix::ABILITIES as $label)
                            <th>{{ $label }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->modules() as $module)
                        @php $offered = $this->abilitiesFor($module); @endphp
                        <tr>
                            <td>
                                <span class="bsi-matrix__module">{{ $module->label() }}</span>
                                @if ($module->isReadOnly())
                                    <span class="bsi-matrix__hint">hanya baca</span>
                                @endif
                            </td>

                            @foreach (\App\Filament\Pages\RoleAccessMatrix::ABILITIES as $ability => $label)
                                <td>
                                    @if (array_key_exists($ability, $offered))
                                        <input
                                            type="checkbox"
                                            class="bsi-check"
                                            wire:model="grants.{{ $module->value }}.{{ $ability }}"
                                            aria-label="{{ $module->label() }} — {{ $label }}"
                                        >
                                    @else
                                        <span class="bsi-na" aria-hidden="true">–</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div>
            {{-- Emphasis is inverted ink, never a colour. --}}
            <button type="button" class="bsi-btn bsi-btn--ink" wire:click="save">
                <svg class="bsi-btn__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg>
                Simpan hak akses
            </button>
        </div>
    </div>
</x-filament-panels::page>
