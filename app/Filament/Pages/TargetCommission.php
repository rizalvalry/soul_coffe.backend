<?php

namespace App\Filament\Pages;

use App\Enums\PanelModule;
use App\Enums\Role;
use App\Filament\Concerns\RenameableModule;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use App\Services\RiderCommissionService;
use BackedEnum;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use UnitEnum;

class TargetCommission extends Page
{
    use RenameableModule;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static string|UnitEnum|null $navigationGroup = 'Operasional';

    protected static ?string $navigationLabel = 'Target & Komisi';

    protected static ?string $title = 'Target & Komisi';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.target-commission';

    public string $period = '';

    public string $effectiveFrom = '';

    public string $selectedRiderId = '';

    public string $sourceRiderId = '';

    /** @var array<int, string> */
    public array $targetRiderIds = [];

    /** @var array<int, array{min_cups: string, amount_per_cup_minor: string}> */
    public array $tiers = [];

    public string $requestKey = '';

    public static function panelModule(): PanelModule
    {
        return PanelModule::RIDER_COMMISSIONS;
    }

    public static function canAccess(): bool
    {
        return self::allowedRole() && PermissionMatrix::can(Auth::user(), self::panelModule(), 'view');
    }

    public function canManage(): bool
    {
        return self::allowedRole() && PermissionMatrix::can(Auth::user(), self::panelModule(), 'edit');
    }

    private static function allowedRole(): bool
    {
        return Auth::user()?->role->grants(Role::FINANCE) ?? false;
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
        $this->period = today()->format('Y-m');
        $this->effectiveFrom = today()->toDateString();
        $this->tiers = [['min_cups' => '100', 'amount_per_cup_minor' => '1000']];
        $this->requestKey = (string) Str::uuid();
    }

    public function periodForm(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('period')->label('Periode')->type('month')->live()->required(),
        ]);
    }

    public function riderForm(Schema $schema): Schema
    {
        $riders = fn (): array => collect($this->riders())->mapWithKeys(
            fn (User $rider): array => [$rider->id => $rider->name],
        )->all();

        return $schema->components([
            DatePicker::make('effectiveFrom')->label('Berlaku mulai')->required(),
            Select::make('selectedRiderId')->label('Rider yang mau diatur')->options($riders)->searchable(),
        ])->columns(['default' => 1, 'md' => 2]);
    }

    public function tierForm(Schema $schema): Schema
    {
        $riders = fn (): array => collect($this->riders())->mapWithKeys(
            fn (User $rider): array => [$rider->id => $rider->name],
        )->all();

        return $schema->components([
            CheckboxList::make('targetRiderIds')->label('Terapkan tier ke Rider')->options($riders)
                ->columns(['default' => 1, 'md' => 3])->columnSpanFull(),
            Repeater::make('tiers')->label('Tier komisi')->schema([
                TextInput::make('min_cups')->label('Mulai cup')->numeric()->minValue(1)->required(),
                TextInput::make('amount_per_cup_minor')->label('Rp per cup')->numeric()->minValue(0)->required(),
            ])->columns(['default' => 1, 'md' => 2])->minItems(1)->addActionLabel('Tambah tier')
                ->reorderable(false)->columnSpanFull(),
        ]);
    }

    public function copyForm(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('sourceRiderId')->label('Salin tier dari Rider lain')
                ->options(fn (): array => collect($this->riders())->pluck('name', 'id')->all())->searchable(),
        ]);
    }

    public function riders(): array
    {
        abort_unless(self::canAccess(), 403);

        return User::query()->where('role', Role::STAFF->value)->where('is_active', true)
            ->orderBy('name')->get(['id', 'name'])->all();
    }

    public function rows(): array
    {
        abort_unless(self::canAccess(), 403);
        $riders = $this->riders();
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $this->period)
            ? $this->period : today()->format('Y-m');
        $from = $month.'-01';
        $through = Carbon::parse($from)->endOfMonth()->toDateString();
        $ids = array_map(fn (User $rider): int => $rider->id, $riders);
        $commissions = app(RiderCommissionService::class);
        $period = $commissions->summaries($ids, $from, $through);
        $today = today()->toDateString();
        $todayRows = $today >= $from && $today <= $through
            ? $period : $commissions->summaries($ids, $today, $today);
        $out = [];
        foreach ($riders as $rider) {
            $todaySummary = $todayRows[$rider->id][$today] ?? null;
            $monthDays = $period[$rider->id] ?? [];
            $out[] = [
                'id' => $rider->id, 'name' => $rider->name,
                'tiers' => $commissions->tiersFor($rider->id, $today),
                'today_cups' => $todaySummary['cups_sold'] ?? 0,
                'today_commission_minor' => $todaySummary['commission_minor'] ?? 0,
                'today_has_closing' => $todaySummary['has_closing'] ?? false,
                'period_cups' => $this->sumDays($monthDays, 'cups_sold'),
                'period_commission_minor' => $this->sumDays($monthDays, 'commission_minor'),
            ];
        }

        return $out;
    }

    private function sumDays(array $days, string $field): int
    {
        $total = 0;
        foreach ($days as $day) {
            $value = $day[$field];
            if ($value > PHP_INT_MAX - $total) {
                throw new \DomainException('Jumlah komisi terlalu besar. Cek lagi tier-nya ya.');
            }
            $total += $value;
        }

        return $total;
    }

    public function loadRider(): void
    {
        abort_unless(self::canAccess(), 403);
        $riderId = (int) $this->selectedRiderId;
        abort_unless(User::query()->whereKey($riderId)->where('role', Role::STAFF->value)->where('is_active', true)->exists(), 404);
        $tiers = app(RiderCommissionService::class)->tiersFor($riderId, $this->effectiveFrom);
        $this->tiers = $tiers === [] ? [['min_cups' => '100', 'amount_per_cup_minor' => '1000']]
            : array_map(fn (array $row): array => [
                'min_cups' => (string) $row['min_cups'],
                'amount_per_cup_minor' => (string) $row['amount_per_cup_minor'],
            ], $tiers);
        $this->targetRiderIds = [(string) $riderId];
        $this->requestKey = (string) Str::uuid();
    }

    public function addTier(): void
    {
        abort_unless($this->canManage(), 403);
        $this->tiers[] = ['min_cups' => '', 'amount_per_cup_minor' => ''];
    }

    public function removeTier(int $index): void
    {
        abort_unless($this->canManage(), 403);
        unset($this->tiers[$index]);
        $this->tiers = array_values($this->tiers);
    }

    public function saveTiers(): void
    {
        abort_unless($this->canManage(), 403);
        $data = $this->validate([
            'effectiveFrom' => ['required', 'date_format:Y-m-d'],
            'targetRiderIds' => ['required', 'array', 'min:1'],
            'targetRiderIds.*' => ['required', 'integer'],
            'tiers' => ['required', 'array', 'min:1', 'max:20'],
            'tiers.*.min_cups' => ['required', 'integer', 'min:1', 'max:1000000'],
            'tiers.*.amount_per_cup_minor' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'requestKey' => ['required', 'uuid'],
        ]);
        $rows = array_map(fn (array $row): array => [
            'min_cups' => (int) $row['min_cups'],
            'amount_per_cup_minor' => (int) $row['amount_per_cup_minor'],
        ], $data['tiers']);
        app(RiderCommissionService::class)->save(
            array_map('intval', $data['targetRiderIds']), $data['effectiveFrom'], $rows,
            Auth::user(), $data['requestKey'],
        );
        $this->requestKey = (string) Str::uuid();
        Notification::make()->title('Tier Rider sudah disimpan ya.')->success()->send();
    }

    public function copyTiers(): void
    {
        abort_unless($this->canManage(), 403);
        $data = $this->validate([
            'sourceRiderId' => ['required', 'integer'],
            'effectiveFrom' => ['required', 'date_format:Y-m-d'],
            'targetRiderIds' => ['required', 'array', 'min:1'],
            'targetRiderIds.*' => ['required', 'integer'],
            'requestKey' => ['required', 'uuid'],
        ]);
        $sourceId = (int) $data['sourceRiderId'];
        abort_unless(User::query()->whereKey($sourceId)->where('role', Role::STAFF->value)->where('is_active', true)->exists(), 404);
        $rows = app(RiderCommissionService::class)->tiersFor($sourceId, $data['effectiveFrom']);
        if ($rows === []) {
            throw ValidationException::withMessages(['sourceRiderId' => 'Rider sumber belum punya tier di tanggal ini ya.']);
        }
        app(RiderCommissionService::class)->save(
            array_map('intval', $data['targetRiderIds']), $data['effectiveFrom'], $rows,
            Auth::user(), $data['requestKey'],
        );
        $this->requestKey = (string) Str::uuid();
        Notification::make()->title('Tier sudah disalin ke Rider pilihan ya.')->success()->send();
    }
}
