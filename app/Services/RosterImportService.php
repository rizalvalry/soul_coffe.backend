<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Cart;
use App\Models\EmployeeProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class RosterImportService
{
    private const HEADERS = ['divisi', 'jabatan', 'nama', 'kode_cart', 'role_sistem'];

    /**
     * @return array{created: int, updated: int, skipped: int, issues: int, aborted: bool, rows: list<array{line: int, status: string, reason: string}>}
     */
    public function import(string $path, bool $dryRun = false): array
    {
        $rows = $this->readCsv($path);
        $connection = DB::connection();
        // MySQL advisory locks are connection scoped, not transaction scoped. Read and write on
        // the primary connection so two CLI processes cannot both create the same identity.
        $lock = 'soul:roster:'.sha1($connection->getDatabaseName());
        $acquired = $connection->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$lock], false);
        if ((int) ($acquired->acquired ?? 0) !== 1) {
            throw new RuntimeException('Impor roster lain sedang berjalan.');
        }

        try {
            $preview = $this->process($rows, true);
            if ($dryRun) {
                return [...$preview, 'aborted' => false];
            }
            if ($preview['issues'] > 0) {
                return [...$preview, 'aborted' => true];
            }

            $result = DB::transaction(function () use ($rows): array {
                $result = $this->process($rows, false);
                if ($result['issues'] > 0) {
                    throw new RuntimeException('Impor dibatalkan karena data berubah sejak pratinjau.');
                }

                return $result;
            });

            return [...$result, 'aborted' => false];
        } finally {
            $connection->selectOne('SELECT RELEASE_LOCK(?) AS released', [$lock], false);
        }
    }

    /**
     * @param  list<array{line: int, data: array<string, string>}>  $rows
     * @return array{created: int, updated: int, skipped: int, issues: int, rows: array}
     */
    private function process(array $rows, bool $dryRun): array
    {
        $carts = [];
        foreach (Cart::query()->get(['id', 'code']) as $cart) {
            $normalizedCode = $this->normalizeCartCode($cart->code);
            if ($normalizedCode !== '') {
                $carts[$normalizedCode][] = $cart;
            }
        }

        $users = [];
        foreach (User::query()->with('employeeProfile')->get(['id', 'name', 'role']) as $user) {
            $users[$this->personKey($user->name, $user->role)][] = $user;
        }

        $result = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'issues' => 0, 'rows' => []];
        $seen = [];

        foreach ($rows as $row) {
            $line = $row['line'];
            $data = $row['data'];
            $name = preg_replace('/\s+/u', ' ', trim($data['nama'])) ?? '';
            $role = Role::tryFrom(strtoupper(trim($data['role_sistem'])));
            $division = trim($data['divisi']);
            $position = trim($data['jabatan']);
            $cartCode = trim($data['kode_cart']);

            $reason = null;
            if ($name === '' || $role === null || $division === '' || $position === '') {
                $reason = 'kolom wajib kosong atau role tidak dikenal';
            } elseif ($role === Role::ADMINISTRATOR) {
                $reason = 'role Administrator tidak dapat dibuat dari CSV';
            } elseif (mb_strlen($name) > 255 || mb_strlen($division) > 255 || mb_strlen($position) > 255) {
                $reason = 'nilai melebihi batas kolom';
            } elseif ($cartCode !== '' && $role !== Role::STAFF) {
                $reason = 'kode Coffee Bike hanya boleh untuk Rider';
            }

            if ($reason !== null) {
                $this->record($result, $line, 'skipped', $reason, true);

                continue;
            }

            $key = $this->personKey($name, $role);
            if (isset($seen[$key])) {
                $this->record($result, $line, 'skipped', 'nama dan role berulang dalam CSV', true);

                continue;
            }
            $seen[$key] = true;

            $cartId = null;
            if ($cartCode !== '') {
                $normalizedCode = $this->normalizeCartCode($cartCode);
                if ($normalizedCode === '') {
                    $this->record($result, $line, 'skipped', 'kode Coffee Bike tidak valid', true);

                    continue;
                }
                $matches = $carts[$normalizedCode] ?? [];
                if (count($matches) !== 1) {
                    $this->record($result, $line, 'skipped', count($matches) === 0
                        ? 'kode Coffee Bike tidak ditemukan'
                        : 'kode Coffee Bike ambigu', true);

                    continue;
                }
                $cartId = $matches[0]->id;
            }

            $matches = $users[$key] ?? [];
            if (count($matches) > 1) {
                $this->record($result, $line, 'skipped', 'akun dengan nama dan role ini ambigu', true);

                continue;
            }

            $profileData = [
                'division' => $division,
                'position_title' => $position,
                'default_cart_id' => $cartId,
            ];

            if ($matches === []) {
                if (! $dryRun) {
                    $user = User::query()->create([
                        'name' => $name,
                        'phone_e164' => null,
                        'password' => Str::random(64),
                        'role' => $role,
                        'is_active' => false,
                        'roster_onboarding' => true,
                    ]);
                    $user->employeeProfile()->create($profileData);
                    AuditLog::query()->create([
                        'action' => 'roster_account_created',
                        'subject_type' => User::class,
                        'subject_id' => $user->id,
                        'after_json' => ['role' => $role->value, 'is_active' => false],
                    ]);
                    $users[$key] = [$user];
                } else {
                    $users[$key] = [null];
                }

                $this->record($result, $line, 'created', 'akun nonaktif dan profil baru');

                continue;
            }

            /** @var User|null $user */
            $user = $matches[0];
            if ($user === null) {
                $this->record($result, $line, 'skipped', 'akun direncanakan pada baris sebelumnya');

                continue;
            }

            /** @var EmployeeProfile $profile */
            $profile = $user->employeeProfile ?? new EmployeeProfile(['user_id' => $user->id]);
            $profile->fill($profileData);

            if ($profile->exists && ! $profile->isDirty()) {
                $this->record($result, $line, 'skipped', 'profil sudah sesuai');

                continue;
            }

            $wasCreated = ! $profile->exists;
            if (! $dryRun) {
                $profile->save();
                AuditLog::query()->create([
                    'action' => $wasCreated ? 'roster_profile_created' : 'roster_profile_updated',
                    'subject_type' => EmployeeProfile::class,
                    'subject_id' => $profile->id,
                    'after_json' => ['fields' => array_keys($profileData)],
                ]);
                $user->setRelation('employeeProfile', $profile);
            }

            $this->record($result, $line, 'updated', $wasCreated ? 'profil dibuat' : 'profil diperbarui');
        }

        return $result;
    }

    /** @return list<array{line: int, data: array<string, string>}> */
    private function readCsv(string $path): array
    {
        $resolved = realpath($path);
        if ($resolved === false || ! is_file($resolved) || ! is_readable($resolved)) {
            throw new RuntimeException('CSV tidak dapat dibaca.');
        }

        $normalized = strtolower(str_replace('\\', '/', $resolved));
        $root = strtolower(str_replace('\\', '/', realpath(base_path())));
        if (str_starts_with($normalized, $root.'/') && ! str_starts_with($normalized, $root.'/tests/fixtures/')) {
            throw new RuntimeException('CSV roster harus berada di luar repo backend.');
        }

        $handle = fopen($resolved, 'rb');
        if ($handle === false) {
            throw new RuntimeException('CSV tidak dapat dibuka.');
        }

        try {
            $header = fgetcsv($handle, 0, ',', '"', '');
            if ($header === false) {
                throw new RuntimeException('CSV kosong.');
            }
            $header = array_map(fn (string $value): string => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', $value))), $header);
            if (count($header) !== count(array_unique($header)) || array_diff(self::HEADERS, $header) !== []) {
                throw new RuntimeException('Header CSV wajib berisi divisi,jabatan,nama,kode_cart,role_sistem.');
            }

            $out = [];
            $line = 1;
            while (($values = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                $line++;
                if ($values === [null]) {
                    continue;
                }
                if (count($values) !== count($header)) {
                    $out[] = ['line' => $line, 'data' => array_fill_keys(self::HEADERS, '')];

                    continue;
                }
                $data = array_combine($header, $values);
                $out[] = ['line' => $line, 'data' => array_map(fn ($value): string => (string) $value, $data)];
            }

            if ($out === []) {
                throw new RuntimeException('CSV tidak berisi baris karyawan.');
            }

            return $out;
        } finally {
            fclose($handle);
        }
    }

    private function normalizeCartCode(string $code): string
    {
        $normalized = preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($code)) ?? '';

        return preg_replace('/^SOUL/', '', $normalized);
    }

    private function personKey(string $name, Role $role): string
    {
        return mb_strtoupper(preg_replace('/\s+/u', ' ', trim($name)) ?? '').'|'.$role->value;
    }

    /** @param array{created: int, updated: int, skipped: int, issues: int, rows: array} $result */
    private function record(array &$result, int $line, string $status, string $reason, bool $issue = false): void
    {
        $result[$status]++;
        if ($issue) {
            $result['issues']++;
        }
        $result['rows'][] = compact('line', 'status', 'reason');
    }
}
