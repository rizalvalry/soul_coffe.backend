<?php

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Filament\Resources\Journals\JournalResource;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Services\JournalService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

class OpeningBalances extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Laporan';

    protected static ?string $navigationLabel = 'Saldo Awal';

    protected static ?string $title = 'Jurnal Saldo Awal';

    protected string $view = 'filament.pages.opening-balances';

    public string $entryDate = '';

    public array $lines = [];

    public function mount(): void
    {
        $this->entryDate = today()->toDateString();
        $this->lines = [$this->blankLine(), $this->blankLine()];
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->role === Role::MANAGEMENT;
    }

    public function activeOpening(): ?JournalEntry
    {
        return JournalEntry::query()->where('source_type', 'opening_balance')->where('purpose', 'OPENING')
            ->whereDoesntHave('reversal')->first();
    }

    public function accounts(): mixed
    {
        return Account::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']);
    }

    public function addLine(): void
    {
        $this->lines[] = $this->blankLine();
    }

    public function removeLine(int $index): void
    {
        if (count($this->lines) > 2) {
            array_splice($this->lines, $index, 1);
        }
    }

    public function submit(): void
    {
        Gate::authorize('manage-accounting-periods');
        $entry = app(JournalService::class)->postOpening($this->entryDate, $this->lines);
        Notification::make()->success()->title('Saldo awal diposting')->body($entry->number)->send();
        $this->redirect(JournalResource::getUrl('view', ['record' => $entry]));
    }

    private function blankLine(): array
    {
        return ['account_id' => '', 'debit_minor' => 0, 'credit_minor' => 0];
    }
}
