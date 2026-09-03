<?php

use App\Support\PhoneNumber;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Canonical phone format changed from E.164 (+628…) to the local Indonesian form (08…), which is
 * how every user of this system reads and dictates their own number.
 *
 * Rows written before the change still hold the old shape, and login looks up exactly one shape
 * — so without this migration every existing account, the administrator included, is locked out
 * with "Nomor HP atau kata sandi salah" and no way to tell that the password was never the
 * problem. That is not hypothetical: it is precisely how this surfaced, from a number edited
 * straight into the database in a shape the lookup could never match.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('users')->select('id', 'phone_e164')->get() as $user) {
            $normalized = PhoneNumber::normalize($user->phone_e164);

            if ($normalized === $user->phone_e164) {
                continue;
            }

            // Never merge two accounts by normalising one onto another's number. If the target
            // is taken, leave the row alone and let a human decide which account is real.
            $taken = DB::table('users')
                ->where('phone_e164', $normalized)
                ->where('id', '!=', $user->id)
                ->exists();

            if ($taken) {
                continue;
            }

            DB::table('users')->where('id', $user->id)->update(['phone_e164' => $normalized]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('users')->select('id', 'phone_e164')->get() as $user) {
            if (! str_starts_with($user->phone_e164, '0')) {
                continue;
            }

            $reverted = '+62'.substr($user->phone_e164, 1);

            $taken = DB::table('users')
                ->where('phone_e164', $reverted)
                ->where('id', '!=', $user->id)
                ->exists();

            if ($taken) {
                continue;
            }

            DB::table('users')->where('id', $user->id)->update(['phone_e164' => $reverted]);
        }
    }
};
