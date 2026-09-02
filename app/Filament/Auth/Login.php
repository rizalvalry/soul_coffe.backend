<?php

namespace App\Filament\Auth;

use App\Support\PhoneNumber;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Filament authenticates by email out of the box. This application has no email column at all —
 * `users` is keyed on `phone_e164`, because the people who use it are field staff who are
 * identified by phone number and nothing else.
 *
 * Three overrides are the whole change: the field the form shows, the credentials that field
 * turns into, and where a failure message is attached (the parent hangs it on `data.email`,
 * which no longer exists here and would render an error the user cannot see).
 */
class Login extends BaseLogin
{
    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('phone')
            ->label('Nomor HP')
            ->tel()
            ->placeholder('08xx / +62xx')
            ->required()
            ->autocomplete('username')
            ->autofocus();
    }

    /**
     * The panel and the API must agree on what a phone number is, or an account created in one
     * cannot log into the other. Both normalise through PhoneNumber.
     */
    protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    {
        return [
            'phone_e164' => PhoneNumber::normalize($data['phone']),
            'password' => $data['password'],
        ];
    }

    protected function throwFailureValidationException(): never
    {
        // Deliberately identical whether the number is unknown, the password is wrong, or the
        // account is deactivated — same reasoning as AuthController::login().
        throw ValidationException::withMessages([
            'data.phone' => 'Nomor HP atau kata sandi salah.',
        ]);
    }
}
