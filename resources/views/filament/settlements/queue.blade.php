<div class="bsi-cms-card">
    <label class="bsi-cms-field">Tanggal operasional
        <input type="date" wire:model.live="selectedDate" class="bsi-input" />
    </label>
    <div class="bsi-cms-table-scroll">
        <table class="bsi-cms-table">
            <thead><tr>
                <th>Coffee Bike</th><th>Rider</th><th>Kedatangan</th>
                <th class="bsi-cms-money">Ekspektasi Tunai</th><th class="bsi-cms-money">QRIS</th><th class="bsi-cms-money">Transfer</th>
                <th class="bsi-cms-money">Setoran</th><th class="bsi-cms-money">Selisih</th><th>Status</th>
            </tr></thead>
            <tbody>
            @foreach ($this->queue() as $row)
                <tr>
                    <td>{{ $row['cart_code'] }}</td><td>{{ $row['staff_name'] }}</td>
                    <td>{{ $row['arrived_at'] ? \Illuminate\Support\Carbon::parse($row['arrived_at'])->format('H:i') : 'Belum tiba' }}</td>
                    <td class="bsi-cms-money">Rp {{ number_format($row['expected_cash_minor'], 0, ',', '.') }}</td>
                    <td class="bsi-cms-money">Rp {{ number_format($row['expected_qris_minor'], 0, ',', '.') }}</td>
                    <td class="bsi-cms-money">Rp {{ number_format($row['expected_transfer_minor'], 0, ',', '.') }}</td>
                    <td class="bsi-cms-money">{{ $row['settlement_id'] ? 'Rp '.number_format($row['cash_minor'] + $row['qris_minor'] + $row['transfer_minor'], 0, ',', '.') : 'Belum setor' }}</td>
                    <td class="bsi-cms-money">
                        {{ $row['settlement_id'] ? 'Rp '.number_format($row['variance_minor'], 0, ',', '.') : '-' }}
                        @if ($row['expected_stale'])
                            <div class="bsi-muted">Terhadap snapshot Rp {{ number_format($row['snapshot_expected_total_minor'], 0, ',', '.') }}; perubahan live Rp {{ number_format($row['stale_delta_minor'], 0, ',', '.') }}</div>
                        @endif
                    </td>
                    <td class="bsi-cms-status">
                        <div>{{ $row['closing_status'] }}</div>
                        @if (in_array(auth()->user()?->role, [\App\Enums\Role::ADMINISTRATOR, \App\Enums\Role::FINANCE], true) && $row['outside_closing_point'])
                            <span class="bsi-cms-badge bsi-cms-badge--warning">Pernah Closing di luar titik / GPS mati{{ $row['closing_distance_m'] !== null ? ' · lokasi terbaru '.number_format($row['closing_distance_m'], 0, ',', '.').' m' : '' }}</span>
                        @endif
                        @if ($row['carry_warning'])
                            <span class="bsi-cms-badge bsi-cms-badge--warning">Sisa kemarin</span>
                        @endif
                        @if ($row['closeout_variance_count'] > 0)
                            <span class="bsi-cms-badge bsi-cms-badge--danger">Selisih Tutup Coffee Bike</span>
                        @endif
                        @if ($row['settlement_status'] === 'VARIANCE_FLAGGED')
                            <span class="bsi-cms-badge bsi-cms-badge--danger">Selisih total</span>
                        @else
                            {{ $row['settlement_status'] ?? 'Belum setor' }}
                        @endif
                        @if ($row['method_variance_flagged'])
                            <span class="bsi-cms-badge bsi-cms-badge--warning">Selisih metode</span>
                        @endif
                        @if ($row['self_approved'])
                            <span class="bsi-cms-badge bsi-cms-badge--warning">Disetujui sendiri</span>
                        @endif
                        @if ($row['expected_stale'])
                            <span class="bsi-cms-badge bsi-cms-badge--warning">Ekspektasi berubah</span>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
