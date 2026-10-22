<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SuperAdmin extends Command
{
    protected $signature = 'soul:super-admin {phone} {--revoke}';

    protected $description = 'Naikkan atau turunkan status Super Administrator untuk akun Administrator aktif';

    public function handle(): int
    {
        try {
            DB::transaction(function (): void {
                $phone = PhoneNumber::normalize((string) $this->argument('phone'));
                User::query()->where('role', Role::ADMINISTRATOR->value)
                    ->orderBy('id')->lockForUpdate()->get(['id']);
                $user = User::query()->where('phone_e164', $phone)->lockForUpdate()->first();
                if ($user === null || ! $user->is_active || $user->role !== Role::ADMINISTRATOR) {
                    throw ValidationException::withMessages(['phone' => 'Nomor ini bukan Administrator aktif.']);
                }
                $user->is_super_admin = ! $this->option('revoke');
                $user->save();
            });
        } catch (ValidationException $exception) {
            $this->error(collect($exception->errors())->flatten()->first() ?? 'Perubahan gagal.');

            return self::FAILURE;
        }

        $this->info($this->option('revoke') ? 'Status Super Administrator dicabut.' : 'Status Super Administrator aktif.');

        return self::SUCCESS;
    }
}
