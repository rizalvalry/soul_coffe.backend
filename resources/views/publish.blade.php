<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $mode === 'index' ? 'Unduh aplikasi' : $appName.' · Unduh aplikasi' }} · Soul Coffeemate</title>
    <style>
        :root { color-scheme: light; font-family: system-ui, sans-serif; color: #16363a; background: #f4f9f9; }
        body { margin: 0; }
        main { max-width: 680px; margin: 0 auto; padding: 28px 18px 56px; }
        .brand { color: #007f85; font-weight: 800; text-decoration: none; }
        .card { margin-top: 24px; padding: 24px; border-radius: 18px; background: white; box-shadow: 0 5px 24px #123b4012; }
        h1 { margin: 0 0 12px; font-size: 1.7rem; line-height: 1.2; }
        h2 { margin: 28px 0 10px; font-size: 1.1rem; }
        p, li { line-height: 1.55; }
        .muted { color: #52676a; }
        .meta { display: grid; grid-template-columns: 110px 1fr; gap: 9px 14px; margin: 22px 0; }
        .meta dt { color: #52676a; }
        .meta dd { margin: 0; overflow-wrap: anywhere; }
        .hash { font-family: ui-monospace, monospace; font-size: .82rem; }
        .button { display: inline-block; margin-top: 14px; padding: 13px 20px; border-radius: 10px; background: #00A3AA; color: #fff; font-weight: 700; text-decoration: none; }
        a { color: #007f85; }
        ol, ul { padding-left: 22px; }
    </style>
</head>
<body>
<main>
    <a class="brand" href="{{ route('publish.index') }}">Soul Coffeemate</a>
    <div class="card">
        @if ($mode === 'index')
            <h1>Unduh aplikasi</h1>
            <p class="muted">Cari versi terbaru buat HP kamu di sini.</p>
            @foreach ($channels as $channel => $item)
                <h2>{{ $item['name'] }}</h2>
                @if ($item['release'])
                    <p>Versi {{ $item['release']['version'] }} sudah siap.</p>
                    <a href="{{ route('publish.version', ['channel' => $channel, 'version' => $item['release']['version']]) }}">Lihat versi terbaru</a>
                @else
                    <p class="muted">Belum tersedia.</p>
                @endif
            @endforeach
        @elseif ($mode === 'release')
            <h1>{{ $appName }} v{{ $release['version'] }}</h1>
            <p class="muted">Versi terbaru siap dipasang.</p>
            <dl class="meta">
                <dt>VersionCode</dt><dd>{{ $release['version_code'] }}</dd>
                <dt>Rilis</dt><dd>{{ $date }}</dd>
                <dt>Ukuran</dt><dd>{{ $size }} MB</dd>
                <dt>SHA-256</dt><dd class="hash">{{ $release['sha256'] }}</dd>
            </dl>
            <h2>Yang baru</h2>
            <ul>
                @foreach ($release['notes'] as $note)
                    <li>{{ $note }}</li>
                @endforeach
            </ul>
            <a class="button" href="{{ route('publish.download', ['channel' => $channel, 'version' => $release['version']]) }}">Unduh APK</a>
            <h2>Cara pasang</h2>
            <ol>
                <li>Unduh APK, lalu buka filenya di HP.</li>
                <li>Kalau diminta, izinkan pemasangan dari sumber tidak dikenal.</li>
                <li>Pasang langsung menimpa versi lama. Tidak perlu uninstall, lalu login seperti biasa.</li>
            </ol>
        @elseif ($mode === 'gone')
            <h1>Versi ini sudah tidak tersedia</h1>
            <p>Tenang, {{ $appName }} versi terbaru bisa kamu ambil di sini.</p>
            <a class="button" href="{{ $latestUrl }}">Lihat versi terbaru</a>
        @else
            <h1>{{ $appName }} belum tersedia</h1>
            <p>Balik lagi nanti ya. Kalau sudah siap, link unduhnya bakal muncul di sini.</p>
            <a href="{{ route('publish.index') }}">Lihat aplikasi lain</a>
        @endif
    </div>
</main>
</body>
</html>
