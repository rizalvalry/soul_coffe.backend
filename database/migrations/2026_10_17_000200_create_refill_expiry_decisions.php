<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refill_status_history', function (Blueprint $table): void {
            $table->foreignId('actor_id')->nullable()->change();
        });

        // Only this migration assigns the inactive identity; restore every affected audit row.
        $systemId = DB::table('users')->where('phone_e164', 't035-system-expiry')->value('id');
        if ($systemId) {
            DB::table('refill_status_history')->where('actor_id', $systemId)
                ->update(['actor_id' => null]);
        }

        Schema::create('refill_expiry_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('refill_request_id')->unique()->constrained('refill_requests')->restrictOnDelete();
            $table->foreignId('decided_by')->constrained('users')->restrictOnDelete();
            $table->string('outcome');
            $table->text('reason');
            $table->json('recognized_lines');
            $table->json('confirmed_prepared_lines');
            $table->timestamp('decided_at');
            $table->timestamps();
        });

        if (Schema::hasTable('refill_expiry_decisions_archive')) {
            $this->copyRows('refill_expiry_decisions_archive', 'refill_expiry_decisions');
            Schema::drop('refill_expiry_decisions_archive');
        }
    }

    public function down(): void
    {
        // MySQL DDL commits independently. Copy decisions before changing the schema, and keep
        // the archive through rollback so reapplying can restore every one-time decision.
        if (DB::table('refill_expiry_decisions')->exists()) {
            if (! Schema::hasTable('refill_expiry_decisions_archive')) {
                Schema::create('refill_expiry_decisions_archive', function (Blueprint $table): void {
                    $table->id();
                    $table->unsignedBigInteger('refill_request_id')->unique();
                    $table->unsignedBigInteger('decided_by');
                    $table->string('outcome');
                    $table->text('reason');
                    $table->json('recognized_lines');
                    $table->json('confirmed_prepared_lines');
                    $table->timestamp('decided_at');
                    $table->timestamps();
                });
            }
            $this->copyRows('refill_expiry_decisions', 'refill_expiry_decisions_archive');
        }

        if (DB::table('refill_status_history')->whereNull('actor_id')->exists()) {
            // The old schema requires a user FK. This inactive internal identity makes the
            // existing SYSTEM audit rows truthful and keeps them intact through rollback.
            $phone = 't035-system-expiry';
            $systemId = DB::table('users')->where('phone_e164', $phone)->value('id');
            if (! $systemId) {
                $systemId = DB::table('users')->insertGetId([
                    'name' => 'Sistem Refill', 'phone_e164' => $phone,
                    'password' => Hash::make(Str::random(64)), 'role' => 'STAFF',
                    'is_active' => false, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('refill_status_history')->whereNull('actor_id')->update(['actor_id' => $systemId]);
        }

        Schema::table('refill_status_history', function (Blueprint $table): void {
            $table->foreignId('actor_id')->nullable(false)->change();
        });

        Schema::drop('refill_expiry_decisions');
    }

    private function copyRows(string $source, string $target): void
    {
        DB::table($source)->orderBy('id')->chunkById(100, function ($rows) use ($target): void {
            DB::table($target)->insert($rows->map(fn ($row): array => (array) $row)->all());
        });

        if (DB::table($source)->count() !== DB::table($target)->count()) {
            throw new RuntimeException('Refill expiry decision archive copy count does not match.');
        }
    }
};
