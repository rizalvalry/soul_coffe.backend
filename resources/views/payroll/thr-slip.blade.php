<!doctype html>
<html lang="id"><head><meta charset="utf-8"><title>Slip THR</title>
<style>body{font-family:DejaVu Sans,sans-serif;font-size:12px}table{width:100%;border-collapse:collapse}td,th{padding:7px;border-bottom:1px solid #ddd;text-align:left}.money{text-align:right}</style>
</head><body>
<h1>Slip THR</h1>
<p>Hari raya: {{ $run->holiday_date->format('d-m-Y') }}<br>Karyawan: {{ $item->user->name }}<br>Masa kerja: {{ $snapshot['months'] }} bulan</p>
<table><tr><th>Rincian</th><th class="money">Rupiah</th></tr>
<tr><td>Dasar THR</td><td class="money">{{ number_format($snapshot['base_minor'], 0, ',', '.') }}</td></tr>
<tr><td>THR bruto</td><td class="money">{{ number_format($snapshot['gross_minor'], 0, ',', '.') }}</td></tr>
<tr><td>PPh 21 THR</td><td class="money">{{ number_format($snapshot['pph21_minor'], 0, ',', '.') }}</td></tr>
<tr><th>THR diterima</th><th class="money">{{ number_format($snapshot['net_minor'], 0, ',', '.') }}</th></tr></table>
</body></html>
