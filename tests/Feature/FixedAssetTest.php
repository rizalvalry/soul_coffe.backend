<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Resources\FixedAssets\FixedAssetResource;
use App\Models\Account;
use App\Models\FixedAsset;
use App\Models\FixedAssetCategory;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\AccountingPeriodService;
use App\Services\FixedAssetService;
use App\Services\JournalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FixedAssetTest extends TestCase
{
    use RefreshDatabase;

    public function test_opening_asset_waits_for_matching_opening_journal_and_disposal_cannot_precede_depreciation(): void
    {
        $finance = User::factory()->role(Role::FINANCE)->create();
        $manager = User::factory()->role(Role::MANAGEMENT)->create();
        $uuid = (string) Str::uuid();
        $input = ['uuid' => $uuid, 'name' => 'Aset Awal Uji',
            'category_id' => FixedAssetCategory::query()->where('name', 'Coffee Bike')->firstOrFail()->id,
            'acquired_on' => '2026-01-01', 'cost_minor' => 12000000,
            'useful_life_months' => 24, 'residual_minor' => 0, 'acquisition_mode' => 'OPENING'];
        $service = app(FixedAssetService::class);
        $asset = $service->acquire($input, $finance);
        $this->assertSame($asset->id, $service->acquire($input, $finance)->id);
        $this->assertDatabaseCount('fixed_assets', 1);
        try {
            $service->acquire([...$input, 'useful_life_months' => 36], $finance);
            $this->fail('Changed UUID replay was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('uuid', $exception->errors());
        }
        foreach (['depreciate', 'dispose'] as $action) {
            try {
                $action === 'depreciate'
                    ? $service->postMonthly(Carbon::create(2026, 1, 1), $manager)
                    : $service->dispose($asset, '2026-01-31', 0, null, $finance);
                $this->fail('Opening asset moved before its opening journal.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('asset', $exception->errors());
            }
        }
        try {
            app(JournalService::class)->postOpening('2026-01-01', [
                ['account_id' => Account::query()->where('code', '1-1800')->firstOrFail()->id,
                    'debit_minor' => 11000000, 'credit_minor' => 0, 'memo' => 'ASSET:'.$uuid],
                ['account_id' => Account::query()->where('code', '3-1200')->firstOrFail()->id,
                    'debit_minor' => 0, 'credit_minor' => 11000000],
            ]);
            $this->fail('Opening asset with mismatched amount was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines.0.memo', $exception->errors());
        }
        try {
            app(JournalService::class)->postOpening('2025-12-31', [
                ['account_id' => Account::query()->where('code', '1-1800')->firstOrFail()->id,
                    'debit_minor' => 12000000, 'credit_minor' => 0, 'memo' => 'ASSET:'.$uuid],
                ['account_id' => Account::query()->where('code', '3-1200')->firstOrFail()->id,
                    'debit_minor' => 0, 'credit_minor' => 12000000],
            ]);
            $this->fail('Opening asset was accepted before acquisition date.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines.0.memo', $exception->errors());
        }
        app(JournalService::class)->postOpening('2026-01-01', [
            ['account_id' => Account::query()->where('code', '1-1800')->firstOrFail()->id,
                'debit_minor' => 12000000, 'credit_minor' => 0, 'memo' => 'ASSET:'.$uuid],
            ['account_id' => Account::query()->where('code', '3-1200')->firstOrFail()->id,
                'debit_minor' => 0, 'credit_minor' => 12000000],
        ]);
        $this->assertSame(1, $service->postMonthly(Carbon::create(2026, 1, 1), $manager));
        $this->assertSame(1, $service->postMonthly(Carbon::create(2026, 2, 1), $manager));
        try {
            $service->dispose($asset, '2026-01-31', 0, null, $finance);
            $this->fail('Backdated disposal was accepted after February depreciation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('date', $exception->errors());
        }
        $this->assertSame('DISPOSED', $service->dispose($asset, '2026-02-28', 0, null, $finance)->status);
    }

    private function acquire(User $actor): FixedAsset
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
        $this->assertSame(5000000, (int) DB::table('journal_lines as l')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')->where('a.code', '6-2000')->sum('l.debit_minor'));

        $disposed = app(FixedAssetService::class)->dispose($asset, '2026-10-31', 0, null, $finance);
        $this->assertSame('DISPOSED', $disposed->status);
        $this->assertSame(7000000, (int) DB::table('journal_lines as l')
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
        $this->actingAs($rider)->get(FixedAssetResource::getUrl('create'))->assertForbidden();
    }

    public function test_disposal_retry_requires_same_proceeds_account(): void
    {
        $finance = User::factory()->role(Role::FINANCE)->create();
        $asset = $this->acquire($finance);
        $cash = Account::query()->where('code', '1-1100')->firstOrFail();
        $bank = Account::query()->where('code', '1-1200')->firstOrFail();
        $service = app(FixedAssetService::class);
        $this->assertSame('DISPOSED', $service->dispose($asset, '2026-10-31', 1000000, $cash->id, $finance)->status);
        $this->assertSame($asset->id, $service->dispose($asset, '2026-10-31', 1000000, $cash->id, $finance)->id);
        try {
            $service->dispose($asset, '2026-10-31', 1000000, $bank->id, $finance);
            $this->fail('Disposal replay with a different bank was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('asset', $exception->errors());
        }
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'fixed_asset_disposal')->count());
    }
}
