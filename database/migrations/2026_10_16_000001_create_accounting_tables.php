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
        Schema::create('accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->string('type', 16);
            $table->string('normal_balance', 6);
            $table->foreignId('parent_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        Schema::create('accounting_periods', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->string('status', 6)->default('OPEN');
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->unique(['year', 'month']);
        });

        Schema::create('journal_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 16)->unique();
            $table->date('entry_date');
            $table->foreignId('period_id')->constrained('accounting_periods')->restrictOnDelete();
            $table->string('description');
            $table->string('source_type', 80);
            $table->unsignedBigInteger('source_id');
            $table->string('purpose', 40);
            $table->string('status', 6)->default('POSTED');
            $table->foreignId('reverses_entry_id')->nullable()->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['source_type', 'source_id', 'purpose']);
            $table->index(['period_id', 'number']);
        });

        Schema::create('journal_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('entry_id')->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->unsignedBigInteger('debit_minor')->default(0);
            $table->unsignedBigInteger('credit_minor')->default(0);
            $table->foreignId('cart_id')->nullable()->constrained('carts')->restrictOnDelete();
            $table->string('memo')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['account_id', 'entry_id']);
        });

        // Existing installations run migrate without db:seed; the system chart must be ready.
        app(AccountSeeder::class)->run();
    }

    public function down(): void
    {
        if (DB::table('journal_entries')->exists()
            || DB::table('accounting_periods')->exists()
            || DB::table('accounts')->where('is_system', false)->exists()) {
            throw new RuntimeException('Accounting data must be preserved before rolling back accounting tables.');
        }

        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('accounting_periods');
        Schema::dropIfExists('accounts');
    }
};
