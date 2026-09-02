<?php

namespace App\Support;

/**
 * The one authoritative place where a phone number becomes E.164.
 *
 * This rule used to live as a private method on AuthController. It now has a second caller —
 * the admin panel, which both authenticates by phone and creates users by phone — and a format
 * rule that exists in two places is a format rule that will eventually disagree with itself:
 * a user created through the panel as `0811…` would simply never match a login normalised to
 * `+62811…`. Callers must not hand-roll their own variant.
 */
final class PhoneNumber
{
    /**
     * Normalise `08…`, `62…`, `+62…` to `+62…`.
     */
    public static function normalize(string $raw): string
    {
        $digits = preg_replace('/[^\d+]/', '', trim($raw)) ?? '';

        return match (true) {
            str_starts_with($digits, '+62') => $digits,
            str_starts_with($digits, '62') => '+'.$digits,
            str_starts_with($digits, '0') => '+62'.substr($digits, 1),
            default => '+62'.$digits,
        };
    }
}
