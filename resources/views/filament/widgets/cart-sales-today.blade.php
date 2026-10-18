{{--
    Penjualan per gerobak hari ini — the dashboard list.

    Styled with the `bsi-` layout classes in soul-teal-layout.css, wrapped in Filament's own widget
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
                        <p class="bsi-empty__title">Belum ada Closing hari ini</p>
                        <p>Hasil penjualan muncul setelah Rider mengisi sisa cups saat Closing.</p>
                    </div>
                @else
                    <div class="bsi-scroll">
                        <table class="bsi-grid">
                            <thead>
                                <tr>
                                    <th class="bsi-name">Gerobak</th>
                                    <th>Rider</th>
                                    <th class="bsi-num">Cups</th>
                                    <th class="bsi-num">Pendapatan</th>
                                    <th>Tanda</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($rows as $row)
                                    <tr>
                                        <td class="bsi-name">{{ $row['cart_code'] }}<small>{{ $row['area'] ?? '—' }}</small></td>
                                        <td>{{ $row['staff_name'] ?? '—' }}</td>
                                        <td class="bsi-num bsi-num--strong">{{ number_format($row['cups'], 0, ',', '.') }}</td>
                                        <td class="bsi-num">Rp {{ number_format($row['revenue'], 0, ',', '.') }}</td>
                                        <td><span class="sc-table-badge {{ $row['flagged'] > 0 ? 'sc-table-badge--danger' : 'sc-table-badge--neutral' }}">{{ $row['flagged'] > 0 ? $row['flagged'].' ditandai' : '—' }}</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr class="bsi-foot-total">
                                    <td class="bsi-name">{{ $totals['carts'] }} gerobak</td>
                                    <td></td>
                                    <td class="bsi-num">{{ number_format($totals['cups'], 0, ',', '.') }}</td>
                                    <td class="bsi-num">Rp {{ number_format($totals['revenue'], 0, ',', '.') }}</td>
                                    <td>{{ $totals['flagged'] > 0 ? $totals['flagged'].' ditandai' : '—' }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
