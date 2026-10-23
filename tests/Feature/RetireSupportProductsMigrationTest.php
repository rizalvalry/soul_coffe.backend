<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RetireSupportProductsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_retirement_resumes_without_resnapshotting_or_touching_main_products(): void
    {
        $ice = Product::query()->create(['code' => 'ES BATU', 'name' => 'ES BATU', 'unit' => 'pack',
            'is_sellable' => false, 'is_active' => true, 'sort_order' => 1]);
        $other = Product::query()->create(['code' => 'SUPPORT', 'name' => 'Barang pendukung', 'unit' => 'pcs',
            'is_sellable' => false, 'is_active' => true, 'sort_order' => 2]);
        $coffee = Product::query()->create(['code' => 'COFFEE', 'name' => 'Kopi', 'unit' => 'cup',
            'is_sellable' => true, 'is_active' => true, 'sort_order' => 3]);
        $alreadyInactive = Product::query()->create(['code' => 'OLD', 'name' => 'Lama', 'unit' => 'pack',
            'is_sellable' => false, 'is_active' => false, 'sort_order' => 4]);
        $migration = require database_path('migrations/2026_10_23_000072_retire_support_products_and_add_field_notes.php');

        $migration->up();
        $this->assertFalse($ice->fresh()->is_active);
        $this->assertFalse($other->fresh()->is_active);
        $this->assertTrue($coffee->fresh()->is_active);
        $snapshots = DB::table('t072_retired_products')->pluck('previous_updated_at', 'product_id')->all();
        $this->assertEqualsCanonicalizing([$ice->id, $other->id], array_keys($snapshots));

        $migration->up();
        $this->assertSame(2, DB::table('t072_retired_products')->count());
        $this->assertSame($snapshots, DB::table('t072_retired_products')->pluck('previous_updated_at', 'product_id')->all());
        $this->assertFalse($alreadyInactive->fresh()->is_active);
    }
}
