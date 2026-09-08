{{--
    The absensi grid. Deliberately a hand-written table rather than a Filament table: the columns
    are the days of the selected month, so their number changes with the month.

    Horizontal scroll lives on the wrapper, and the four identity columns are sticky, so scrolling
    to day 31 never leaves the reader wondering whose row they are on.
--}}
<x-filament-panels::page>
    @php
        $month = $this->selectedMonth();
        $daysInMonth = $month->copy()->endOfMonth()->day;
        $rows = $this->rows();
        $editable = $this->canEditCells();
        $codes = $this->codeOptions();
    @endphp

    {{-- Controls --}}
    <div class="flex flex-wrap items-end gap-3">
        <div>
            <label for="month" class="block text-sm font-medium text-gray-950 dark:text-white">Bulan</label>
            <input
                id="month"
                type="month"
                wire:model.live="month"
                class="mt-1 block rounded-lg border-gray-300 shadow-sm dark:border-white/20 dark:bg-white/5 dark:text-white sm:text-sm"
            />
        </div>

        <div>
            <label for="role" class="block text-sm font-medium text-gray-950 dark:text-white">Role</label>
            <select
                id="role"
                wire:model.live="role"
                class="mt-1 block rounded-lg border-gray-300 shadow-sm dark:border-white/20 dark:bg-white/5 dark:text-white sm:text-sm"
            >
                <option value="">Semua Role</option>
                @foreach (\App\Enums\Role::cases() as $roleCase)
                    <option value="{{ $roleCase->value }}">{{ $roleCase->label() }}</option>
                @endforeach
            </select>
        </div>

        <div class="flex gap-2">
            <x-filament::button color="gray" wire:click="shiftMonth(-1)" icon="heroicon-o-chevron-left">
                Bulan Sebelumnya
            </x-filament::button>
            <x-filament::button color="gray" wire:click="shiftMonth(1)" icon="heroicon-o-chevron-right" icon-position="after">
                Bulan Berikutnya
            </x-filament::button>
        </div>
    </div>

    {{-- Legend. Without this the colours are decoration; with it they are a key. --}}
    <div class="flex flex-wrap items-center gap-4 text-sm">
        <span class="font-medium text-gray-950 dark:text-white">Keterangan:</span>
        @foreach ($codes as $code)
            <span class="inline-flex items-center gap-2">
                <span
                    class="inline-flex h-6 w-6 items-center justify-center rounded text-xs font-bold"
                    style="background-color: {{ $code->background() }}; color: {{ $code->foreground() }}"
                >{{ $code->value }}</span>
                <span class="text-gray-600 dark:text-gray-300">{{ $code->label() }}</span>
            </span>
        @endforeach
        <span class="inline-flex items-center gap-2">
            <span class="inline-flex h-6 w-6 items-center justify-center rounded border border-gray-300 text-xs dark:border-white/20">—</span>
            <span class="text-gray-600 dark:text-gray-300">Belum diisi</span>
        </span>
    </div>

    @unless ($editable)
        <div class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-400/10 dark:text-amber-200">
            Peran Anda hanya diberi akses <strong>melihat</strong> laporan ini. Hubungi Administrator
            bila perlu mengubah absensi.
        </div>
    @endunless

    <div class="text-center">
        <div class="text-base font-bold uppercase tracking-wide text-gray-950 dark:text-white">
            {{ $this->role === '' ? 'Semua Role' : \App\Enums\Role::from($this->role)->label() }}
        </div>
        <div class="text-sm text-gray-600 dark:text-gray-300">{{ $month->translatedFormat('F Y') }}</div>
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
        <table class="w-full border-collapse text-xs">
            <thead>
                <tr class="bg-gray-50 dark:bg-white/5">
                    <th class="sticky left-0 z-10 border border-gray-200 bg-gray-50 px-2 py-2 dark:border-white/10 dark:bg-gray-800">No</th>
                    <th class="sticky left-10 z-10 border border-gray-200 bg-gray-50 px-2 py-2 text-left dark:border-white/10 dark:bg-gray-800">NIK</th>
                    <th class="border border-gray-200 px-2 py-2 text-left dark:border-white/10">Nama Karyawan</th>
                    <th class="border border-gray-200 px-2 py-2 dark:border-white/10">SIZE</th>

                    @for ($day = 1; $day <= $daysInMonth; $day++)
                        <th class="border border-gray-200 px-1 py-2 font-normal dark:border-white/10">{{ $day }}</th>
                    @endfor

                    <th class="border border-gray-200 bg-red-500 px-2 py-2 text-white dark:border-white/10">Libur<br>(L)</th>
                    <th class="border border-gray-200 bg-sky-400 px-2 py-2 text-white dark:border-white/10">Sakit<br>(S)</th>
                    <th class="border border-gray-200 bg-amber-400 px-2 py-2 dark:border-white/10">Berangkat Siang /<br>Tidak Target</th>
                    <th class="border border-gray-200 px-2 py-2 dark:border-white/10">Hadir<br>(M)</th>
                    <th class="border border-gray-200 bg-green-500 px-2 py-2 text-white dark:border-white/10">Jatah<br>Klibur</th>
                    <th class="border border-gray-200 bg-red-600 px-2 py-2 text-white dark:border-white/10">Lebih dari Jatah /<br>Tidak Masuk</th>
                    <th class="border border-gray-200 px-2 py-2 dark:border-white/10">Presentase<br>Kehadiran</th>
                </tr>
            </thead>

            <tbody>
                @forelse ($rows as $index => $row)
                    @php
                        $partner = $row['partner'];
                        $summary = $row['summary'];
                    @endphp
                    <tr class="hover:bg-gray-50 dark:hover:bg-white/5">
                        <td class="sticky left-0 z-10 border border-gray-200 bg-white px-2 py-1 text-center dark:border-white/10 dark:bg-gray-900">{{ $index + 1 }}</td>
                        <td class="sticky left-10 z-10 border border-gray-200 bg-white px-2 py-1 dark:border-white/10 dark:bg-gray-900">{{ $partner->nik ?? '—' }}</td>
                        <td class="border border-gray-200 px-2 py-1 whitespace-nowrap font-medium dark:border-white/10">{{ $partner->name }}</td>
                        <td class="border border-gray-200 px-2 py-1 text-center dark:border-white/10">{{ $partner->size ?? '—' }}</td>

                        @for ($day = 1; $day <= $daysInMonth; $day++)
                            @php $cell = $row['codes'][$day] ?? null; @endphp
                            <td
                                class="border border-gray-200 p-0 text-center dark:border-white/10"
                                @if ($cell) style="background-color: {{ $cell->background() }}; color: {{ $cell->foreground() }}" @endif
                            >
                                @if ($editable)
                                    {{-- One select per cell: explicit, keyboard-accessible, and it
                                         saves on change so a month can be filled in without ever
                                         reaching for a Save button. --}}
                                    <select
                                        wire:change="setCell({{ $partner->id }}, {{ $day }}, $event.target.value)"
                                        aria-label="{{ $partner->name }} tanggal {{ $day }}"
                                        class="w-9 cursor-pointer appearance-none border-0 bg-transparent px-0 py-1 text-center text-xs font-bold focus:ring-1 focus:ring-primary-500"
                                        style="color: inherit"
                                    >
                                        <option value="" @selected($cell === null)>–</option>
                                        @foreach ($codes as $code)
                                            <option value="{{ $code->value }}" @selected($cell === $code)>{{ $code->value }}</option>
                                        @endforeach
                                    </select>
                                @else
                                    <span class="block px-1 py-1 font-bold">{{ $cell?->value ?? '' }}</span>
                                @endif
                            </td>
                        @endfor

                        <td class="border border-gray-200 px-2 py-1 text-center font-semibold dark:border-white/10">{{ $summary['libur'] }}</td>
                        <td class="border border-gray-200 px-2 py-1 text-center dark:border-white/10">{{ $summary['sakit'] }}</td>
                        <td class="border border-gray-200 px-2 py-1 text-center dark:border-white/10">{{ $summary['late'] }}</td>
                        <td class="border border-gray-200 px-2 py-1 text-center font-semibold dark:border-white/10">{{ $summary['hadir'] }}</td>
                        <td class="border border-gray-200 px-2 py-1 text-center dark:border-white/10">{{ $summary['quota'] }}</td>
                        <td
                            class="border border-gray-200 px-2 py-1 text-center font-semibold dark:border-white/10 {{ $summary['over_quota'] > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-600 dark:text-gray-300' }}"
                        >{{ $summary['over_quota'] }}</td>
                        <td class="border border-gray-200 px-2 py-1 text-center font-semibold dark:border-white/10">{{ number_format($summary['attendance_rate'], 0) }}%</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ 4 + $daysInMonth + 7 }}" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                            Belum ada partner aktif untuk role ini. Tambahkan lewat menu
                            <strong>Absensi → Data Partner</strong>.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-filament-panels::page>
