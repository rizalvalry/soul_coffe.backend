<!doctype html>
<html lang="id"><head><meta charset="utf-8"><title>Slip Gaji</title>
<style>body{font-family:DejaVu Sans,sans-serif;font-size:12px}table{width:100%;border-collapse:collapse}td,th{padding:6px;border-bottom:1px solid #ddd;text-align:left}.money{text-align:right}</style>
</head><body>
<h1>Slip Gaji</h1>
<p>Periode: {{ $run->period_start->format('Y-m') }}<br>Karyawan: {{ $item->user->name }}</p>
<table><tr><th>Rincian</th><th class="money">Rupiah</th></tr>
<tr><td>Gaji pokok</td><td class="money">{{ number_format($snapshot['base_salary_minor'], 0, ',', '.') }}</td></tr>
<tr><td>Insentif Rider</td><td class="money">{{ number_format($snapshot['rider_incentive_minor'], 0, ',', '.') }}</td></tr>
@foreach ($snapshot['components'] as $component)
<tr><td>{{ $component['name'] }} ({{ $component['kind'] === 'DEDUCTION' ? 'potongan' : 'tunjangan' }})</td><td class="money">{{ number_format($component['amount_minor'], 0, ',', '.') }}</td></tr>
@endforeach
<tr><td>Bruto</td><td class="money">{{ number_format($snapshot['gross_pay_minor'], 0, ',', '.') }}</td></tr>
<tr><td>BPJS pegawai</td><td class="money">{{ number_format($snapshot['bpjs_employee_total_minor'], 0, ',', '.') }}</td></tr>
<tr><td>BPJS perusahaan (info)</td><td class="money">{{ number_format($snapshot['bpjs_employer_total_minor'], 0, ',', '.') }}</td></tr>
<tr><td>PPh 21</td><td class="money">{{ number_format($snapshot['pph21_minor'], 0, ',', '.') }}</td></tr>
<tr><th>Take-home pay</th><th class="money">{{ number_format($snapshot['take_home_minor'], 0, ',', '.') }}</th></tr>
</table></body></html>
