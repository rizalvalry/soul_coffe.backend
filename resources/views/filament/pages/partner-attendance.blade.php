{{--
    The absensi sheet, in the BSI Black & White system (see public/css/bsi-bw.css).

    Two things this view must get right, both of which the first version got wrong:

    1. **Every class here is a `bsi-` class from our own stylesheet.** The first
       version used Tailwind utilities, and this project has no panel build step —
       Filament's shipped CSS does not contain them, so the whole screen rendered
       as bare HTML. Nothing here depends on a compile step.

    2. **The pinned columns derive their offsets from their widths.** The first
       version pinned NIK at `left-10` (40px) against a column that was not 40px
       wide, so the two identity columns overlapped. Widths and offsets now come
       from the same two CSS variables (--bsi-col-no, --bsi-col-nik).

    The day columns are the days of the selected month, so their count changes with
    the month — that is why this is a hand-built table rather than a Filament table.
--}}
<x-filament-panels::page>
    @php
        $month = $this->selectedMonth();
        $daysInMonth = $month->copy()->endOfMonth()->day;
        $rows = $this->rows();
        $editable = $this->canEditCells();
        $codes = $this->codeOptions();

        // Fill classes mirror the legend marks exactly, so the key and the grid agree.
        $cellClass = [
            'M' => '',
            'T' => 'bsi-cell--t',
            'S' => 'bsi-cell--s',
            'L' => 'bsi-cell--l',
        ];
        $markClass = [
            'M' => '',
            'T' => 'bsi-mark--t',
            'S' => 'bsi-mark--s',
            'L' => 'bsi-mark--l',
        ];
        $roleLabel = $this->role === '' ? 'Semua Role' : \App\Enums\Role::from($this->role)->label();
    @endphp

    <div class="bsi">
        {{-- Controls --}}
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

        {{-- The key. Without it the ink levels are decoration; with it they are a scale. --}}
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
                    <h2 class="bsi-title">Laporan Absensi Partner</h2>
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
                                $partner = $row['partner'];
                                $summary = $row['summary'];
                            @endphp
                            <tr>
                                <td class="bsi-pin-1 bsi-num bsi-num--quiet">{{ $index + 1 }}</td>
                                <td class="bsi-pin-2">{{ $partner->nik ?? '–' }}</td>
                                <td class="bsi-name">{{ $partner->name }}</td>
                                <td class="bsi-num bsi-num--quiet">{{ $partner->size ?? '–' }}</td>

                                @for ($day = 1; $day <= $daysInMonth; $day++)
                                    @php $cell = $row['codes'][$day] ?? null; @endphp
                                    <td class="bsi-day bsi-cell {{ $cell ? ($cellClass[$cell->value] ?? '') : '' }}">
                                        @if ($editable)
                                            {{-- Saves on change: a month gets filled in without
                                                 ever reaching for a Save button. --}}
                                            <select
                                                class="bsi-cell__select"
                                                aria-label="{{ $partner->name }} tanggal {{ $day }}"
                                                wire:change="setCell({{ $partner->id }}, {{ $day }}, $event.target.value)"
                                            >
                                                <option value="" @selected($cell === null)>–</option>
                                                @foreach ($codes as $code)
                                                    <option value="{{ $code->value }}" @selected($cell === $code)>{{ $code->value }}</option>
                                                @endforeach
                                            </select>
                                        @else
                                            <span class="bsi-cell__static">{{ $cell?->value ?? '' }}</span>
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
                                        <p class="bsi-empty__title">Belum ada partner aktif untuk role ini</p>
                                        <p>Tambahkan lewat menu Absensi → Data Partner, lalu kembali ke sini untuk mengisi absensinya.</p>
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
