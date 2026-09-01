<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->string('kind'); // 'evidence' | 'signature'
            $table->string('path');
            $table->string('mime');
            $table->unsignedBigInteger('bytes');
            $table->string('sha256', 64);
            $table->string('phash', 64)->nullable();
            $table->timestamp('exif_taken_at')->nullable();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            // Dedup windows differ by kind and are enforced at the application layer,
            // not by a DB-level unique constraint: evidence photos are deduped by
            // sha256 within a rolling 7 days (E6), while a signature sha256 must never
            // be reused at all (R13). A single unique index cannot express both rules,
            // so this stays an indexed lookup column instead.
            $table->index(['kind', 'sha256']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
