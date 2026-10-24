<?php

namespace App\Filament\Pages;

use App\Enums\PanelModule;
use App\Enums\Role;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use App\Services\Menu\MenuLabels;
use App\Services\RiderIncentiveBalanceService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use UnitEnum;

class RiderIncentiveBalances extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Akuntansi';

    protected static ?string $navigationLabel = 'Saldo Insentif Rider';

    protected static ?string $title = 'Saldo Insentif Rider';

    protected string $view = 'filament.pages.rider-incentive-balances';

    public array $entry = [];

    public array $correction = [];

    public ?int $selectedRiderId = null;

    public string $asOfDate = '';

    public static function canAccess(): bool
    {
        return PermissionMatrix::can(Auth::user(), PanelModule::RIDER_INCENTIVE_BALANCE, 'view');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return MenuLabels::group('Akuntansi');
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
        $this->asOfDate = today()->toDateString();
        $this->resetEntry();
        $this->correction = ['request_key' => (string) Str::uuid(), 'corrects_movement_id' => '',
            'entry_date' => today()->toDateString(), 'reason' => ''];
    }

    public function resetEntry(): void
    {
        $this->entry = [
            'request_key' => (string) Str::uuid(), 'rider_id' => '',
            'entry_date' => today()->toDateString(), 'amount_minor' => '',
            'kind' => 'WITHDRAWAL', 'cash_account_id' => '', 'advance_id' => '', 'reason' => '',
        ];
    }

    public function riders(): array
    {
        abort_unless(self::canAccess(), 403);

        return User::query()->where(fn ($query) => $query->where('role', Role::STAFF->value)
            ->orWhereIn('id', DB::table('rider_incentive_movements')->select('rider_id')))
            ->orderBy('name')->get(['id', 'name', 'is_active'])->mapWithKeys(fn (User $rider): array => [
                $rider->id => $rider->name.($rider->is_active ? '' : ' (nonaktif)'),
            ])->all();
    }

    public function cashAccounts(): array
    {
        abort_unless(self::canAccess(), 403);

        return DB::table('accounts')->whereIn('code', ['1-1100', '1-1200', '1-1300'])
            ->where('is_active', true)->orderBy('code')->pluck('name', 'id')->all();
    }

    public function advances(): array
    {
        abort_unless(self::canAccess(), 403);
        if (! PermissionMatrix::can(Auth::user(), PanelModule::EMPLOYEE_ADVANCES, 'view')) {
            return [];
        }

        return DB::table('employee_advances')->where('user_id', (int) ($this->entry['rider_id'] ?? 0))
            ->where('status', 'ACTIVE')->orderBy('id')->pluck('id')->mapWithKeys(
                fn ($id): array => [$id => 'Kasbon #'.$id],
            )->all();
    }

    public function balances(): array
    {
        abort_unless(self::canAccess(), 403);
        $riders = $this->riders();
        $balances = app(RiderIncentiveBalanceService::class)->balances(array_keys($riders));
        $rows = [];
        foreach ($riders as $id => $name) {
            $rows[] = ['id' => $id, 'name' => $name, 'balance_minor' => $balances[$id] ?? 0];
        }

        return $rows;
    }

    public function movements(): array
    {
        abort_unless(self::canAccess(), 403);
        if (! $this->selectedRiderId) {
            return [];
        }

        return DB::table('rider_incentive_movements as movement')
            ->leftJoin('rider_incentive_movements as correction', 'correction.corrects_movement_id', '=', 'movement.id')
            ->where('movement.rider_id', $this->selectedRiderId)
            ->orderByDesc('movement.entry_date')->orderByDesc('movement.id')
            ->limit(100)->get(['movement.*', 'correction.id as correction_id'])->all();
    }

    public function reconciliation(): array
    {
        abort_unless(self::canAccess(), 403);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $this->asOfDate)) {
            return ['balances_minor' => 0, 'ledger_minor' => 0, 'difference_minor' => 0];
        }

        return app(RiderIncentiveBalanceService::class)->reconciliation($this->asOfDate);
    }

    public function submit(): void
    {
        abort_unless(PermissionMatrix::can(Auth::user(), PanelModule::RIDER_INCENTIVE_BALANCE, 'create'), 403);
        $kind = $this->entry['kind'] ?? '';
        abort_unless(in_array($kind, ['WITHDRAWAL', 'MANUAL_DEDUCTION', 'ADVANCE_REPAYMENT'], true), 422);
        $service = app(RiderIncentiveBalanceService::class);
        $input = [
            'request_key' => $this->entry['request_key'] ?? null,
            'rider_id' => $this->entry['rider_id'] ?? null,
            'entry_date' => $this->entry['entry_date'] ?? null,
            'amount_minor' => $this->entry['amount_minor'] ?? null,
            'cash_account_id' => $this->entry['cash_account_id'] ?: null,
            'advance_id' => $this->entry['advance_id'] ?: null,
            'reason' => $this->entry['reason'] ?? null,
        ];
        match ($kind) {
            'WITHDRAWAL' => $service->withdraw($input, Auth::user()),
            'MANUAL_DEDUCTION' => $service->deduct($input, Auth::user()),
            'ADVANCE_REPAYMENT' => $service->repayAdvance($input, Auth::user()),
        };
        $this->selectedRiderId = (int) $input['rider_id'];
        $this->resetEntry();
    }

    public function correct(): void
    {
        abort_unless(PermissionMatrix::can(Auth::user(), PanelModule::RIDER_INCENTIVE_BALANCE, 'edit'), 403);
        app(RiderIncentiveBalanceService::class)->correct($this->correction, Auth::user());
        $this->correction = ['request_key' => (string) Str::uuid(), 'corrects_movement_id' => '',
            'entry_date' => today()->toDateString(), 'reason' => ''];
    }
}
