<?php

namespace App\Enums;

/**
 * Every menu in the admin panel that the access matrix can grant or withhold, by name.
 *
 * A closed list rather than free strings so that a typo in a resource's `permissionModule()`
 * is a compile-time class error, not a silently-unreachable menu — and so the matrix editor can
 * enumerate exactly what exists instead of guessing from the database.
 */
enum PanelModule: string
{
    case DASHBOARD = 'dashboard';
    case USERS = 'users';
    case PRODUCTS = 'products';
    case CARTS = 'carts';
    case LOCATIONS = 'locations';
    case CENTRAL_KITCHENS = 'central_kitchens';
    case DAILY_TARGETS = 'daily_targets';
    case STAFF_ASSIGNMENTS = 'staff_assignments';
    case ATTENDANCE = 'attendance';
    case REPORTS = 'reports';
    case AUDIT_LOGS = 'audit_logs';
    case PIN_RESET_REQUESTS = 'pin_reset_requests';
    case NEWS_POSTS = 'news_posts';
    case AI_SETTINGS = 'ai_settings';

    public function label(): string
    {
        return match ($this) {
            self::DASHBOARD => 'Dashboard',
            self::USERS => 'Pengguna & Role',
            self::PRODUCTS => 'Produk',
            self::CARTS => 'Gerobak',
            self::LOCATIONS => 'Lokasi',
            self::CENTRAL_KITCHENS => 'Dapur Pusat',
            self::DAILY_TARGETS => 'Target Harian',
            self::STAFF_ASSIGNMENTS => 'Penugasan Staff',
            self::ATTENDANCE => 'Laporan Absensi',
            self::REPORTS => 'Laporan & Ekspor',
            self::AUDIT_LOGS => 'Audit Trail',
            self::PIN_RESET_REQUESTS => 'Permintaan Reset PIN',
            self::NEWS_POSTS => 'News Feed',
            self::AI_SETTINGS => 'Pengaturan AI',
        };
    }

    /**
     * Modules that are read-only by nature — there is nothing to create or edit, so the matrix
     * editor offers them only as "Lihat", never "Kelola".
     */
    public function isReadOnly(): bool
    {
        return in_array($this, [self::DASHBOARD, self::REPORTS, self::AUDIT_LOGS], true);
    }
}
