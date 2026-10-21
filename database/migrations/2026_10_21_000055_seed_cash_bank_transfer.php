<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Keep the IDs inserted by this migration so rollback never removes pre-existing master data.
        if (! Schema::hasTable('cash_bank_seed_ownership')) {
            Schema::create('cash_bank_seed_ownership', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('cash_account_id')->nullable()->constrained('cash_accounts')->restrictOnDelete();
                $table->foreignId('cash_category_id')->nullable()->constrained('cash_categories')->restrictOnDelete();
            });
        }

        $settlementAccount = DB::table('accounts')->where('code', '1-1200')->value('id');
        $bankAccount = DB::table('accounts')->where('code', '1-1300')->value('id');
        if (! $settlementAccount || ! $bankAccount) {
            throw new RuntimeException('Akun Kas Setoran atau Bank belum tersedia.');
        }
        $cashId = DB::table('cash_accounts')->where('name', 'Kas Setoran')->value('id');
        if ($cashId && (DB::table('cash_accounts')->where('id', $cashId)->value('account_id') != $settlementAccount
            || ! DB::table('cash_accounts')->where('id', $cashId)->value('is_active'))) {
            throw new RuntimeException('Kas Setoran sudah ada dengan pemetaan salah atau tidak aktif.');
        }
        if (! $cashId && DB::table('cash_accounts')->where('account_id', $settlementAccount)->exists()) {
            throw new RuntimeException('Akun 1-1200 sudah dipakai akun kas dengan nama berbeda.');
        }
        if (! $cashId && ! DB::table('cash_accounts')->where('account_id', $settlementAccount)->exists()) {
            $cashId = DB::table('cash_accounts')->insertGetId([
                'name' => 'Kas Setoran', 'account_id' => $settlementAccount,
                'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('cash_bank_seed_ownership')->insert(['cash_account_id' => $cashId]);
        }
        if (DB::table('cash_categories')->where('name', 'Setor tunai ke bank')->count() > 1) {
            throw new RuntimeException('Ada lebih dari satu kategori setor bank; rapikan datanya sebelum migrasi.');
        }
        $category = DB::table('cash_categories')->where('name', 'Setor tunai ke bank')->first();
        if ($category && ($category->direction !== 'OUT' || $category->account_id != $bankAccount || ! $category->is_active)) {
            throw new RuntimeException('Kategori setor bank sudah ada dengan pemetaan salah atau tidak aktif.');
        }
        if (! $category) {
            $categoryId = DB::table('cash_categories')->insertGetId([
                'name' => 'Setor tunai ke bank', 'direction' => 'OUT', 'account_id' => $bankAccount,
                'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('cash_bank_seed_ownership')->insert(['cash_category_id' => $categoryId]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('cash_bank_seed_ownership')->get() as $owned) {
            if ($owned->cash_account_id && DB::table('cash_transactions')->where('cash_account_id', $owned->cash_account_id)->exists()) {
                throw new RuntimeException('Kas Setoran sudah dipakai transaksi; simpan datanya sebelum rollback.');
            }
            if ($owned->cash_account_id && DB::table('journal_lines')->where('account_id', DB::table('cash_accounts')
                ->where('id', $owned->cash_account_id)->value('account_id'))->exists()) {
                throw new RuntimeException('Kas Setoran sudah punya jurnal; simpan datanya sebelum rollback.');
            }
            if ($owned->cash_category_id && DB::table('cash_transactions')->where('cash_category_id', $owned->cash_category_id)->exists()) {
                throw new RuntimeException('Kategori setor bank sudah dipakai transaksi; simpan datanya sebelum rollback.');
            }
        }
        foreach (DB::table('cash_bank_seed_ownership')->get() as $owned) {
            if ($owned->cash_account_id) {
                $row = DB::table('cash_accounts')->where('id', $owned->cash_account_id)->first();
                if (! $row || $row->name !== 'Kas Setoran' || ! $row->is_active
                    || $row->account_id != DB::table('accounts')->where('code', '1-1200')->value('id')) {
                    throw new RuntimeException('Kas Setoran sudah diubah; simpan data sebelum rollback.');
                }
            }
            if ($owned->cash_category_id) {
                $row = DB::table('cash_categories')->where('id', $owned->cash_category_id)->first();
                if (! $row || $row->name !== 'Setor tunai ke bank' || $row->direction !== 'OUT' || ! $row->is_active
                    || $row->account_id != DB::table('accounts')->where('code', '1-1300')->value('id')) {
                    throw new RuntimeException('Kategori setor bank sudah diubah; simpan data sebelum rollback.');
                }
            }
        }
        foreach (DB::table('cash_bank_seed_ownership')->get() as $owned) {
            if ($owned->cash_category_id) {
                DB::table('cash_bank_seed_ownership')->where('id', $owned->id)->delete();
                DB::table('cash_categories')->where('id', $owned->cash_category_id)->delete();
            }
            if ($owned->cash_account_id) {
                DB::table('cash_bank_seed_ownership')->where('id', $owned->id)->delete();
                DB::table('cash_accounts')->where('id', $owned->cash_account_id)->delete();
            }
        }
        Schema::dropIfExists('cash_bank_seed_ownership');
    }
};
