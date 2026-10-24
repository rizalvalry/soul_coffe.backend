<?php

namespace App\Enums;

enum WasteReason: string
{
    case EXPIRED = 'EXPIRED';
    case DAMAGED = 'DAMAGED';
    case DISCARDED = 'DISCARDED';
    case PROMOTION = 'PROMOTION';

    public function label(): string
    {
        return match ($this) {
            self::EXPIRED => 'Expired',
            self::DAMAGED => 'Rusak/pecah',
            self::DISCARDED => 'Terbuang',
            self::PROMOTION => 'Konten/lapak',
        };
    }

    public static function fromKitchenReject(KitchenRejectReason $reason): self
    {
        return match ($reason) {
            KitchenRejectReason::DROPPED, KitchenRejectReason::PACKAGING => self::DAMAGED,
            KitchenRejectReason::QUALITY, KitchenRejectReason::OTHER => self::DISCARDED,
        };
    }

    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $reason) {
            $options[$reason->value] = $reason->label();
        }

        return $options;
    }
}
