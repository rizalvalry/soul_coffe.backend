<?php

namespace App\Enums;

/**
 * Employee roles, in the business-given priority order (§2 of the business spec).
 * The server decides the role on login; a role supplied by the client is ignored.
 */
enum Role: string
{
    case ADMINISTRATOR = 'ADMINISTRATOR';
    case FINANCE = 'FINANCE';
    case BARISTA = 'BARISTA';
    case RIDER = 'RIDER';
    case STAFF = 'STAFF';

    /**
     * Writes and schedules the in-app news feed. Added after the original five (§2), so it sits
     * last: the priority order above is business hierarchy over the OPERATIONAL flow, and this
     * role touches none of it. It never sees a refill request, an allocation, a price, or a
     * settlement — see canAccessPanel() and NewsPostPolicy.
     */
    case CONTENT_CREATOR = 'CONTENT_CREATOR';

    public function label(): string
    {
        return match ($this) {
            self::ADMINISTRATOR => 'Administrator',
            self::FINANCE => 'Finance',
            self::BARISTA => 'Barista',
            self::RIDER => 'Rider',
            self::STAFF => 'Staff',
            self::CONTENT_CREATOR => 'Content Creator',
        };
    }

    /** Roles that take part in the daily refill/allocation flow. */
    public function isOperational(): bool
    {
        return $this !== self::CONTENT_CREATOR;
    }
}
