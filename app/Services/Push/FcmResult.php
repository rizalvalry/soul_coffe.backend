<?php

namespace App\Services\Push;

enum FcmResult: string
{
    case Sent = 'sent';

    /** The device token is dead (app uninstalled, token rotated). Delete the registration. */
    case Unregistered = 'unregistered';

    /** Our bearer token was refused; retried once internally, surfaced only if that failed too. */
    case Unauthorized = 'unauthorized';

    /** Transport or provider error; the registration stays, the delivery is lost. */
    case Failed = 'failed';
}
