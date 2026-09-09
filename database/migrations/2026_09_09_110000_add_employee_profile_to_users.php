<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Biodata for the people the company employs, plus a free-text note per person.
 *
 * EVERY column here is nullable and none has a default that implies a fact. That is the
 * requirement: a record must save cleanly with all of this left blank, because HR data arrives
 * in pieces — someone starts work today and brings their bank details next week. A required
 * field here would block the one thing the panel must always be able to do, which is record that
 * a person exists.
 *
 * `national_id` is the KTP number and is deliberately NOT the same column as `nik`, which is the
 * employee number the absensi sheet prints. Two different identifiers issued by two different
 * authorities; collapsing them would corrupt both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('email')->nullable()->after('phone_e164');
            $table->string('national_id', 32)->nullable()->after('nik'); // KTP
            $table->date('birth_date')->nullable()->after('national_id');
            $table->string('birth_place')->nullable()->after('birth_date');
            $table->string('gender', 16)->nullable()->after('birth_place'); // L | P
            $table->string('marital_status', 24)->nullable()->after('gender');
            $table->text('address')->nullable()->after('marital_status');
            $table->date('joined_at')->nullable()->after('address');
            $table->string('emergency_contact_name')->nullable()->after('joined_at');
            $table->string('emergency_contact_phone')->nullable()->after('emergency_contact_name');
            $table->string('bank_name')->nullable()->after('emergency_contact_phone');
            $table->string('bank_account_number', 64)->nullable()->after('bank_name');
            $table->string('bank_account_holder')->nullable()->after('bank_account_number');
            // Free-text note per person, asked for separately from the biodata above: somewhere
            // to write the thing that has no field of its own.
            $table->text('notes')->nullable()->after('bank_account_holder');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'email',
                'national_id',
                'birth_date',
                'birth_place',
                'gender',
                'marital_status',
                'address',
                'joined_at',
                'emergency_contact_name',
                'emergency_contact_phone',
                'bank_name',
                'bank_account_number',
                'bank_account_holder',
                'notes',
            ]);
        });
    }
};
