<?php

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\WithPagination;
use UnitEnum;

class GeneralLedger extends Page
{
    use WithPagination;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static string|UnitEnum|null $navigationGroup = 'Laporan';

    protected static ?string $navigationLabel = 'Buku Besar';

    protected static ?string $title = 'Buku Besar per Akun';

    protected string $view = 'filament.pages.general-ledger';

    public ?int $accountId = null;

    public static function canAccess(): bool
    {
        return in_array(Auth::user()?->role, [Role::FINANCE, Role::MANAGEMENT], true);
    }

    public function updatedAccountId(): void
    {
        $this->resetPage();
    }

    public function selectedAccount(): ?Account
    {
        return $this->accountId ? Account::query()->find($this->accountId) : null;
    }

    public function accounts(): mixed
    {
        return Account::query()->orderBy('code')->get(['id', 'code', 'name']);
    }

    public function ledgerRows(Account $account): LengthAwarePaginator
    {
        $signed = $account->normal_balance === 'DEBIT'
            ? 'CAST(journal_lines.debit_minor AS SIGNED) - CAST(journal_lines.credit_minor AS SIGNED)'
            : 'CAST(journal_lines.credit_minor AS SIGNED) - CAST(journal_lines.debit_minor AS SIGNED)';

        $cutover = JournalEntry::query()->where('source_type', 'opening_balance')->where('purpose', 'OPENING')
            ->whereDoesntHave('reversal')->value('entry_date');

        return JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.entry_id')
            ->where('journal_lines.account_id', $account->id)
            ->when($cutover, fn ($query) => $query->whereDate('journal_entries.entry_date', '>=', $cutover))
            ->select('journal_lines.*', 'journal_entries.entry_date', 'journal_entries.number')
            ->selectRaw("SUM($signed) OVER (ORDER BY journal_entries.entry_date, journal_entries.id, journal_lines.id ROWS UNBOUNDED PRECEDING) AS running_minor")
            ->orderBy('journal_entries.entry_date')->orderBy('journal_entries.id')->orderBy('journal_lines.id')
            ->paginate(50);
    }
}
