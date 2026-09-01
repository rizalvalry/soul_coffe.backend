<?php

namespace App\Enums;

/**
 * refill_requests.signature_method (R5, E7). Values match the API contract's
 * `signature_method` field verbatim (`POST /refills/{id}/deliver`).
 */
enum SignatureMethod: string
{
    case STAFF_SIGNATURE = 'staff_signature';
    case PIN_FALLBACK = 'pin_fallback';
}
