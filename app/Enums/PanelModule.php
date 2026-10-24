<?php

namespace App\Enums;

/**
 * Every menu in the admin panel that the access matrix can grant or withhold, by name.
 *
 * Every case here governs a real CMS or back-office feature, including News Feed and access settings.
 *
 * A closed list rather than free strings so that a typo in a resource's `permissionModule()`
 * is a compile-time class error, not a silently-unreachable menu — and so the matrix editor can
 * enumerate exactly what exists instead of guessing from the database.
 */
enum PanelModule: string
{
    case DASHBOARD = 'dashboard';
    case USERS = 'users';
    case DEVICES = 'devices';
    case CUSTOMERS = 'customers';
    case PRODUCTS = 'products';
    case CARTS = 'carts';
    case LOCATIONS = 'locations';
    case CENTRAL_KITCHENS = 'central_kitchens';
    case DAILY_TARGETS = 'daily_targets';
    case RIDER_COMMISSIONS = 'rider_commissions';
    case RIDER_INCENTIVE_BALANCE = 'rider_incentive_balance';
    case CENTRAL_STOCK = 'central_stock';
    case STOCK_OPNAME = 'stock_opname';
    case SALES = 'sales';
    case SETTLEMENTS = 'settlements';
    case DELIVERY_INCIDENTS = 'delivery_incidents';
    case DELIVERY_INCIDENT_DECISIONS = 'delivery_incident_decisions';
    case SALE_CORRECTIONS = 'sale_corrections';
    case ALLOCATIONS = 'allocations';
    case KITCHEN_OPERATIONS = 'kitchen_operations';
    case CART_CLOSE_OUT = 'cart_close_out';
    case KITCHEN_REJECT = 'kitchen_reject';
    case CLOSING_LOCATION_EVIDENCE = 'closing_location_evidence';
    case DIRECT_SALE_ACTIONS = 'direct_sale_actions';
    case PURCHASE_ORDER_ACTIONS = 'purchase_order_actions';
    case STOCK_OPNAME_ACTIONS = 'stock_opname_actions';
    case STOCK_ADJUSTMENTS = 'stock_adjustments';
    case STAFF_ACTIVITY = 'staff_activity';
    case ATTENDANCE = 'attendance';
    case ABSEN_EXEMPTIONS = 'absen_exemptions';
    case REPORTS = 'reports';
    case AUDIT_LOGS = 'audit_logs';
    case PIN_RESET_REQUESTS = 'pin_reset_requests';
    case AI_SETTINGS = 'ai_settings';
    case LOCATION_SETTINGS = 'location_settings';

    // Phase 2 — raw materials & recipes (BOM), and the purchasing that brings stock in with a
    // real cost. RAW_MATERIALS/SUPPLIERS/RECIPES/PURCHASE_ORDERS are full-CRUD-shaped modules;
    // PRODUCTION is a fact log, see isReadOnly() below.
    case RAW_MATERIALS = 'raw_materials';
    case SUPPLIERS = 'suppliers';
    case RECIPES = 'recipes';
    case PURCHASE_ORDERS = 'purchase_orders';
    case PRODUCTION = 'production';

    // Walk-in sales at the kitchen or office, recorded by an account with action access
    // straight into a cart's stock. Its listing is read-only; direct_sale_actions governs
    // both recording and voiding.
    case DIRECT_SALES = 'direct_sales';
    case RIDER_LOCATION_SETUP = 'rider_location_setup';
    case MANAGEMENT_REPORTS = 'management_reports';
    case PAYROLL = 'payroll';
    case EMPLOYEE_ADVANCES = 'employee_advances';
    case PAYROLL_PRIOR_WITHHOLDING = 'payroll_prior_withholding';
    case TAX_SETTINGS = 'tax_settings';
    case ACCOUNTING_PERIODS = 'accounting_periods';
    case OPENING_BALANCES = 'opening_balances';
    case CHART_OF_ACCOUNTS = 'chart_of_accounts';
    case JOURNALS = 'journals';
    case JOURNAL_REVERSALS = 'journal_reversals';
    case GENERAL_LEDGER = 'general_ledger';
    case CASH = 'cash';
    case CLEARING = 'clearing';
    case FIXED_ASSETS = 'fixed_assets';
    case FIXED_ASSET_DEPRECIATION = 'fixed_asset_depreciation';
    case INVENTORY_COSTS = 'inventory_costs';
    case REFILL_APPROVAL = 'refill_approval';
    case EMPLOYEES = 'employees';
    case ROLE_MATRIX = 'role_matrix';
    case MENU_NAMING = 'menu_naming';
    case NEWS_FEED = 'news_feed';

    public function label(): string
    {
        return match ($this) {
            self::DASHBOARD => 'Dashboard',
            self::USERS => 'Pengguna & Role',
            self::DEVICES => 'Perangkat Login',
            self::CUSTOMERS => 'Pelanggan',
            self::PRODUCTS => 'Produk',
            self::CARTS => 'Coffee Bike',
            self::LOCATIONS => 'Lokasi',
            self::CENTRAL_KITCHENS => 'Dapur Pusat',
            self::DAILY_TARGETS => 'Target Harian',
            self::RIDER_COMMISSIONS => 'Target & Komisi',
            self::RIDER_INCENTIVE_BALANCE => 'Saldo Insentif Rider',
            self::CENTRAL_STOCK => 'Stok Terpusat',
            self::STOCK_OPNAME => 'Stock Opname',
            self::SALES => 'Penjualan Coffee Bike',
            self::SETTLEMENTS => 'Setoran Harian',
            self::DELIVERY_INCIDENTS => 'Insiden Pengiriman',
            self::DELIVERY_INCIDENT_DECISIONS => 'Keputusan Insiden',
            self::SALE_CORRECTIONS => 'Pembatalan Penjualan',
            self::ALLOCATIONS => 'Alokasi Harian',
            self::KITCHEN_OPERATIONS => 'Operasional Dapur di HP',
            self::CART_CLOSE_OUT => 'Tutup Coffee Bike',
            self::KITCHEN_REJECT => 'Reject Dapur',
            self::CLOSING_LOCATION_EVIDENCE => 'Bukti Lokasi Closing',
            self::DIRECT_SALE_ACTIONS => 'Aksi Penjualan Langsung',
            self::PURCHASE_ORDER_ACTIONS => 'Aksi Pembelian',
            self::STOCK_OPNAME_ACTIONS => 'Aksi Stock Opname',
            self::STOCK_ADJUSTMENTS => 'Koreksi Stok',
            self::STAFF_ACTIVITY => 'Aktivitas Rider',
            self::ATTENDANCE => 'Laporan Absensi',
            self::ABSEN_EXEMPTIONS => 'Izin Absen Luar Lokasi',
            self::REPORTS => 'Laporan & Ekspor',
            self::AUDIT_LOGS => 'Audit Trail',
            self::PIN_RESET_REQUESTS => 'Permintaan Reset PIN',
            self::AI_SETTINGS => 'Pengaturan AI',
            self::LOCATION_SETTINGS => 'Pengaturan Lokasi',
            self::RAW_MATERIALS => 'Bahan Baku',
            self::SUPPLIERS => 'Pemasok',
            self::RECIPES => 'Resep',
            self::PURCHASE_ORDERS => 'Pembelian Bahan Baku',
            self::PRODUCTION => 'Produksi',
            self::DIRECT_SALES => 'Penjualan Langsung Kantor',
            self::RIDER_LOCATION_SETUP => 'Setup Lokasi Rider',
            self::MANAGEMENT_REPORTS => 'Laporan Management',
            self::PAYROLL => 'Payroll',
            self::EMPLOYEE_ADVANCES => 'Kasbon Karyawan',
            self::PAYROLL_PRIOR_WITHHOLDING => 'PPh Sebelumnya',
            self::TAX_SETTINGS => 'Pengaturan Pajak',
            self::ACCOUNTING_PERIODS => 'Periode Akuntansi',
            self::OPENING_BALANCES => 'Saldo Awal',
            self::CHART_OF_ACCOUNTS => 'Bagan Akun',
            self::JOURNALS => 'Jurnal',
            self::JOURNAL_REVERSALS => 'Balik Jurnal',
            self::GENERAL_LEDGER => 'Buku Besar',
            self::CASH => 'Kas dan Barang Kantor',
            self::CLEARING => 'Dana Masuk',
            self::FIXED_ASSETS => 'Aset Tetap',
            self::FIXED_ASSET_DEPRECIATION => 'Penyusutan Aset',
            self::INVENTORY_COSTS => 'Biaya Stok',
            self::REFILL_APPROVAL => 'Approval Refill',
            self::EMPLOYEES => 'Karyawan',
            self::ROLE_MATRIX => 'Management Users Role',
            self::MENU_NAMING => 'Penamaan Menu',
            self::NEWS_FEED => 'News Feed',
        };
    }

    public function accessHint(): ?string
    {
        return $this === self::PAYROLL
            ? 'Lihat = slip gaji sendiri di aplikasi; Kelola = halaman Payroll CMS'
            : null;
    }

    /**
     * Read-only listing modules offer only Lihat. Their separate action modules govern writes
     * such as voids, incident decisions, stock opname, and stock adjustments.
     */
    public function isReadOnly(): bool
    {
        return in_array($this, [
            self::DASHBOARD,
            self::REPORTS,
            self::MANAGEMENT_REPORTS,
            self::GENERAL_LEDGER,
            self::AUDIT_LOGS,
            self::STAFF_ACTIVITY,
            self::STOCK_OPNAME,
            self::SALES,
            self::DELIVERY_INCIDENTS,
            self::DIRECT_SALES,
            // A brew is a fact about what happened in the kitchen, backed by ledger rows that are
            // themselves append-only — there is nothing here to create or edit, only to read.
            self::PRODUCTION,
        ], true);
    }

    public function group(): string
    {
        return match ($this) {
            self::USERS, self::DEVICES, self::CUSTOMERS, self::EMPLOYEES, self::ROLE_MATRIX, self::AUDIT_LOGS,
            self::PIN_RESET_REQUESTS, self::AI_SETTINGS, self::LOCATION_SETTINGS, self::MENU_NAMING,
            self::NEWS_FEED => 'Pengguna & Pengaturan',
            self::REPORTS, self::MANAGEMENT_REPORTS, self::PAYROLL, self::EMPLOYEE_ADVANCES,
            self::RIDER_INCENTIVE_BALANCE,
            self::PAYROLL_PRIOR_WITHHOLDING, self::TAX_SETTINGS, self::ACCOUNTING_PERIODS,
            self::OPENING_BALANCES, self::CHART_OF_ACCOUNTS, self::JOURNALS,
            self::JOURNAL_REVERSALS, self::GENERAL_LEDGER, self::CASH,
            self::CLEARING, self::FIXED_ASSETS, self::FIXED_ASSET_DEPRECIATION,
            self::INVENTORY_COSTS => 'Keuangan',
            default => 'Operasional',
        };
    }
}
