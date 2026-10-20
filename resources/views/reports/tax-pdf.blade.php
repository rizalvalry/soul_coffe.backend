<!doctype html>
<html lang="id"><head><meta charset="utf-8"><title>{{ $section['title'] }}</title>
<style>body{font-family:DejaVu Sans,sans-serif;font-size:11px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #888;padding:6px;text-align:left}td:last-child{text-align:right}</style></head>
<body><h1>{{ $section['title'] }} · {{ $month }}</h1><table><thead><tr><th>Uraian</th><th>Nominal (Rp)</th></tr></thead><tbody>
@foreach ($section['rows'] as $row)<tr><td>{{ $row[0] }}</td><td>{{ number_format($row[1], 0, ',', '.') }}</td></tr>@endforeach
</tbody></table></body></html>
