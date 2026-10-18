<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->index(['entry_date', 'id'], 'journal_entries_report_date_idx');
        });
        Schema::table('settlements', function (Blueprint $table): void {
            $table->index(['operating_date', 'cart_id'], 'settlements_report_date_idx');
        });
    }

    public function down(): void
    {
        Schema::table('settlements', function (Blueprint $table): void {
            $table->dropIndex('settlements_report_date_idx');
        });
        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->dropIndex('journal_entries_report_date_idx');
        });
    }
};
