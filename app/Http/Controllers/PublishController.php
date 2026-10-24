<?php

namespace App\Http\Controllers;

use DateTimeImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PublishController extends Controller
{
    public function index(): Response
    {
        return response()->view('publish', [
            'mode' => 'index',
            'channels' => [
                'oapk' => ['name' => $this->appName('oapk'), 'release' => $this->latestAvailable('oapk')],
                'capps' => ['name' => $this->appName('capps'), 'release' => $this->latestAvailable('capps')],
            ],
        ]);
    }

    public function latest(string $channel): Response|RedirectResponse
    {
        $release = $this->latestAvailable($channel);

        if ($release === null) {
            return response()->view('publish', [
                'mode' => 'unavailable',
                'appName' => $this->appName($channel),
            ], 404);
        }

        return redirect()->route('publish.version', [
            'channel' => $channel,
            'version' => $release['version'],
        ]);
    }

    public function show(string $channel, string $version): Response
    {
        $releases = $this->catalog()[$channel] ?? [];

        if ($releases === []) {
            return response()->view('publish', [
                'mode' => 'unavailable',
                'appName' => $this->appName($channel),
            ], 404);
        }

        $release = $this->findRelease($releases, $version);

        if ($release === null || $this->availableFile($release) === null) {
            return $this->gone($channel);
        }

        return response()->view('publish', [
            'mode' => 'release',
            'appName' => $this->appName($channel),
            'channel' => $channel,
            'release' => $release,
            'date' => Carbon::parse($release['released_at'])->locale('id')->translatedFormat('d F Y'),
            'size' => number_format($release['size_bytes'] / 1048576, 1, ',', '.'),
        ]);
    }

    public function download(string $channel, string $version): Response|StreamedResponse
    {
        $releases = $this->catalog()[$channel] ?? [];

        if ($releases === []) {
            return response()->view('publish', [
                'mode' => 'unavailable',
                'appName' => $this->appName($channel),
            ], 404);
        }

        $release = $this->findRelease($releases, $version);
        $path = $release === null ? null : $this->availableFile($release);

        if ($path === null) {
            return $this->gone($channel);
        }

        $stream = @fopen($path, 'rb');

        if ($stream === false) {
            return $this->gone($channel);
        }

        $size = fstat($stream)['size'];

        return response()->stream(static function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => 'application/vnd.android.package-archive',
            'Content-Disposition' => 'attachment; filename="soul-coffeemate-v'.$version.'.apk"',
            'Content-Length' => (string) $size,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function gone(string $channel): Response
    {
        $latest = $this->latestAvailable($channel);

        return response()->view('publish', [
            'mode' => 'gone',
            'appName' => $this->appName($channel),
            'latestUrl' => $latest === null ? route('publish.index') : route('publish.version', [
                'channel' => $channel,
                'version' => $latest['version'],
            ]),
        ], 410);
    }

    private function appName(string $channel): string
    {
        return $channel === 'oapk' ? 'OperationalApps' : 'CustomerApps';
    }

    private function catalog(): array
    {
        $path = $this->distDirectory().DIRECTORY_SEPARATOR.'releases.json';

        if (! is_file($path)) {
            return ['oapk' => [], 'capps' => []];
        }

        $catalog = json_decode(file_get_contents($path), true);
        $releases = ['oapk' => [], 'capps' => []];

        if (! is_array($catalog)) {
            return $releases;
        }

        foreach (array_keys($releases) as $channel) {
            if (! is_array($catalog[$channel] ?? null)) {
                continue;
            }

            foreach ($catalog[$channel] as $release) {
                if ($this->validRelease($release)) {
                    $releases[$channel][] = $release;
                }
            }
        }

        return $releases;
    }

    private function validRelease(mixed $release): bool
    {
        if (! is_array($release)
            || ! is_string($release['version'] ?? null)
            || preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+\z/D', $release['version']) !== 1
            || ! is_int($release['version_code'] ?? null)
            || $release['version_code'] < 1
            || ! is_string($release['file'] ?? null)
            || ! is_int($release['size_bytes'] ?? null)
            || $release['size_bytes'] < 1
            || ! is_string($release['sha256'] ?? null)
            || preg_match('/\A[0-9a-fA-F]{64}\z/D', $release['sha256']) !== 1
            || ! is_string($release['released_at'] ?? null)
            || ! is_array($release['notes'] ?? null)
            || ! array_is_list($release['notes'])
            || ! is_string($release['min_backend'] ?? null)) {
            return false;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $release['released_at']);

        return $date !== false
            && $date->format('Y-m-d') === $release['released_at']
            && array_reduce($release['notes'], static fn (bool $valid, mixed $note): bool => $valid && is_string($note), true);
    }

    private function latestAvailable(string $channel): ?array
    {
        $releases = $this->catalog()[$channel] ?? [];
        usort($releases, static fn (array $a, array $b): int => version_compare($b['version'], $a['version']));

        foreach ($releases as $release) {
            if ($this->availableFile($release) !== null) {
                return $release;
            }
        }

        return null;
    }

    private function findRelease(array $releases, string $version): ?array
    {
        foreach ($releases as $release) {
            if (($release['version'] ?? null) === $version) {
                return $release;
            }
        }

        return null;
    }

    private function availableFile(array $release): ?string
    {
        $filename = $release['file'] ?? null;

        if (! is_string($filename)
            || preg_match('/\A[a-zA-Z0-9._-]+\.apk\z/D', $filename) !== 1
            || str_contains($filename, '..')) {
            return null;
        }

        $directory = realpath($this->distDirectory());
        $path = $directory === false ? false : realpath($directory.DIRECTORY_SEPARATOR.$filename);

        return $path !== false && dirname($path) === $directory && is_file($path) && is_readable($path) ? $path : null;
    }

    private function distDirectory(): string
    {
        return config('publish.directory', base_path('dist'));
    }
}
