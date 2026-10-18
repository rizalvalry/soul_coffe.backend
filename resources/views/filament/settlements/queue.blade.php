<div class="space-y-4">
    <label class="block text-sm font-medium">Tanggal operasional
        <input type="date" wire:model.live="selectedDate" class="mt-1 rounded-lg border-gray-300 dark:bg-gray-900" />
    </label>
    <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
        <table class="w-full text-sm">
            <thead><tr class="text-left">
                <th class="p-3">Gerobak</th><th class="p-3">Rider</th><th class="p-3">Kedatangan</th>
                <th class="p-3">Ekspektasi Tunai</th><th class="p-3">QRIS</th><th class="p-3">Transfer</th>
                <th class="p-3">Setoran</th><th class="p-3">Selisih</th><th class="p-3">Status</th>
            </tr></thead>
            <tbody>
            @foreach ($this->queue() as $row)
                <tr class="border-t border-gray-200 dark:border-gray-700">
                    <td class="p-3">{{ $row['cart_code'] }}</td><td class="p-3">{{ $row['staff_name'] }}</td>
                    <td class="p-3">{{ $row['arrived_at'] ? \Illuminate\Support\Carbon::parse($row['arrived_at'])->format('H:i') : 'Belum tiba' }}</td>
                    <td class="p-3">Rp {{ number_format($row['expected_cash_minor'], 0, ',', '.') }}</td>
                    <td class="p-3">Rp {{ number_format($row['expected_qris_minor'], 0, ',', '.') }}</td>
                    <td class="p-3">Rp {{ number_format($row['expected_transfer_minor'], 0, ',', '.') }}</td>
                    <td class="p-3">{{ $row['settlement_id'] ? 'Rp '.number_format($row['cash_minor'] + $row['qris_minor'] + $row['transfer_minor'], 0, ',', '.') : 'Belum setor' }}</td>
                    <td class="p-3">
                        {{ $row['settlement_id'] ? 'Rp '.number_format($row['variance_minor'], 0, ',', '.') : '-' }}
                        @if ($row['expected_stale'])
                            <div class="text-xs">Terhadap snapshot Rp {{ number_format($row['snapshot_expected_total_minor'], 0, ',', '.') }}; perubahan live Rp {{ number_format($row['stale_delta_minor'], 0, ',', '.') }}</div>
                        @endif
                    </td>
                    <td class="p-3">
                        <div>{{ $row['closing_status'] }}</div>
                        @if ($row['carry_warning'])
                            <span class="rounded-full bg-amber-100 px-2 py-1 text-amber-800">Sisa kemarin</span>
                        @endif
                        @if ($row['closeout_variance_count'] > 0)
                            <span class="rounded-full bg-red-100 px-2 py-1 text-red-800">Selisih Tutup Gerobak</span>
                        @endif
                        @if ($row['settlement_status'] === 'VARIANCE_FLAGGED')
                            <span class="rounded-full bg-red-100 px-2 py-1 font-semibold text-red-800">VARIANCE_FLAGGED</span>
                        @else
                            {{ $row['settlement_status'] ?? 'Belum setor' }}
                        @endif
                        @if ($row['expected_stale'])
                            <span class="rounded-full bg-amber-100 px-2 py-1 font-semibold text-amber-800">Ekspektasi berubah</span>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
