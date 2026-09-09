<?php

namespace App\Filament\Pages;

use App\Enums\PanelModule;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use App\Services\Reporting\SalesActivityService;
use App\Services\StaffLocationService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * "Aktivitas Staff" — where each cart is, and what it has sold there today.
 *
 * TWO QUESTIONS, ONE SCREEN
 * -------------------------
 * The map answers *where is this person now*. The area × hour grid answers *which area was busy
 * at which hour*, which is the question the whole feature was actually asked for: Pulomas at
 * 09:00 against Cempaka Mas at 10:00, in cups. They share a screen because neither is worth much
 * alone — a position with no sales is a dot, and a sales total with no position cannot be acted
 * on.
 *
 * WHY THIS REFRESHES INSTEAD OF STREAMING
 * ---------------------------------------
 * A phone reports roughly once a minute (see config soul.location_ping_min_interval_seconds), so
 * a WebSocket would push nothing that a periodic refresh has not already got. Spending the Pusher
 * budget on data that has not changed yet would come straight out of the notification path, which
 * is the one place where a delay is actually felt by a person waiting on a refill. The map keeps
 * its own DOM (wire:ignore) and pulls a small JSON payload, so refreshing costs one query and
 * never resets the operator's pan and zoom.
 *
 * Refreshing stops when a past date is selected — history does not change, and a page that keeps
 * polling for it is just load with no question behind it.
 *
 * READ-ONLY. The trail is evidence; evidence that can be edited is not evidence.
 */
class StaffActivity extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static string|UnitEnum|null $navigationGroup = 'Operasional';

    protected static ?string $navigationLabel = 'Aktivitas Staff';

    protected static ?string $title = 'Aktivitas Staff';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.staff-activity';

    /** The operating day being looked at, as Y-m-d. */
    public string $date = '';

    /** Whose trail is drawn on the map, if anyone's. */
    public ?int $selectedStaffId = null;

    /** How many days the engagement grid covers, ending on $date. */
    public int $gridDays = 1;

    public function mount(): void
    {
        $this->date = Carbon::today()->toDateString();
    }

    public static function panelModule(): PanelModule
    {
        return PanelModule::STAFF_ACTIVITY;
    }

    public static function canAccess(): bool
    {
        return PermissionMatrix::can(Auth::user(), static::panelModule(), 'view');
    }

    public function selectStaff(int $userId): void
    {
        // A second click on the same person clears the trail — the same control both ways, so
        // there is nothing to hunt for when you want the map back.
        $this->selectedStaffId = $this->selectedStaffId === $userId ? null : $userId;
    }

    public function clearStaff(): void
    {
        $this->selectedStaffId = null;
    }

    public function setGridDays(int $days): void
    {
        $this->gridDays = in_array($days, [1, 7, 30], true) ? $days : 1;
    }

    public function isToday(): bool
    {
        return $this->day()->isSameDay(Carbon::today());
    }

    /** @return array<int, array<string, mixed>> */
    public function board(): array
    {
        return app(StaffLocationService::class)->liveBoard($this->day())->all();
    }

    /** @return array<string, int> */
    public function totals(): array
    {
        return app(SalesActivityService::class)->dayTotals($this->day());
    }

    /** @return array<int, array<string, mixed>> */
    public function perCart(): array
    {
        return app(SalesActivityService::class)->perCart($this->day());
    }

    /** @return array<string, mixed> */
    public function areaHours(): array
    {
        return app(SalesActivityService::class)->areaHours($this->day(), $this->gridDays);
    }

    /** @return array<int, array<string, mixed>> */
    public function suspects(): array
    {
        return app(SalesActivityService::class)->suspects($this->day());
    }

    public function selectedStaffName(): ?string
    {
        if (! $this->selectedStaffId) {
            return null;
        }

        return User::query()->whereKey($this->selectedStaffId)->value('name');
    }

    /**
     * Everything the map needs, and nothing else.
     *
     * Called from the browser on a timer, so it is kept deliberately small: coordinates, a label,
     * and the few facts that belong in a marker popup. The tables on the page are rendered
     * server-side from the same services — this payload is not a second source of truth for them.
     *
     * @return array{staff: array<int, array<string, mixed>>, trail: array<int, array<string, mixed>>, selected: int|null, stale_minutes: int}
     */
    public function mapPayload(): array
    {
        $staff = [];

        foreach ($this->board() as $row) {
            if ($row['lat'] === null || $row['lng'] === null) {
                continue;
            }

            $staff[] = [
                'user_id' => $row['user_id'],
                'name' => $row['name'],
                'cart_code' => $row['cart_code'],
                'area' => $row['area'],
                'lat' => $row['lat'],
                'lng' => $row['lng'],
                'accuracy_m' => $row['accuracy_m'],
                'battery_pct' => $row['battery_pct'],
                'is_live' => $row['is_live'],
                'reported_at' => $row['reported_at']?->format('H:i'),
                'transactions' => $row['transactions'],
                'cups' => $row['cups'],
            ];
        }

        $trail = [];

        if ($this->selectedStaffId) {
            $member = User::query()->find($this->selectedStaffId);

            if ($member) {
                $trail = app(StaffLocationService::class)
                    ->trail($member, $this->day())
                    ->map(fn ($ping): array => [
                        'lat' => (float) $ping->lat,
                        'lng' => (float) $ping->lng,
                        'at' => $ping->recorded_at->format('H:i'),
                        // A sale-sourced point is a place a cup was actually bought, which is
                        // worth seeing differently from a background ping.
                        'source' => $ping->source,
                        'accuracy_m' => $ping->accuracy_m,
                    ])
                    ->all();
            }
        }

        return [
            'staff' => $staff,
            'trail' => $trail,
            'selected' => $this->selectedStaffId,
            'stale_minutes' => (int) config('soul.location_stale_minutes', 10),
        ];
    }

    private function day(): Carbon
    {
        try {
            return Carbon::parse($this->date)->startOfDay();
        } catch (\Throwable) {
            return Carbon::today();
        }
    }
}
