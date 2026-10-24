<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublishTest extends TestCase
{
    private string $directory;

    private string $apk = 'small fake apk for the publish route';

    private string $sha256;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/publish-'.Str::random(12));
        File::makeDirectory($this->directory, 0755, true);
        config()->set('publish.directory', $this->directory);

        $this->sha256 = strtoupper(hash('sha256', $this->apk));
        File::put($this->directory.'/soul-coffeemate-v1.5.10.apk', $this->apk);
        File::put($this->directory.'/releases.json', json_encode([
            'oapk' => [[
                'version' => '1.5.10',
                'version_code' => 30,
                'file' => 'soul-coffeemate-v1.5.10.apk',
                'size_bytes' => strlen($this->apk),
                'sha256' => $this->sha256,
                'released_at' => '2026-10-23',
                'notes' => ['Tampilan lebih enak dipakai.'],
                'min_backend' => '79c0cee',
            ]],
            'capps' => [],
        ], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        File::delete($this->directory.'/soul-coffeemate-v1.5.10.apk');
        File::delete($this->directory.'/soul-coffeemate-v1.5.11.apk');
        File::delete($this->directory.'/releases.json');
        rmdir($this->directory);

        parent::tearDown();
    }

    public function test_publish_index_and_release_page_are_public_and_show_release_metadata(): void
    {
        $this->get('/')->assertOk();

        $this->get('/publish')->assertOk()
            ->assertSee('OperationalApps')
            ->assertSee('1.5.10')
            ->assertSee('CustomerApps');

        $this->get('/publish/version/oapk_v1.5.10')->assertOk()
            ->assertSee('OperationalApps v1.5.10')
            ->assertSee('VersionCode')
            ->assertSee('0,0 MB')
            ->assertSee('23 Oktober 2026')
            ->assertSee($this->sha256)
            ->assertSee('Yang baru')
            ->assertSee('Unduh APK')
            ->assertSee('sumber tidak dikenal')
            ->assertSee('Tidak perlu uninstall')
            ->assertDontSee('79c0cee');
    }

    public function test_latest_redirects_and_empty_customer_channel_is_friendly(): void
    {
        $this->get('/publish/version/oapk_latest')
            ->assertStatus(302)
            ->assertRedirect('/publish/version/oapk_v1.5.10');

        $this->get('/publish/version/capps_latest')->assertNotFound()
            ->assertSee('CustomerApps belum tersedia');

        $this->get('/publish/version/capps_v1.0.0')->assertNotFound()
            ->assertSee('CustomerApps belum tersedia');
    }

    public function test_latest_selects_highest_available_semver(): void
    {
        $catalog = json_decode(File::get($this->directory.'/releases.json'), true, 512, JSON_THROW_ON_ERROR);
        $next = $catalog['oapk'][0];
        $next['version'] = '1.5.11';
        $next['version_code'] = 31;
        $next['file'] = 'soul-coffeemate-v1.5.11.apk';
        $next['size_bytes'] = 8;
        $next['sha256'] = strtoupper(hash('sha256', 'next apk'));
        $catalog['oapk'][] = $next;
        File::put($this->directory.'/soul-coffeemate-v1.5.11.apk', 'next apk');
        File::put($this->directory.'/releases.json', json_encode($catalog, JSON_THROW_ON_ERROR));

        $this->get('/publish/version/oapk_latest')->assertStatus(302)
            ->assertRedirect('/publish/version/oapk_v1.5.11');
    }

    public function test_download_has_its_own_ten_per_minute_limit(): void
    {
        $this->get('/publish')->assertOk();

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->assertSame($this->apk, $this->get('/publish/version/oapk_v1.5.10/download')
                ->assertOk()->streamedContent());
        }

        $this->get('/publish/version/oapk_v1.5.10/download')->assertStatus(429);
    }

    public function test_valid_old_or_missing_version_returns_410_with_latest_link(): void
    {
        $this->get('/publish/version/oapk_v1.5.9')->assertStatus(410)
            ->assertSee('/publish/version/oapk_v1.5.10');

        $this->get('/publish/version/oapk_v1.5.9/download')->assertStatus(410);

        File::delete($this->directory.'/soul-coffeemate-v1.5.10.apk');
        $this->get('/publish/version/oapk_v1.5.10')->assertStatus(410);
    }

    public function test_download_streams_exact_registered_apk_with_expected_headers_and_hash(): void
    {
        $response = $this->get('/publish/version/oapk_v1.5.10/download')->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.android.package-archive')
            ->assertHeader('Content-Disposition', 'attachment; filename="soul-coffeemate-v1.5.10.apk"')
            ->assertHeader('Content-Length', (string) strlen($this->apk));

        $bytes = $response->streamedContent();
        $this->assertSame($this->apk, $bytes);
        $this->assertSame($this->sha256, strtoupper(hash('sha256', $bytes)));
    }

    public function test_invalid_channel_version_and_traversal_paths_return_404(): void
    {
        foreach ([
            '/publish/version/other_v1.5.10',
            '/publish/version/oapk_v1.5',
            '/publish/version/oapk_v1.5.10-beta',
            '/publish/version/oapk_v..%2F1.5.10',
            '/publish/version/other_v1.5.10/download',
            '/publish/version/oapk_v1.5/download',
        ] as $path) {
            $this->get($path)->assertNotFound();
        }
    }

    public function test_manifest_file_cannot_escape_release_directory(): void
    {
        File::put($this->directory.'/releases.json', json_encode([
            'oapk' => [[
                'version' => '1.5.10',
                'version_code' => 30,
                'file' => '../other.apk',
                'size_bytes' => 10,
                'sha256' => $this->sha256,
                'released_at' => '2026-10-23',
                'notes' => [],
                'min_backend' => '79c0cee',
            ]],
            'capps' => [],
        ], JSON_THROW_ON_ERROR));

        $this->get('/publish/version/oapk_v1.5.10')->assertStatus(410);
        $this->get('/publish/version/oapk_v1.5.10/download')->assertStatus(410);
    }

    public function test_malformed_manifest_entries_do_not_break_public_pages(): void
    {
        $catalog = json_decode(File::get($this->directory.'/releases.json'), true, 512, JSON_THROW_ON_ERROR);
        array_unshift($catalog['oapk'], null, ['version' => 'broken']);
        $catalog['capps'] = 'not a list';
        File::put($this->directory.'/releases.json', json_encode($catalog, JSON_THROW_ON_ERROR));

        $this->get('/publish')->assertOk()->assertSee('1.5.10');
        $this->get('/publish/version/oapk_latest')->assertRedirect('/publish/version/oapk_v1.5.10');
        $this->get('/publish/version/capps_latest')->assertNotFound();
    }
}
