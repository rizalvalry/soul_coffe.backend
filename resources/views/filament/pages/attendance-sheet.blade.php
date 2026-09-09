{{--
    The absensi sheet, in the BSI Black & White system (see public/css/bsi-bw.css).

    Every class here is a `bsi-` class from our own stylesheet. This project has no panel build
    step and Filament ships pre-compiled CSS, so a Tailwind utility written here would simply
    never apply — which is exactly how the first version of this screen shipped unstyled.

    Rows come from `users`; there is no separate roster. A cell filled by the employee's own
    clock-in is marked with a small ink dot so the sheet says where each number came from.
--}}
<x-filament-panels::page>
    @php
        $month = $this->selectedMonth();
        $daysInMonth = $month->copy()->endOfMonth()->day;
        $rows = $this->rows();
        $editable = $this->canEditCells();
        $codes = $this->codeOptions();

        // Fill classes mirror the legend marks exactly, so the key and the grid agree.
        $cellClass = ['M' => '', 'T' => 'bsi-cell--t', 'S' => 'bsi-cell--s', 'L' => 'bsi-cell--l'];
        $markClass = ['M' => '', 'T' => 'bsi-mark--t', 'S' => 'bsi-mark--s', 'L' => 'bsi-mark--l'];
        $roleLabel = $this->role === '' ? 'Semua Role' : \App\Enums\Role::from($this->role)->label();
    @endphp

    <div class="bsi">
        <div class="bsi-toolbar">
            <div class="bsi-field">
                <label class="bsi-label" for="bsi-month">Bulan</label>
                <input id="bsi-month" class="bsi-input" type="month" wire:model.live="month">
            </div>

            <div class="bsi-field">
                <label class="bsi-label" for="bsi-role">Role</label>
                <select id="bsi-role" class="bsi-select" wire:model.live="role">
                    <option value="">Semua Role</option>
                    @foreach (\App\Enums\Role::cases() as $roleCase)
                        <option value="{{ $roleCase->value }}">{{ $roleCase->label() }}</option>
                    @endforeach
                </select>
            </div>

            <div class="bsi-toolbar__spacer"></div>

            {{-- Lucide chevrons, inline at strokeWidth 1.6 — the system's icon spec. --}}
            <button type="button" class="bsi-btn" wire:click="shiftMonth(-1)">
                <svg class="bsi-btn__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>
                Bulan sebelumnya
            </button>

            <button type="button" class="bsi-btn" wire:click="shiftMonth(1)">
                Bulan berikutnya
                <svg class="bsi-btn__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 18l6-6-6-6"/></svg>
            </button>
        </div>

        <div class="bsi-legend">
            <span class="bsi-kicker">Keterangan</span>
            @foreach ($codes as $code)
                <span class="bsi-legend__item">
                    <span class="bsi-mark {{ $markClass[$code->value] ?? '' }}">{{ $code->value }}</span>
                    {{ $code->label() }}
                </span>
            @endforeach
            <span class="bsi-legend__item">
                <span class="bsi-mark bsi-mark--empty">–</span>
                Belum diisi
            </span>
            <span class="bsi-legend__item">
                <span class="bsi-mark bsi-mark--dotted">M</span>
                Dari absen aplikasi
            </span>
        </div>

        <div class="bsi-note">
            <span class="bsi-kicker">Dari mana angkanya</span>
            @if ($this->roleClocksIn())
                <p>
                    <strong>M terisi sendiri</strong> begitu {{ strtolower($roleLabel) }} menekan absen
                    di aplikasi — jam yang dipakai jam server, bukan jam HP. Sel bertanda titik berasal
                    dari absen itu; hover untuk melihat jamnya.
                </p>
                <p>
                    Isian manual menimpa angka tersebut (mis. menandai sakit atau libur), dan yang
                    manual ditampilkan tanpa titik. Mengosongkan isian manual tidak menghapus absennya
                    — selnya kembali menampilkan M dari aplikasi.
                </p>
            @else
                <p>
                    Role ini <strong>tidak punya absen di aplikasi</strong> (hanya Barista dan Staff yang
                    bisa), jadi seluruh selnya diisi manual di sini.
                </p>
            @endif
        </div>

        @unless ($editable)
            <div class="bsi-note">
                <span class="bsi-kicker">Akses baca saja</span>
                <p>
                    Peran Anda diberi akses <strong>melihat</strong> laporan ini. Hubungi
                    Administrator bila perlu mengubah absensi.
                </p>
            </div>
        @endunless

        <div class="bsi-sheet">
            <div class="bsi-sheet__head">
                <div class="bsi-sheet__head-left">
                    <span class="bsi-kicker">{{ $roleLabel }}</span>
                    <h2 class="bsi-title">Laporan absensi</h2>
                </div>
                {{-- The single serif-italic flourish for this view: the period it covers. --}}
                <span class="bsi-serif">{{ $month->translatedFormat('F Y') }}</span>
            </div>

            <div class="bsi-scroll">
                <table class="bsi-grid">
                    <thead>
                        <tr>
                            <th class="bsi-pin-1">No</th>
                            <th class="bsi-pin-2">NIK</th>
                            <th class="bsi-name">Nama Karyawan</th>
                            <th>Size</th>

                            @for ($day = 1; $day <= $daysInMonth; $day++)
                                <th class="bsi-day">{{ $day }}</th>
                            @endfor

                            <th class="bsi-sum-first">Libur<br>(L)</th>
                            <th>Sakit<br>(S)</th>
                            <th>Berangkat siang /<br>tidak target</th>
                            <th>Hadir<br>(M)</th>
                            <th>Jatah<br>klibur</th>
                            <th>Lebih dari jatah /<br>tidak masuk</th>
                            <th>Presentase<br>kehadiran</th>
                        </tr>
                    </thead>

                    <tbody>
                        @forelse ($rows as $index => $row)
                            @php
                                $user = $row['user'];
                                $summary = $row['summary'];
                            @endphp
                            <tr>
                                <td class="bsi-pin-1 bsi-num bsi-num--quiet">{{ $index + 1 }}</td>
                                <td class="bsi-pin-2">{{ $user->nik ?? '–' }}</td>
                                <td class="bsi-name">{{ $user->name }}</td>
                                <td class="bsi-num bsi-num--quiet">{{ $user->uniform_size ?? '–' }}</td>

                                @for ($day = 1; $day <= $daysInMonth; $day++)
                                    @php
                                        $cell = $row['cells'][$day] ?? ['code' => null, 'source' => null, 'time' => null];
                                        $code = $cell['code'];
                                        $fromApp = $cell['source'] === 'app';
                                    @endphp
                                    <td
                                        class="bsi-day bsi-cell {{ $code ? ($cellClass[$code->value] ?? '') : '' }} {{ $fromApp ? 'bsi-cell--sourced' : '' }}"
                                        @if ($fromApp) title="Absen dari aplikasi pukul {{ $cell['time'] }}" @endif
                                    >
                                        @if ($editable)
                                            {{-- Saves on change: a month gets filled in without
                                                 ever reaching for a Save button. --}}
                                            {{-- One control, no overlay. When the cell is filled by
                                                 the employee's own clock-in and nobody has
                                                 overridden it, the "no manual entry" option is
                                                 what is selected — so it is LABELLED with the code
                                                 that clock-in produced. Picking that option again
                                                 later clears an override and hands the cell back
                                                 to the app, which is exactly what it reads as. --}}
                                            <select
                                                class="bsi-cell__select"
                                                aria-label="{{ $user->name }} tanggal {{ $day }}{{ $fromApp ? ' (absen aplikasi pukul '.$cell['time'].')' : '' }}"
                                                wire:change="setCell({{ $user->id }}, {{ $day }}, $event.target.value)"
                                            >
                                                <option value="" @selected($cell['source'] !== 'manual')>{{ $fromApp ? 'M' : '–' }}</option>
                                                @foreach ($codes as $option)
                                                    <option value="{{ $option->value }}" @selected($cell['source'] === 'manual' && $code === $option)>{{ $option->value }}</option>
                                                @endforeach
                                            </select>
                                        @else
                                            <span class="bsi-cell__static">{{ $code?->value ?? '' }}</span>
                                        @endif
                                    </td>
                                @endfor

                                <td class="bsi-sum-first bsi-num bsi-num--strong">{{ $summary['libur'] }}</td>
                                <td class="bsi-num">{{ $summary['sakit'] }}</td>
                                <td class="bsi-num">{{ $summary['late'] }}</td>
                                <td class="bsi-num bsi-num--strong">{{ $summary['hadir'] }}</td>
                                <td class="bsi-num bsi-num--quiet">{{ $summary['quota'] }}</td>
                                {{-- Danger red is the only hue this system has, and this is the
                                     only figure that earns it: days off taken beyond the quota. --}}
                                <td class="bsi-num {{ $summary['over_quota'] > 0 ? 'bsi-num--alarm' : 'bsi-num--quiet' }}">{{ $summary['over_quota'] }}</td>
                                <td class="bsi-num bsi-num--strong">{{ number_format($summary['attendance_rate'], 0) }}%</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ 4 + $daysInMonth + 7 }}">
                                    <div class="bsi-empty">
                                        <p class="bsi-empty__title">Belum ada pegawai aktif untuk role ini</p>
                                        <p>Tambahkan lewat menu Master Data → Pengguna &amp; Role, lengkapi NIK dan jatah kliburnya, lalu kembali ke sini.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-filament-panels::page>
