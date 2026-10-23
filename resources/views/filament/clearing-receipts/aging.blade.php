@php($aging = $this->aging())
<div class="bsi-cms-card">
    <h2 class="bsi-cms-heading">Umur saldo kliring per {{ $aging['as_of'] }}</h2>
    <p class="bsi-muted">Hari penjualan yang dananya belum dipasangkan. Dana tanpa pilihan hari ditampilkan terpisah.</p>
    <div class="bsi-cms-stats">
        <span class="bsi-cms-stat"><span>0–1 hari:</span><strong>Rp {{ number_format($aging['buckets']['0_1_minor'], 0, ',', '.') }}</strong></span>
        <span class="bsi-cms-stat"><span>2–3 hari:</span><strong>Rp {{ number_format($aging['buckets']['2_3_minor'], 0, ',', '.') }}</strong></span>
        <span class="bsi-cms-stat {{ $aging['buckets']['over_3_minor'] > 0 ? 'bsi-cms-danger' : '' }}"><span>&gt;3 hari:</span><strong>Rp {{ number_format($aging['buckets']['over_3_minor'], 0, ',', '.') }}</strong></span>
        <span class="bsi-cms-stat"><span>Dana belum dipasangkan:</span><strong>Rp {{ number_format($aging['unmatched_credit_minor'], 0, ',', '.') }}</strong></span>
        <span class="bsi-cms-stat"><span>Saldo lain/penyesuaian:</span><strong>Rp {{ number_format($aging['unattributed_minor'], 0, ',', '.') }}</strong></span>
        <span class="bsi-cms-stat"><span>Saldo akun kliring:</span><strong>Rp {{ number_format($aging['net_balance_minor'], 0, ',', '.') }}</strong></span>
    </div>
    <div class="bsi-cms-table-scroll">
        <table class="bsi-cms-table">
            <thead><tr><th>Hari penjualan</th><th>Umur</th><th class="bsi-cms-money">Sisa</th><th>Status</th></tr></thead>
            <tbody>
            @forelse ($aging['rows'] as $row)
                <tr><td>{{ $row['sale_date'] }}</td><td>{{ $row['age_days'] }} hari</td><td class="bsi-cms-money">Rp {{ number_format($row['outstanding_minor'], 0, ',', '.') }}</td><td>{{ $row['flagged'] ? 'Lewat 3 hari, cek ya' : 'Menunggu dana' }}</td></tr>
            @empty
                <tr><td colspan="4">Belum ada saldo kliring yang menunggu.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
