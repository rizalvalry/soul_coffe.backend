@php($aging = $this->aging())
<div class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-900">
    <h2 class="text-base font-semibold">Umur saldo kliring per {{ $aging['as_of'] }}</h2>
    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Hari penjualan yang dananya belum dipasangkan. Dana tanpa pilihan hari ditampilkan terpisah.</p>
    <div class="mt-3 flex flex-wrap gap-4 text-sm">
        <span>0–1 hari: Rp {{ number_format($aging['buckets']['0_1_minor'], 0, ',', '.') }}</span>
        <span>2–3 hari: Rp {{ number_format($aging['buckets']['2_3_minor'], 0, ',', '.') }}</span>
        <span class="{{ $aging['buckets']['over_3_minor'] > 0 ? 'font-semibold text-red-700' : '' }}">&gt;3 hari: Rp {{ number_format($aging['buckets']['over_3_minor'], 0, ',', '.') }}</span>
        <span>Dana belum dipasangkan: Rp {{ number_format($aging['unmatched_credit_minor'], 0, ',', '.') }}</span>
        <span>Saldo lain/penyesuaian: Rp {{ number_format($aging['unattributed_minor'], 0, ',', '.') }}</span>
        <span>Saldo akun kliring: Rp {{ number_format($aging['net_balance_minor'], 0, ',', '.') }}</span>
    </div>
    <div class="mt-3 overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="border-b text-left"><th class="py-2">Hari penjualan</th><th>Umur</th><th>Sisa</th><th>Status</th></tr></thead>
            <tbody>
            @forelse ($aging['rows'] as $row)
                <tr class="border-b"><td class="py-2">{{ $row['sale_date'] }}</td><td>{{ $row['age_days'] }} hari</td><td>Rp {{ number_format($row['outstanding_minor'], 0, ',', '.') }}</td><td>{{ $row['flagged'] ? 'Lewat 3 hari, cek ya' : 'Menunggu dana' }}</td></tr>
            @empty
                <tr><td colspan="4" class="py-3">Belum ada saldo kliring yang menunggu.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
