{{--
    Penjualan per gerobak hari ini — the dashboard list.

    Styled with the `bsi-` classes from public/css/bsi-bw.css, wrapped in Filament's own widget
    card so it sits with the charts above it instead of floating.
--}}
<x-filament-widgets::widget>
    <x-filament::section>
        @php
            $rows = $this->rows();
            $totals = $this->totals();
            $peak = $this->peak();
        @endphp

        <div class="bsi">
            <div class="bsi-sheet">
                <div class="bsi-sheet__head">
                    <div class="bsi-sheet__head-left">
                        <span class="bsi-kicker">Hari ini · per gerobak di lokasinya</span>
                        <h2 class="bsi-title">Penjualan gerobak</h2>
                    </div>

                    @if ($peak['area'])
                        {{-- The single serif-italic flourish this view is allowed. --}}
                        <span class="bsi-serif">
                            teramai {{ $peak['area'] }} · {{ str_pad((string) $peak['hour'], 2, '0', STR_PAD_LEFT) }}:00
                        </span>
                    @endif
                </div>

                @if ($rows === [])
                    <div class="bsi-empty">
                        <p class="bsi-empty__title">Belum ada transaksi hari ini</p>
                        <p>Baris di sini terisi sendiri begitu staff mencatat penjualan dari gerobaknya.</p>
                    </div>
                @else
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
                                    <th>Terakhir</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($rows as $row)
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
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr class="bsi-foot-total">
                                    <td class="bsi-name">{{ $totals['carts'] }} gerobak</td>
                                    <td></td>
                                    <td></td>
                                    <td class="bsi-num">{{ number_format($totals['transactions'], 0, ',', '.') }}</td>
                                    <td class="bsi-num">{{ number_format($totals['cups'], 0, ',', '.') }}</td>
                                    <td class="bsi-num">{{ number_format($totals['revenue'], 0, ',', '.') }}</td>
                                    <td class="bsi-num @if ($totals['flagged'] > 0) bsi-num--alarm @endif">
                                        {{ $totals['flagged'] > 0 ? $totals['flagged'] : '' }}
                                    </td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
