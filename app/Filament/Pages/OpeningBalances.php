<?php

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Filament\Resources\Journals\JournalResource;
use App\Models\Account;
use App\Models\FixedAsset;
use App\Models\JournalEntry;
use App\Services\JournalService;
use App\Services\Menu\MenuLabels;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

class OpeningBalances extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Akuntansi';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Saldo Awal';

    protected static ?string $title = 'Jurnal Saldo Awal';

    protected string $view = 'filament.pages.opening-balances';

    public string $entryDate = '';

    public array $lines = [];

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return MenuLabels::group('Akuntansi');
    }

    public function mount(): void
    {
        $this->entryDate = today()->toDateString();
        $this->lines = [$this->blankLine(), $this->blankLine()];
    }

    public static function canAccess(): bool
    {
        return in_array(Auth::user()?->role, [Role::ADMINISTRATOR, Role::MANAGEMENT], true);
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

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Isi saldo awal')->schema([
                DatePicker::make('entryDate')->label('Tanggal cutover')->required(),
                Repeater::make('lines')->label('Baris saldo awal')->schema([
                    Select::make('account_id')->label('Akun')
                        ->options(fn (): array => $this->accounts()->mapWithKeys(
                            fn (Account $account): array => [$account->id => $account->code.' — '.$account->name],
                        )->all())->searchable()->required(),
                    TextInput::make('debit_minor')->label('Debit (Rp)')->numeric()->minValue(0),
                    TextInput::make('credit_minor')->label('Kredit (Rp)')->numeric()->minValue(0),
                    Select::make('memo')->label('Aset saldo awal (kalau baris ini buat aset)')
                        ->options(fn (): array => FixedAsset::query()->where('acquisition_mode', 'OPENING')
                            ->orderBy('name')->get()->mapWithKeys(fn (FixedAsset $asset): array => ['ASSET:'.$asset->uuid => $asset->name.' · '.$asset->uuid])->all())
                        ->searchable(),
                ])->columns(['default' => 1, 'md' => 3])->minItems(2)
                    ->addActionLabel('Tambah Baris')->reorderable(false)->columnSpanFull(),
            ]),
        ]);
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
