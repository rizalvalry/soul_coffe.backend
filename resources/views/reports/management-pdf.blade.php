<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Laporan Management</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #183536; }
        h1 { font-size: 17px; margin-bottom: 3px; }
        h2 { font-size: 12px; margin-top: 18px; margin-bottom: 6px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        th, td { border: 1px solid #b9cccc; padding: 4px 5px; text-align: left; }
        th { background: #e2f0ee; }
        .number { text-align: right; white-space: nowrap; }
    </style>
</head>
<body>
    <h1>Laporan Management</h1>
    <p>Periode {{ $report['from'] }} s.d. {{ $report['to'] }}</p>
    @foreach ($sections as $section)
        <h2>{{ $section['title'] }}</h2>
        <table>
            <thead><tr>@foreach ($section['headings'] as $heading)<th>{{ $heading }}</th>@endforeach</tr></thead>
            <tbody>
                @forelse ($section['rows'] as $row)
                    <tr>@foreach ($row as $index => $value)
                        <td class="{{ is_int($value) ? 'number' : '' }}">{{ is_int($value) && in_array($index, $section['money'], true) ? 'Rp '.number_format($value, 0, ',', '.') : $value }}</td>
                    @endforeach</tr>
                @empty
                    <tr><td colspan="{{ count($section['headings']) }}">Belum ada data di periode ini.</td></tr>
                @endforelse
            </tbody>
        </table>
    @endforeach
</body>
</html>
