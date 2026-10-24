<?php

use App\Enums\KitchenRejectReason;
use App\Enums\WasteReason;
use App\Services\Access\PermissionMatrix;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MARKER = '2026-10-24 00:01:21';

    public function up(): void
    {
        if (! Schema::hasIndex('stock_ledger', 'stock_ledger_waste_month_idx')) {
            Schema::table('stock_ledger', function (Blueprint $table): void {
                $table->index(['movement_type', 'created_at', 'kitchen_id', 'product_id'], 'stock_ledger_waste_month_idx');
            });
        }
        if (! Schema::hasColumn('raw_materials', 'is_packaging_cup')) {
            Schema::table('raw_materials', function (Blueprint $table): void {
                $table->boolean('is_packaging_cup')->default(false);
            });
        }
        if (Schema::hasTable('t081_packaging_flags_backup')) {
            foreach (DB::table('t081_packaging_flags_backup')->get() as $row) {
                DB::table('raw_materials')->where('id', $row->raw_material_id)
                    ->update(['is_packaging_cup' => true]);
            }
            Schema::drop('t081_packaging_flags_backup');
        }
        if (Schema::hasTable('t081_waste_classifications_backup')) {
            Schema::rename('t081_waste_classifications_backup', 'waste_classifications');
        } elseif (! Schema::hasTable('waste_classifications')) {
            Schema::create('waste_classifications', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('stock_ledger_id')->unique()->constrained('stock_ledger')->restrictOnDelete();
                $table->string('reason_code', 20);
                $table->timestamps();
                $table->index('reason_code');
            });
        }
        if (Schema::hasTable('t081_promotional_uses_backup')) {
            Schema::rename('t081_promotional_uses_backup', 'promotional_uses');
        } elseif (! Schema::hasTable('promotional_uses')) {
            Schema::create('promotional_uses', function (Blueprint $table): void {
                $table->id();
                $table->uuid('request_key')->unique();
                $table->char('payload_hash', 64);
                $table->foreignId('kitchen_id')->constrained('central_kitchens')->restrictOnDelete();
                $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
                $table->unsignedInteger('qty');
                $table->string('note', 500);
                $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
                $table->foreignId('stock_ledger_id')->nullable()->unique()->constrained('stock_ledger')->restrictOnDelete();
                $table->timestamps();
            });
        }
        if (Schema::hasTable('t081_packaging_wastes_backup')) {
            Schema::rename('t081_packaging_wastes_backup', 'packaging_wastes');
        } elseif (! Schema::hasTable('packaging_wastes')) {
            Schema::create('packaging_wastes', function (Blueprint $table): void {
                $table->id();
                $table->uuid('request_key')->unique();
                $table->char('payload_hash', 64);
                $table->date('operating_date');
                $table->foreignId('kitchen_id')->constrained('central_kitchens')->restrictOnDelete();
                $table->foreignId('raw_material_id')->constrained('raw_materials')->restrictOnDelete();
                $table->unsignedInteger('qty');
                $table->unsignedBigInteger('cost_minor');
                $table->foreignId('stock_ledger_id')->nullable()->unique()->constrained('stock_ledger')->restrictOnDelete();
                $table->string('note', 500)->nullable();
                $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
                $table->timestamps();
                $table->index(['operating_date', 'kitchen_id']);
            });
        }
        if (Schema::hasTable('t081_waste_reason_corrections_backup')) {
            Schema::rename('t081_waste_reason_corrections_backup', 'waste_reason_corrections');
        } elseif (! Schema::hasTable('waste_reason_corrections')) {
            Schema::create('waste_reason_corrections', function (Blueprint $table): void {
                $table->uuid('request_key')->primary();
                $table->foreignId('classification_id')->constrained('waste_classifications')->restrictOnDelete();
                $table->char('payload_hash', 64);
                $table->timestamp('created_at')->useCurrent();
            });
        }
        if (! Schema::hasTable('t081_permission_snapshots')) {
            Schema::create('t081_permission_snapshots', function (Blueprint $table): void {
                $table->string('role', 32)->primary();
                $table->unsignedBigInteger('role_permission_id')->nullable();
                $table->text('prior_abilities')->nullable();
                $table->timestamp('prior_updated_at')->nullable();
            });
        }
        DB::table('stock_ledger')->where('movement_type', 'WASTE_OUT')->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $reason = match ($row->ref_type) {
                        'rider_closing', 'cart_closeout_reject', 'settlement_reject', 'delivery_incident' => WasteReason::DAMAGED,
                        'refill_expired' => WasteReason::EXPIRED,
                        'kitchen_reject' => WasteReason::fromKitchenReject(
                            KitchenRejectReason::tryFrom((string) DB::table('kitchen_rejects')
                                ->where('id', $row->ref_id)->value('reason_code')) ?? KitchenRejectReason::OTHER),
                        default => WasteReason::DISCARDED,
                    };
                    DB::table('waste_classifications')->insertOrIgnore([
                        'stock_ledger_id' => $row->id, 'reason_code' => $reason->value,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            });
        DB::table('accounts')->insertOrIgnore([
            'code' => '6-1930', 'name' => 'Beban Promosi Konten/Lapak', 'type' => 'EXPENSE',
            'normal_balance' => 'DEBIT', 'is_active' => true, 'is_system' => true,
            'created_at' => self::MARKER, 'updated_at' => self::MARKER,
        ]);
        foreach (['BARISTA' => ['view', 'create'], 'FINANCE' => ['view', 'create', 'edit'],
            'MANAGEMENT' => ['view'], 'ADMINISTRATOR' => ['view']] as $role => $abilities) {
            $row = DB::table('role_permissions')->where('role', $role)->where('module', 'waste_management')->first();
            DB::table('t081_permission_snapshots')->insertOrIgnore([
                'role' => $role, 'role_permission_id' => $row?->id,
                'prior_abilities' => $row?->abilities, 'prior_updated_at' => $row?->updated_at,
            ]);
            if ($row && $row->updated_by !== null) {
                // A PM-edited grant survives rollback/reapply as a dormant old-module row.
                continue;
            }
            if ($row) {
                DB::table('role_permissions')->where('id', $row->id)->update([
                    'abilities' => json_encode($abilities, JSON_THROW_ON_ERROR), 'updated_at' => self::MARKER,
                ]);
            } else {
                DB::table('role_permissions')->insert([
                    'role' => $role, 'module' => 'waste_management',
                    'abilities' => json_encode($abilities, JSON_THROW_ON_ERROR),
                    'created_at' => self::MARKER, 'updated_at' => self::MARKER,
                ]);
            }
        }
        PermissionMatrix::forget();
    }

    public function down(): void
    {
        if (Schema::hasTable('t081_permission_snapshots')) {
            foreach (DB::table('t081_permission_snapshots')->get() as $snapshot) {
                $current = DB::table('role_permissions')->where('role', $snapshot->role)
                    ->where('module', 'waste_management')->first();
                if (! $current || ($current->updated_at !== self::MARKER && $current->updated_by === null)) {
                    throw new RuntimeException('Hak akses Barang Terbuang berubah; rollback butuh peninjauan PM.');
                }
            }
        }
        if (DB::table('raw_materials')->where('is_packaging_cup', true)->exists()) {
            Schema::create('t081_packaging_flags_backup', function (Blueprint $table): void {
                $table->unsignedBigInteger('raw_material_id')->primary();
            });
            DB::table('t081_packaging_flags_backup')->insert(
                DB::table('raw_materials')->where('is_packaging_cup', true)
                    ->pluck('id')->map(fn ($id): array => ['raw_material_id' => $id])->all());
        }
        if (Schema::hasTable('t081_permission_snapshots')) {
            foreach (DB::table('t081_permission_snapshots')->get() as $snapshot) {
                $query = DB::table('role_permissions')->where('role', $snapshot->role)
                    ->where('module', 'waste_management');
                if ($query->first()?->updated_by !== null) {
                    continue;
                }
                if ($snapshot->role_permission_id === null) {
                    $query->delete();
                } else {
                    $query->update(['abilities' => $snapshot->prior_abilities,
                        'updated_at' => $snapshot->prior_updated_at]);
                }
            }
            Schema::drop('t081_permission_snapshots');
        } else {
            foreach (['BARISTA', 'FINANCE', 'MANAGEMENT', 'ADMINISTRATOR'] as $role) {
                DB::table('role_permissions')->where('module', 'waste_management')->where('role', $role)
                    ->where('created_at', self::MARKER)->where('updated_at', self::MARKER)
                    ->whereNull('updated_by')->delete();
            }
        }
        DB::table('accounts')->where('code', '6-1930')->where('created_at', self::MARKER)
            ->where('updated_at', self::MARKER)->whereNotIn('id', DB::table('journal_lines')->select('account_id'))
            ->delete();
        foreach (['waste_reason_corrections', 'packaging_wastes', 'promotional_uses', 'waste_classifications'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                Schema::rename($table, 't081_'.$table.'_backup');
            } else {
                Schema::dropIfExists($table);
            }
        }
        Schema::table('raw_materials', function (Blueprint $table): void {
            $table->dropColumn('is_packaging_cup');
        });
        if (Schema::hasIndex('stock_ledger', 'stock_ledger_waste_month_idx')) {
            Schema::table('stock_ledger', function (Blueprint $table): void {
                $table->dropIndex('stock_ledger_waste_month_idx');
            });
        }
        PermissionMatrix::forget();
    }
};
