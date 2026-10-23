<?php

namespace Tests\Feature;

use Database\Seeders\AccountSeeder;
use Database\Seeders\SupportMaterialsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupportMaterialsSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_support_materials_and_cash_category_seed_once_without_reactivating_existing_rows(): void
    {
        $this->seed(AccountSeeder::class);
        $this->seed(SupportMaterialsSeeder::class);
        $this->seed(SupportMaterialsSeeder::class);

        foreach (['Es Batu', 'Plastik', 'Sedotan'] as $name) {
            $this->assertSame(1, DB::table('raw_materials')->where('name', $name)->count());
        }
        $category = 'Bahan pendukung (es batu, plastik, sedotan)';
        $this->assertSame(1, DB::table('cash_categories')->where('name', $category)->count());

        DB::table('raw_materials')->where('name', 'Es Batu')->update(['is_active' => false]);
        DB::table('cash_categories')->where('name', $category)->update(['is_active' => false]);
        $this->seed(SupportMaterialsSeeder::class);

        $this->assertSame(1, DB::table('raw_materials')->where('name', 'Es Batu')->count());
        $this->assertSame(0, DB::table('raw_materials')->where('name', 'Es Batu')->value('is_active'));
        $this->assertSame(1, DB::table('cash_categories')->where('name', $category)->count());
        $this->assertSame(0, DB::table('cash_categories')->where('name', $category)->value('is_active'));
    }
}
