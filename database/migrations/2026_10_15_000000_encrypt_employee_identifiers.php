<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->text('national_id')->nullable()->change();
            $table->text('bank_account_number')->nullable()->change();
        });

        // Production deploy enters maintenance mode before migrating, so user writes are paused.
        DB::table('users')->select(['id', 'national_id', 'bank_account_number'])
            ->orderBy('id')->chunkById(100, function ($users): void {
                foreach ($users as $user) {
                    DB::table('users')->where('id', $user->id)->update([
                        'national_id' => $this->encryptLegacyValue($user->national_id),
                        'bank_account_number' => $this->encryptLegacyValue($user->bank_account_number),
                    ]);
                }
            });
    }

    public function down(): void
    {
        DB::table('users')->select(['id', 'national_id', 'bank_account_number'])
            ->orderBy('id')->chunkById(100, function ($users): void {
                foreach ($users as $user) {
                    DB::table('users')->where('id', $user->id)->update([
                        'national_id' => $user->national_id === null ? null : Crypt::decryptString($user->national_id),
                        'bank_account_number' => $user->bank_account_number === null ? null : Crypt::decryptString($user->bank_account_number),
                    ]);
                }
            });

        Schema::table('users', function (Blueprint $table): void {
            $table->string('national_id', 32)->nullable()->change();
            $table->string('bank_account_number', 64)->nullable()->change();
        });
    }

    private function encryptLegacyValue(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        // A partially completed migration can be retried without encrypting earlier rows twice.
        try {
            Crypt::decryptString($value);

            return $value;
        } catch (DecryptException) {
            return Crypt::encryptString($value);
        }
    }
};
