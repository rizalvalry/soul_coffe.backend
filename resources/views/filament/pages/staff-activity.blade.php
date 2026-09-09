{{--
    Aktivitas Staff, in the BSI Black & White system (see public/css/bsi-bw.css).

    Two questions on one screen: where each cart is right now, and which area was busy at which
    hour. The map holds its own DOM (wire:ignore) and pulls a small JSON payload on a timer, so a
    refresh never resets the operator's pan and zoom — and never happens at all for a past date,
    because history does not change.

    Every class here is a `bsi-` class from our own stylesheet: this panel has no build step, so a
    Tailwind utility written here would simply not apply.
--}}
<x-filament-panels::page>
    @php
        $totals = $this->totals();
        $board = $this->board();
        $perCart = $this->perCart();
        $grid = $this->areaHours();
        $suspects = $this->suspects();
        $selectedName = $this->selectedStaffName();
        $staleMinutes = (int) config('soul.location_stale_minutes', 10);
        $liveCount = collect($board)->where('is_live', true)->count();
        $peakCups = max(1, max([0, ...array_values($grid['cells'])]));
    @endphp

    <div class="bsi bsi-activity">
        {{-- ── Toolbar ──────────────────────────────────────────────────── --}}
        <div class="bsi-toolbar">
            <div class="bsi-field">
                <label class="bsi-label" for="activity-date">Tanggal operasi</label>
                <input
                    id="activity-date"
                    type="date"
                    class="bsi-input"
                    wire:model.live="date"
                    max="{{ now()->toDateString() }}"
                >
            </div>

            <div class="bsi-field">
                <span class="bsi-label">Rentang grid keramaian</span>
                <div class="bsi-seg">
                    @foreach ([1 => 'Hari ini', 7 => '7 hari', 30 => '30 hari'] as $days => $label)
                        <button
                            type="button"
                            class="bsi-seg__btn @if ($this->gridDays === $days) bsi-seg__btn--on @endif"
                            wire:click="setGridDays({{ $days }})"
                        >{{ $label }}</button>
                    @endforeach
                </div>
            </div>

            <div class="bsi-toolbar__spacer"></div>

            <div class="bsi-field">
                <span class="bsi-label">Status pembaruan</span>
                <span class="bsi-muted">
                    @if ($this->isToday())
                        Peta menyegarkan sendiri tiap 20 detik.
                    @else
                        Data riwayat — tidak menyegarkan sendiri.
                    @endif
                </span>
            </div>
        </div>

        {{-- ── The numbers ──────────────────────────────────────────────── --}}
        <div class="bsi-stat-row">
            <div class="bsi-stat">
                <span class="bsi-kicker">Transaksi</span>
                <span class="bsi-stat__value">{{ number_format($totals['transactions'], 0, ',', '.') }}</span>
                <span class="bsi-stat__hint">{{ $totals['carts'] }} gerobak berjualan</span>
            </div>
            <div class="bsi-stat">
                <span class="bsi-kicker">Cups terjual</span>
                <span class="bsi-stat__value">{{ number_format($totals['cups'], 0, ',', '.') }}</span>
                <span class="bsi-stat__hint">keluar dari stok gerobak</span>
            </div>
            <div class="bsi-stat">
                <span class="bsi-kicker">Nilai penjualan</span>
                <span class="bsi-stat__value">{{ number_format($totals['revenue'], 0, ',', '.') }}</span>
                <span class="bsi-stat__hint">rupiah, harga saat transaksi</span>
            </div>
            <div class="bsi-stat">
                <span class="bsi-kicker">Terpantau aktif</span>
                <span class="bsi-stat__value">{{ $liveCount }}<span class="bsi-stat__of">/{{ count($board) }}</span></span>
                <span class="bsi-stat__hint">melapor &lt; {{ $staleMinutes }} menit</span>
            </div>
            <div class="bsi-stat @if ($totals['flagged'] > 0) bsi-stat--alarm @endif">
                <span class="bsi-kicker">Perlu ditinjau</span>
                <span class="bsi-stat__value">{{ $totals['flagged'] }}</span>
                <span class="bsi-stat__hint">transaksi ditandai, tetap tercatat</span>
            </div>
        </div>

        {{-- ── Map ──────────────────────────────────────────────────────── --}}
        <div class="bsi-sheet">
            <div class="bsi-sheet__head">
                <div class="bsi-sheet__head-left">
                    <span class="bsi-kicker">Posisi dari GPS perangkat staff</span>
                    <h2 class="bsi-title">
                        @if ($selectedName)
                            Jejak {{ $selectedName }}
                        @else
                            Peta sebaran gerobak
                        @endif
                    </h2>
                </div>

                @if ($selectedName)
                    <button type="button" class="bsi-btn" wire:click="clearStaff">Tampilkan semua</button>
                @else
                    <span class="bsi-serif">{{ $liveCount }} aktif</span>
                @endif
            </div>

            @include('filament.partials.activity-map', [
                'today' => $this->isToday(),
            ])

            <div class="bsi-legend">
                <span class="bsi-legend__item"><span class="bsi-dot bsi-dot--live"></span> Melapor &lt; {{ $staleMinutes }} menit</span>
                <span class="bsi-legend__item"><span class="bsi-dot bsi-dot--stale"></span> Posisi terakhir diketahui</span>
                <span class="bsi-legend__item"><span class="bsi-dot bsi-dot--sale"></span> Titik transaksi</span>
            </div>

            <p class="bsi-map__hint">
                Klik nama staff di tabel bawah untuk melihat jejak perjalanannya hari itu. GPS yang
                mati tidak pernah menghalangi transaksi — hanya membuat jejaknya kosong.
            </p>
        </div>

        {{-- ── Who is where ─────────────────────────────────────────────── --}}
        <div class="bsi-sheet">
            <div class="bsi-sheet__head">
                <div class="bsi-sheet__head-left">
                    <span class="bsi-kicker">Per staff</span>
                    <h2 class="bsi-title">Keberadaan &amp; hasil hari itu</h2>
                </div>
            </div>

            <div class="bsi-scroll">
                <table class="bsi-grid">
                    <thead>
                        <tr>
                            <th class="bsi-name">Staff</th>
                            <th>Gerobak</th>
                            <th>Area</th>
                            <th>Absen</th>
                            <th>Laporan posisi</th>
                            <th class="bsi-num">Transaksi</th>
                            <th class="bsi-num">Cups</th>
                            <th class="bsi-num">Nilai</th>
                            <th>Jejak</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($board as $row)
                            <tr @if ($this->selectedStaffId === $row['user_id']) class="bsi-row--on" @endif>
                                <td class="bsi-name">{{ $row['name'] }}</td>
                                <td>{{ $row['cart_code'] ?? '—' }}</td>
                                <td>{{ $row['area'] ?? '—' }}</td>
                                <td>
                                    @if ($row['clocked_in_at'])
                                        {{ \Illuminate\Support\Carbon::parse($row['clocked_in_at'])->format('H:i') }}
                                    @else
                                        <span class="bsi-muted">belum absen</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($row['reported_at'])
                                        <span class="bsi-dot @if ($row['is_live']) bsi-dot--live @else bsi-dot--stale @endif"></span>
                                        {{ $row['reported_at']->format('H:i') }}
                                        @if (! $row['is_live'])
                                            <span class="bsi-muted">terakhir</span>
                                        @endif
                                    @else
                                        <span class="bsi-muted">tidak ada sinyal</span>
                                    @endif
                                </td>
                                <td class="bsi-num">{{ $row['transactions'] }}</td>
                                <td class="bsi-num bsi-num--strong">{{ number_format($row['cups'], 0, ',', '.') }}</td>
                                <td class="bsi-num">{{ number_format($row['revenue'], 0, ',', '.') }}</td>
                                <td>
                                    @if ($row['lat'] !== null)
                                        <button type="button" class="bsi-link" wire:click="selectStaff({{ $row['user_id'] }})">
                                            {{ $this->selectedStaffId === $row['user_id'] ? 'Tutup jejak' : 'Lihat jejak' }}
                                        </button>
                                    @else
                                        <span class="bsi-muted">—</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9">
                                    <div class="bsi-empty">
                                        <p class="bsi-empty__title">Belum ada staff aktif</p>
                                        <p>Tambahkan lewat menu Pengguna &amp; Role, lalu kembali ke sini.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- ── Area × hour: the engagement question ─────────────────────── --}}
        <div class="bsi-sheet">
            <div class="bsi-sheet__head">
                <div class="bsi-sheet__head-left">
                    <span class="bsi-kicker">Keramaian per area per jam — dalam cups</span>
                    <h2 class="bsi-title">Kapan area mana yang ramai</h2>
                </div>
                @if ($grid['peak']['area'])
                    <span class="bsi-serif">
                        {{ $grid['peak']['area'] }} · {{ str_pad((string) $grid['peak']['hour'], 2, '0', STR_PAD_LEFT) }}:00
                    </span>
                @endif
            </div>

            @if ($grid['areas'] === [])
                <div class="bsi-empty">
                    <p class="bsi-empty__title">Belum ada transaksi pada rentang ini</p>
                    <p>Grid ini terisi sendiri begitu staff mulai mencatat penjualan dari gerobak.</p>
                </div>
            @else
                <div class="bsi-scroll">
                    <table class="bsi-grid bsi-heat">
                        <thead>
                            <tr>
                                <th class="bsi-name">Area</th>
                                @foreach ($grid['hours'] as $hour)
                                    <th>{{ str_pad((string) $hour, 2, '0', STR_PAD_LEFT) }}</th>
                                @endforeach
                                <th class="bsi-sum-first">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($grid['areas'] as $area)
                                <tr>
                                    <td class="bsi-name">{{ $area }}</td>
                                    @foreach ($grid['hours'] as $hour)
                                        @php
                                            $cups = $grid['cells'][$area.'|'.$hour] ?? 0;
                                            // Intensity is capped well below full ink so the
                                            // number stays readable on the darkest cell.
                                            $intensity = $cups > 0 ? round(0.06 + 0.16 * ($cups / $peakCups), 3) : 0;
                                        @endphp
                                        <td class="bsi-num" @if ($cups > 0) style="--bsi-heat: {{ $intensity }}" @endif>
                                            {{ $cups > 0 ? $cups : '' }}
                                        </td>
                                    @endforeach
                                    <td class="bsi-num bsi-num--strong bsi-sum-first">
                                        {{ number_format($grid['area_totals'][$area] ?? 0, 0, ',', '.') }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="bsi-foot-total">
                                <td class="bsi-name">Semua area</td>
                                @foreach ($grid['hours'] as $hour)
                                    <td class="bsi-num">{{ $grid['hour_totals'][$hour] ?? 0 }}</td>
                                @endforeach
                                <td class="bsi-num bsi-sum-first">
                                    {{ number_format(array_sum($grid['area_totals']), 0, ',', '.') }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="bsi-note">
                    <span class="bsi-kicker">Untuk apa grid ini</span>
                    <p>
                        Angka di sini adalah <strong>cups per area per jam</strong> menurut jam server,
                        bukan jam perangkat — jadi jam pada dua gerobak berbeda benar-benar bisa
                        dibandingkan. Ini bahan mentah untuk memindahkan gerobak ke jam dan area yang
                        memang ramai.
                    </p>
                </div>
            @endif
        </div>

        {{-- ── Per cart ─────────────────────────────────────────────────── --}}
        <div class="bsi-sheet">
            <div class="bsi-sheet__head">
                <div class="bsi-sheet__head-left">
                    <span class="bsi-kicker">Per gerobak</span>
                    <h2 class="bsi-title">Transaksi tiap gerobak di lokasinya</h2>
                </div>
            </div>

            <div class="bsi-scroll">
                <table class="bsi-grid">
                    <thead>
                        <tr>
                            <th class="bsi-name">Gerobak</th>
                            <th>Staff</th>
                            <th>Area</th>
                            <th class="bsi-num">Transaksi</th>
                            <th class="bsi-num">Cups</th>
                            <th class="bsi-num">Nilai</th>
                            <th class="bsi-num">Ditandai</th>
                            <th>Transaksi terakhir</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($perCart as $row)
                            <tr>
                                <td class="bsi-name">{{ $row['cart_code'] }}</td>
                                <td>{{ $row['staff_name'] ?? '—' }}</td>
                                <td>{{ $row['area'] ?? '—' }}</td>
                                <td class="bsi-num">{{ $row['transactions'] }}</td>
                                <td class="bsi-num bsi-num--strong">{{ number_format($row['cups'], 0, ',', '.') }}</td>
                                <td class="bsi-num">{{ number_format($row['revenue'], 0, ',', '.') }}</td>
                                <td class="bsi-num @if ($row['flagged'] > 0) bsi-num--alarm @endif">
                                    {{ $row['flagged'] > 0 ? $row['flagged'] : '' }}
                                </td>
                                <td>{{ $row['last_sale_at']?->format('H:i') ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8">
                                    <div class="bsi-empty">
                                        <p class="bsi-empty__title">Belum ada transaksi pada tanggal ini</p>
                                        <p>Staff mencatat penjualan dari aplikasi setelah absen.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- ── Flagged ──────────────────────────────────────────────────── --}}
        @if ($suspects !== [])
            <div class="bsi-sheet">
                <div class="bsi-sheet__head">
                    <div class="bsi-sheet__head-left">
                        <span class="bsi-kicker">Perlu ditinjau</span>
                        <h2 class="bsi-title">Transaksi besar dalam sekali input</h2>
                    </div>
                </div>

                <div class="bsi-note">
                    <span class="bsi-kicker">Cara membacanya</span>
                    <p>
                        Penandaan ini <strong>tidak pernah menolak transaksi</strong>. Cups-nya sudah
                        keluar dari stok gerobak dan uangnya sudah tercatat; yang diminta di sini hanya
                        satu pandangan mata. Untuk gerobak yang memang selalu ramai, matikan penandaan
                        lewat Master Data → Gerobak → “Zona ramai”.
                    </p>
                </div>

                <div class="bsi-scroll">
                    <table class="bsi-grid">
                        <thead>
                            <tr>
                                <th>Waktu</th>
                                <th class="bsi-name">Gerobak</th>
                                <th>Staff</th>
                                <th>Area</th>
                                <th class="bsi-num">Cups</th>
                                <th class="bsi-num">Nilai</th>
                                <th>Alasan</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($suspects as $row)
                                <tr>
                                    <td>{{ $row['occurred_at']->format('H:i') }}</td>
                                    <td class="bsi-name">{{ $row['cart_code'] ?? '—' }}</td>
                                    <td>{{ $row['staff_name'] ?? '—' }}</td>
                                    <td>{{ $row['area'] ?? '—' }}</td>
                                    <td class="bsi-num bsi-num--alarm">{{ $row['cups'] }}</td>
                                    <td class="bsi-num">{{ number_format($row['revenue'], 0, ',', '.') }}</td>
                                    <td class="bsi-muted">{{ $row['reason'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
