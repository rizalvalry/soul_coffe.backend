<?php

use Database\Seeders\AccountSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('direction', 3);
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('cash_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->foreignId('account_id')->unique()->constrained('accounts')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('office_items', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('unit', 32);
            $table->foreignId('default_category_id')->nullable()->constrained('cash_categories')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('cash_transactions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->date('date');
            $table->string('direction', 3);
            $table->foreignId('cash_category_id')->constrained('cash_categories')->restrictOnDelete();
            $table->foreignId('cash_account_id')->constrained('cash_accounts')->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->string('description');
            $table->foreignId('office_item_id')->nullable()->constrained('office_items')->restrictOnDelete();
            $table->unsignedInteger('quantity')->nullable();
            $table->string('evidence_path')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 6)->default('POSTED');
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->timestamps();
            $table->index(['date', 'cash_category_id']);
        });

        // Existing installations run migrate without db:seed, so add the account before categories.
        app(AccountSeeder::class)->run();
        $accountIds = DB::table('accounts')->pluck('id', 'code');
        foreach ([
            ['Petty Cash (isi ulang)', 'OUT', '1-1100'],
            ['Keperluan Kantor', 'OUT', '6-1600'],
            ['Listrik', 'OUT', '6-1400'],
            ['Air/Galon', 'OUT', '6-1500'],
            ['Fasilitas Karyawan', 'OUT', '6-1700'],
            ['Lain-lain', 'OUT', '6-1800'],
            ['Pendapatan Lain-lain', 'IN', '4-1300'],
            ['Setoran Modal Pemilik', 'IN', '3-1100'],
        ] as [$name, $direction, $code]) {
            DB::table('cash_categories')->insert([
                'name' => $name, 'direction' => $direction, 'account_id' => $accountIds[$code],
                'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach ([['Kas Kecil Kantor', '1-1100'], ['Bank', '1-1300']] as [$name, $code]) {
            DB::table('cash_accounts')->insert([
                'name' => $name, 'account_id' => $accountIds[$code],
                'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (DB::table('cash_transactions')->exists()
            || DB::table('office_items')->exists()
            || DB::table('cash_categories')->count() !== 8
            || DB::table('cash_accounts')->count() !== 2) {
            throw new RuntimeException('Cash management data must be preserved before rollback.');
        }
        $accountIds = DB::table('accounts')->pluck('id', 'code');
        // The task worktree may already hold the pre-review seed before this migration was revised.
        $otherIncomeCode = $accountIds->has('4-1300') ? '4-1300' : '4-1200';
        foreach ([
            ['Petty Cash (isi ulang)', 'OUT', '1-1100'],
            ['Keperluan Kantor', 'OUT', '6-1600'],
            ['Listrik', 'OUT', '6-1400'],
            ['Air/Galon', 'OUT', '6-1500'],
            ['Fasilitas Karyawan', 'OUT', '6-1700'],
            ['Lain-lain', 'OUT', '6-1800'],
            ['Pendapatan Lain-lain', 'IN', $otherIncomeCode],
            ['Setoran Modal Pemilik', 'IN', '3-1100'],
        ] as [$name, $direction, $code]) {
            $row = DB::table('cash_categories')->where('name', $name)->first();
            if (! $row || $row->direction !== $direction || (int) $row->account_id !== (int) $accountIds[$code]
                || (int) $row->is_active !== 1) {
                throw new RuntimeException('Cash category edits must be preserved before rollback.');
            }
        }
        foreach ([['Kas Kecil Kantor', '1-1100'], ['Bank', '1-1300']] as [$name, $code]) {
            $row = DB::table('cash_accounts')->where('name', $name)->first();
            if (! $row || (int) $row->account_id !== (int) $accountIds[$code] || (int) $row->is_active !== 1) {
                throw new RuntimeException('Cash account edits must be preserved before rollback.');
            }
        }
        Schema::dropIfExists('cash_transactions');
        Schema::dropIfExists('office_items');
        Schema::dropIfExists('cash_accounts');
        Schema::dropIfExists('cash_categories');
    }
};
