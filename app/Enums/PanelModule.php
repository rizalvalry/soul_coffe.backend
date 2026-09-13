<?php

namespace App\Enums;

/**
 * Every menu in the admin panel that the access matrix can grant or withhold, by name.
 *
 * Every case here governs something real. News Feed is deliberately ABSENT: its
 * Administrator + Content Creator pairing is the feature itself (see NewsPostResource), so it
 * stays hardcoded rather than becoming a switch an administrator could turn off.
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
    case CENTRAL_STOCK = 'central_stock';
    case STOCK_OPNAME = 'stock_opname';
    case SALES = 'sales';
    case SETTLEMENTS = 'settlements';
    case DELIVERY_INCIDENTS = 'delivery_incidents';
    case STAFF_ACTIVITY = 'staff_activity';
    case ATTENDANCE = 'attendance';
    case ABSEN_EXEMPTIONS = 'absen_exemptions';
    case REPORTS = 'reports';
    case AUDIT_LOGS = 'audit_logs';
    case PIN_RESET_REQUESTS = 'pin_reset_requests';
    case AI_SETTINGS = 'ai_settings';

    // Phase 2 — raw materials & recipes (BOM), and the purchasing that brings stock in with a
    // real cost. RAW_MATERIALS/SUPPLIERS/RECIPES/PURCHASE_ORDERS are full-CRUD-shaped modules;
    // PRODUCTION is a fact log, see isReadOnly() below.
    case RAW_MATERIALS = 'raw_materials';
    case SUPPLIERS = 'suppliers';
    case RECIPES = 'recipes';
    case PURCHASE_ORDERS = 'purchase_orders';
    case PRODUCTION = 'production';

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
            self::CENTRAL_STOCK => 'Stok Terpusat',
            self::STOCK_OPNAME => 'Stock Opname',
            self::SALES => 'Penjualan Gerobak',
            self::SETTLEMENTS => 'Setoran Harian',
            self::DELIVERY_INCIDENTS => 'Insiden Pengiriman',
            self::STAFF_ACTIVITY => 'Aktivitas Staff',
            self::ATTENDANCE => 'Laporan Absensi',
            self::ABSEN_EXEMPTIONS => 'Izin Absen Luar Lokasi',
            self::REPORTS => 'Laporan & Ekspor',
            self::AUDIT_LOGS => 'Audit Trail',
            self::PIN_RESET_REQUESTS => 'Permintaan Reset PIN',
            self::AI_SETTINGS => 'Pengaturan AI',
            self::RAW_MATERIALS => 'Bahan Baku',
            self::SUPPLIERS => 'Pemasok',
            self::RECIPES => 'Resep',
            self::PURCHASE_ORDERS => 'Pembelian Bahan Baku',
            self::PRODUCTION => 'Produksi',
        };
    }

    /**
     * Modules that are read-only by nature — there is nothing to create or edit, so the matrix
     * editor offers them only as "Lihat", never "Kelola".
     *
     * SALES is deliberately NOT on this list, even though the resource still hardcodes
     * `canCreate`/`canEdit`/`canDelete` to false and offers no free-form edit form. A sale can be
     * VOIDED — the one legitimate correction, which reverses the cups through the stock ledger
     * rather than rewriting the row — and that single action is what the matrix's "edit" ability
     * governs here (SalesTable::mayVoid()). The same pattern DELIVERY_INCIDENTS already uses for
     * its two decision buttons.
     *
     * STAFF_ACTIVITY stays read-only: the GPS trail is evidence, and evidence that can be edited
     * is not evidence.
     */
    public function isReadOnly(): bool
    {
        return in_array($this, [
            self::DASHBOARD,
            self::REPORTS,
            self::AUDIT_LOGS,
            self::CENTRAL_STOCK,
            // Deposits are taken on the phone at the desk, with the money in hand. The panel is
            // where they are read and reported on — editing one here would be rewriting a
            // reconciliation after the fact, with nobody standing there to disagree.
            self::SETTLEMENTS,
            self::STAFF_ACTIVITY,
            // A brew is a fact about what happened in the kitchen, backed by ledger rows that are
            // themselves append-only — there is nothing here to create or edit, only to read.
            self::PRODUCTION,
        ], true);
    }
}
