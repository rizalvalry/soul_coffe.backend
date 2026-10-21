<?php

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Filament\Resources\Journals\JournalResource;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Services\Menu\MenuLabels;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class GeneralLedger extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static string|UnitEnum|null $navigationGroup = 'Akuntansi';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Buku Besar';

    protected static ?string $title = 'Buku Besar per Akun';

    protected string $view = 'filament.pages.general-ledger';

    public ?int $accountId = null;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return MenuLabels::group('Akuntansi');
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->role->grants(Role::FINANCE) ?? false;
    }

    public function updatedAccountId(): void
    {
        $this->resetTable();
    }

    public function accountForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Pilih akun')->schema([
                Select::make('accountId')->label('Akun')
                    ->options(fn (): array => Account::query()->orderBy('code')->get()
                        ->mapWithKeys(fn (Account $account): array => [$account->id => $account->code.' · '.$account->name])->all())
                    ->searchable()->live(),
            ]),
        ]);
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
        return $this->ledgerQuery($account)->paginate(50);
    }

    public function table(Table $table): Table
    {
        abort_unless(self::canAccess(), 403);
        $account = $this->selectedAccount();

        return $table
            ->query($account ? $this->ledgerQuery($account) : JournalLine::query()->whereKey(0))
            ->defaultPaginationPageOption(50)
            ->columns([
                TextColumn::make('entry_date')->label('Tanggal')->date('Y-m-d'),
                TextColumn::make('number')->label('Jurnal')
                    ->url(fn (JournalLine $record): string => JournalResource::getUrl('view', ['record' => $record->entry_id])),
                TextColumn::make('memo')->label('Catatan')->placeholder('—'),
                TextColumn::make('debit_minor')->label('Debit')->numeric(decimalPlaces: 0, locale: 'id')->prefix('Rp ')->alignEnd(),
                TextColumn::make('credit_minor')->label('Kredit')->numeric(decimalPlaces: 0, locale: 'id')->prefix('Rp ')->alignEnd(),
                TextColumn::make('running_minor')->label('Saldo berjalan')->numeric(decimalPlaces: 0, locale: 'id')->prefix('Rp ')->alignEnd(),
            ]);
    }

    private function ledgerQuery(Account $account): Builder
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
            ->orderBy('journal_entries.entry_date')->orderBy('journal_entries.id')->orderBy('journal_lines.id');
    }
}
