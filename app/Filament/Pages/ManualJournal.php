<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Journals\JournalResource;
use App\Models\Account;
use App\Models\Cart;
use App\Models\JournalEntry;
use App\Services\JournalService;
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
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

class ManualJournal extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $title = 'Jurnal Manual';

    protected string $view = 'filament.pages.manual-journal';

    public string $entryDate = '';

    public string $description = '';

    public array $lines = [];

    #[Locked]
    public int $sourceId;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        return Gate::allows('post-manual-journal');
    }

    public function mount(): void
    {
        $this->entryDate = today()->toDateString();
        $this->lines = [$this->blankLine(), $this->blankLine()];
        do {
            $this->sourceId = random_int(1, PHP_INT_MAX);
        } while (JournalEntry::query()->where('source_type', 'manual')->where('source_id', $this->sourceId)
            ->where('purpose', 'MANUAL')->exists());
    }

    public function accounts(): mixed
    {
        return Account::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']);
    }

    public function carts(): mixed
    {
        return Cart::query()->orderBy('code')->get(['id', 'code']);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Jurnal manual')->schema([
                DatePicker::make('entryDate')->label('Tanggal')->required(),
                TextInput::make('description')->label('Keterangan')->maxLength(255)->required(),
                Repeater::make('lines')->label('Baris jurnal')->schema([
                    Select::make('account_id')->label('Akun')
                        ->options(fn (): array => $this->accounts()->mapWithKeys(
                            fn (Account $account): array => [$account->id => $account->code.' — '.$account->name],
                        )->all())->searchable()->required(),
                    TextInput::make('debit_minor')->label('Debit (Rp)')->numeric()->minValue(0)->live(debounce: 150),
                    TextInput::make('credit_minor')->label('Kredit (Rp)')->numeric()->minValue(0)->live(debounce: 150),
                    Select::make('cart_id')->label('Coffee Bike (opsional)')
                        ->options(fn (): array => $this->carts()->pluck('code', 'id')->all()),
                ])->columns(['default' => 1, 'md' => 4])->minItems(2)->maxItems(1000)
                    ->addActionLabel('Tambah Baris')->reorderable(false)->columnSpanFull(),
            ])->columns(['default' => 1, 'md' => 2]),
        ]);
    }

    public function addLine(): void
    {
        Gate::authorize('post-manual-journal');
        if (count($this->lines) < 1000) {
            $this->lines[] = $this->blankLine();
        }
    }

    public function removeLine(int $index): void
    {
        Gate::authorize('post-manual-journal');
        if (count($this->lines) > 2 && array_key_exists($index, $this->lines)) {
            array_splice($this->lines, $index, 1);
        }
    }

    public function total(string $side): int
    {
        if (! in_array($side, ['debit_minor', 'credit_minor'], true)) {
            return 0;
        }

        $total = 0;
        foreach (array_slice($this->lines, 0, 1000) as $line) {
            $value = $line[$side] ?? 0;
            if (is_int($value) || is_string($value)) {
                $amount = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1000000000000]]);
                if ($amount !== false) {
                    $total += $amount;
                }
            }
        }

        return $total;
    }

    public function submit(): void
    {
        Gate::authorize('post-manual-journal');
        $lines = array_map(
            static fn ($line) => is_array($line) && ($line['cart_id'] ?? null) === ''
                ? [...$line, 'cart_id' => null] : $line,
            $this->lines,
        );
        $entry = app(JournalService::class)->post([
            'entry_date' => $this->entryDate,
            'description' => $this->description,
            'source_type' => 'manual',
            'source_id' => $this->sourceId,
            'purpose' => 'MANUAL',
        ], $lines);

        Notification::make()->success()->title('Jurnal manual sudah diposting')->body($entry->number)->send();
        $this->redirect(JournalResource::getUrl('view', ['record' => $entry]));
    }

    private function blankLine(): array
    {
        return ['account_id' => '', 'debit_minor' => 0, 'credit_minor' => 0, 'cart_id' => null];
    }
}
