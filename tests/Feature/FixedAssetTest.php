<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Resources\FixedAssets\FixedAssetResource;
use App\Models\Account;
use App\Models\FixedAssetCategory;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\AccountingPeriodService;
use App\Services\FixedAssetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FixedAssetTest extends TestCase
{
    use RefreshDatabase;

    private function acquire(User $actor): \App\Models\FixedAsset
    {
        return app(FixedAssetService::class)->acquire([
            'name' => 'Coffee Bike Uji',
            'category_id' => FixedAssetCategory::query()->where('name', 'Coffee Bike')->firstOrFail()->id,
            'acquired_on' => '2026-01-01', 'cost_minor' => 12000000,
            'useful_life_months' => 24, 'residual_minor' => 0,
            'acquisition_mode' => 'CASH',
            'funding_account_id' => Account::query()->where('code', '1-1100')->firstOrFail()->id,
        ], $actor);
    }

    public function test_acquisition_monthly_depreciation_and_month_ten_disposal_are_balanced_and_idempotent(): void
    {
        $finance = User::factory()->role(Role::FINANCE)->create();
        $manager = User::factory()->role(Role::MANAGEMENT)->create();
        $asset = $this->acquire($finance);
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'fixed_asset')->count());

        for ($month = 1; $month <= 10; $month++) {
            $this->assertSame(1, app(FixedAssetService::class)->postMonthly(Carbon::create(2026, $month, 1), $manager));
        }
        $this->assertSame(0, app(FixedAssetService::class)->postMonthly(Carbon::create(2026, 10, 1), $manager));
        $this->assertSame(5000000, (int) \Illuminate\Support\Facades\DB::table('journal_lines as l')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')->where('a.code', '6-2000')->sum('l.debit_minor'));

        $disposed = app(FixedAssetService::class)->dispose($asset, '2026-10-31', 0, null, $finance);
        $this->assertSame('DISPOSED', $disposed->status);
        $this->assertSame(7000000, (int) \Illuminate\Support\Facades\DB::table('journal_lines as l')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')->where('a.code', '6-2100')->sum('l.debit_minor'));
        $this->assertSame(0, app(FixedAssetService::class)->postMonthly(Carbon::create(2026, 11, 1), $manager));
        $this->assertSame(12, JournalEntry::query()->count());
    }

    public function test_closed_month_rejects_depreciation_and_wrong_role_gets_403(): void
    {
        $finance = User::factory()->role(Role::FINANCE)->create();
        $manager = User::factory()->role(Role::MANAGEMENT)->create();
        $rider = User::factory()->role(Role::STAFF)->create();
        $this->acquire($finance);
        app(AccountingPeriodService::class)->setStatus(2026, 2, 'CLOSED', $manager);

        try {
            app(FixedAssetService::class)->postMonthly(Carbon::create(2026, 2, 1), $manager);
            $this->fail('Closed period accepted depreciation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('entry_date', $exception->errors());
        }
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'fixed_asset_depreciation')->count());
        $this->actingAs($rider)->get(FixedAssetResource::getUrl())->assertForbidden();
    }
}
