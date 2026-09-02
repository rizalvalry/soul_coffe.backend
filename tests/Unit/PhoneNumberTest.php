<?php

namespace Tests\Unit;

use App\Support\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Canonical form is the local Indonesian one. This is the rule the API login, the panel login and
 * the panel's user form all share, so a change here silently changes who can sign in — which is
 * exactly how an administrator once ended up locked out by their own phone number.
 */
class PhoneNumberTest extends TestCase
{
    public static function numbers(): array
    {
        return [
            'sudah lokal' => ['085781571742', '085781571742'],
            'E.164' => ['+6285781571742', '085781571742'],
            'awalan 62' => ['6285781571742', '085781571742'],
            'ada spasi' => [' 0857 8157 1742 ', '085781571742'],
            'ada strip' => ['0857-8157-1742', '085781571742'],
            'tanpa awalan apa pun' => ['85781571742', '085781571742'],
        ];
    }

    #[DataProvider('numbers')]
    public function test_every_shape_lands_on_the_same_local_number(string $input, string $expected): void
    {
        $this->assertSame($expected, PhoneNumber::normalize($input));
    }

    public function test_normalising_twice_changes_nothing(): void
    {
        // Idempotence matters because a value is normalised on the way into the database and
        // again on the way into a lookup; if the second pass moved it, nothing would ever match.
        $once = PhoneNumber::normalize('+6285781571742');

        $this->assertSame($once, PhoneNumber::normalize($once));
    }
}
