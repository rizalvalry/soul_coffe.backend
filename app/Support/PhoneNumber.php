<?php

namespace App\Support;

/**
 * The one authoritative place where a phone number is put into canonical form.
 *
 * Canonical form is the local Indonesian one: 08xxxxxxxxxx. Every user of this system is an
 * Indonesian field worker who reads and dictates their number that way, so that is what gets
 * stored and shown. Callers may type 08…, 62…, or +62… — all three land on the same value here,
 * which is what lets someone log in with whatever shape they happen to type.
 *
 * This rule used to be a private method on AuthController. It now has more callers — the admin
 * panel both authenticates by phone and creates users by phone — and a format rule living in two
 * places is a format rule that will eventually disagree with itself: a user saved in one shape
 * would simply never match a login normalised to another, with nothing reporting an error.
 * Callers must not hand-roll their own variant.
 */
final class PhoneNumber
{
    /**
     * Normalise `08…`, `62…`, `+62…` to `08…`.
     */
    public static function normalize(string $raw): string
    {
        $digits = preg_replace('/[^\d+]/', '', trim($raw)) ?? '';

        return match (true) {
            str_starts_with($digits, '+62') => '0'.substr($digits, 3),
            str_starts_with($digits, '62') => '0'.substr($digits, 2),
            str_starts_with($digits, '0') => $digits,
            default => '0'.$digits,
        };
    }
}
