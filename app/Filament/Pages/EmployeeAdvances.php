<?php

namespace App\Filament\Pages;

use App\Enums\PanelModule;
use App\Models\Account;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeAdvanceMovement;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use App\Services\EmployeeAdvanceService;
use App\Services\Menu\MenuLabels;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\WithPagination;
use UnitEnum;

class EmployeeAdvances extends Page
{
    use WithPagination;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Akuntansi';

    protected static ?string $navigationLabel = 'Kasbon Karyawan';

    protected static ?string $title = 'Kasbon Karyawan';

    protected string $view = 'filament.pages.employee-advances';

    public array $loan = [];

    public array $payment = [];

    public array $adjustment = [];

    public array $newType = [];

    public ?int $selectedAdvanceId = null;

    public string $cancelReason = '';

    public string $repaymentCorrectionReason = '';

    public string $repaymentCorrectionKey = '';

    public string $asOfDate = '';

    public static function canAccess(): bool
    {
        return PermissionMatrix::can(Auth::user(), PanelModule::EMPLOYEE_ADVANCES, 'view');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return MenuLabels::group('Akuntansi');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->loan = [
            'request_key' => (string) Str::uuid(), 'user_id' => '', 'type_id' => '',
            'start_date' => today()->toDateString(), 'principal_minor' => '', 'reason' => '',
            'installment_kind' => 'PAYROLL', 'installment_minor' => '',
            'source_kind' => 'CASH', 'cash_account_id' => '',
        ];
        $this->payment = ['request_key' => (string) Str::uuid(), 'entry_date' => today()->toDateString(),
            'amount_minor' => '', 'cash_account_id' => '', 'reason' => ''];
        $this->adjustment = ['request_key' => (string) Str::uuid(), 'entry_date' => today()->toDateString(),
            'amount_minor' => '', 'reason' => ''];
        $this->newType = ['name' => ''];
        $this->asOfDate = today()->toDateString();
        $this->repaymentCorrectionKey = (string) Str::uuid();
    }

    public function loanForm(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('user_id')->label('Karyawan')->options(fn (): array => User::query()
                ->whereHas('employeeProfile')->orderBy('name')->pluck('name', 'id')->all())->searchable()->required(),
            Select::make('type_id')->label('Jenis')->options(fn (): array => DB::table('employee_advance_types')
                ->orderBy('name')->pluck('name', 'id')->all())->required(),
            DatePicker::make('start_date')->label('Tanggal')->required(),
            TextInput::make('principal_minor')->label('Pokok (Rp)')->numeric()->rule('integer')->minValue(1)->required(),
            Textarea::make('reason')->label('Alasan')->required()->maxLength(255),
            Select::make('installment_kind')->label('Cara cicil')->options([
                'DAY' => 'Per hari kerja', 'PAYROLL' => 'Per payroll', 'FREE' => 'Bebas',
            ])->live()->required(),
            TextInput::make('installment_minor')->label('Nominal cicilan (Rp)')->numeric()->rule('integer')->minValue(1)
                ->visible(fn ($get): bool => $get('installment_kind') !== 'FREE')
                ->required(fn ($get): bool => $get('installment_kind') !== 'FREE'),
            Select::make('source_kind')->label('Pencairan')->options([
                'CASH' => 'Kas / bank', 'OPENING' => 'Saldo awal tanpa kas',
            ])->live()->required(),
            Select::make('cash_account_id')->label('Akun kas / bank')->options(fn (): array => $this->cashAccounts())
                ->visible(fn ($get): bool => $get('source_kind') === 'CASH')
                ->required(fn ($get): bool => $get('source_kind') === 'CASH'),
        ])->columns(['default' => 1, 'md' => 2])->statePath('loan');
    }

    public function paymentForm(Schema $schema): Schema
    {
        return $schema->components([
            DatePicker::make('entry_date')->label('Tanggal cicilan')->required(),
            TextInput::make('amount_minor')->label('Cicilan tunai (Rp)')->numeric()->rule('integer')->minValue(1)->required(),
            Select::make('cash_account_id')->label('Akun kas / bank')->options(fn (): array => $this->cashAccounts())->required(),
            Textarea::make('reason')->label('Catatan')->maxLength(255),
        ])->columns(['default' => 1, 'md' => 2])->statePath('payment');
    }

    public function adjustmentForm(Schema $schema): Schema
    {
        return $schema->components([
            DatePicker::make('entry_date')->label('Tanggal koreksi')->required(),
            TextInput::make('amount_minor')->label('Perubahan saldo (Rp, bisa negatif)')->numeric()->rule('integer')->required(),
            Textarea::make('reason')->label('Alasan koreksi')->required()->maxLength(255),
        ])->statePath('adjustment');
    }

    public function typeForm(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Jenis kasbon baru')->required()->maxLength(80),
        ])->statePath('newType');
    }

    public function createLoan(): void
    {
        $this->requireAbility('create');
        $advance = app(EmployeeAdvanceService::class)->create($this->loan, Auth::user());
        $this->selectedAdvanceId = $advance->id;
        $this->loan['request_key'] = (string) Str::uuid();
        Notification::make()->success()->title('Kasbon sudah dicatat')->send();
    }

    public function selectAdvance(int $id): void
    {
        abort_unless(static::canAccess(), 403);
        $this->selectedAdvanceId = EmployeeAdvance::query()->findOrFail($id)->id;
    }

    public function repay(): void
    {
        $this->requireAbility('create');
        app(EmployeeAdvanceService::class)->repay($this->selected(), $this->payment, Auth::user());
        $this->payment['request_key'] = (string) Str::uuid();
        $this->payment['amount_minor'] = '';
        Notification::make()->success()->title('Cicilan tunai sudah dicatat')->send();
    }

    public function adjust(): void
    {
        $this->requireAbility('edit');
        app(EmployeeAdvanceService::class)->adjust($this->selected(), $this->adjustment, Auth::user());
        $this->adjustment['request_key'] = (string) Str::uuid();
        $this->adjustment['amount_minor'] = '';
        Notification::make()->success()->title('Koreksi kasbon sudah dicatat')->send();
    }

    public function cancelAdvance(): void
    {
        $this->requireAbility('edit');
        app(EmployeeAdvanceService::class)->cancel($this->selected(), $this->cancelReason, Auth::user());
        $this->cancelReason = '';
        Notification::make()->success()->title('Kasbon sudah dibatalkan')->send();
    }

    public function reverseRepayment(int $movementId): void
    {
        $this->requireAbility('edit');
        $movement = EmployeeAdvanceMovement::query()->whereKey($movementId)
            ->where('advance_id', $this->selected()->id)->firstOrFail();
        app(EmployeeAdvanceService::class)->reverseRepayment($movement, $this->repaymentCorrectionKey,
            $this->repaymentCorrectionReason, Auth::user());
        $this->repaymentCorrectionKey = (string) Str::uuid();
        $this->repaymentCorrectionReason = '';
        Notification::make()->success()->title('Cicilan sudah dibalik')->send();
    }

    public function canReverseRepayment(EmployeeAdvanceMovement $movement): bool
    {
        return $movement->kind === 'CASH_REPAYMENT'
            && ! JournalEntry::query()->where('reverses_entry_id', $movement->journal_entry_id)->exists();
    }

    public function createType(): void
    {
        $this->requireAbility('edit');
        $this->newType['name'] = trim((string) ($this->newType['name'] ?? ''));
        $data = $this->validate(['newType.name' => ['required', 'string', 'max:80', 'unique:employee_advance_types,name']]);
        DB::table('employee_advance_types')->insert([
            'name' => $data['newType']['name'], 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->newType['name'] = '';
        Notification::make()->success()->title('Jenis kasbon ditambahkan')->send();
    }

    public function loans(): LengthAwarePaginator
    {
        abort_unless(static::canAccess(), 403);

        return EmployeeAdvance::query()->with(['user', 'type'])
            ->withSum('movements as balance_minor', 'amount_minor')
            ->orderByDesc('start_date')->orderByDesc('id')->paginate(30);
    }

    public function employeeBalances(): array
    {
        abort_unless(static::canAccess(), 403);

        return DB::table('employee_advance_movements as movement')
            ->join('employee_advances as advance', 'advance.id', '=', 'movement.advance_id')
            ->join('users as user', 'user.id', '=', 'advance.user_id')
            ->groupBy('user.id', 'user.name')->havingRaw('SUM(movement.amount_minor) <> 0')
            ->selectRaw('user.name, SUM(movement.amount_minor) AS balance_minor')
            ->orderBy('user.name')->get()->all();
    }

    public function reconciliation(): array
    {
        abort_unless(static::canAccess(), 403);
        $this->validate(['asOfDate' => ['required', 'date_format:Y-m-d']]);

        return app(EmployeeAdvanceService::class)->reconciliation($this->asOfDate);
    }

    public function selectedMovements(): array
    {
        return $this->selectedAdvanceId ? $this->selected()->movements()->orderBy('entry_date')->orderBy('id')->get()->all() : [];
    }

    public function selectedLoan(): ?EmployeeAdvance
    {
        return $this->selectedAdvanceId ? $this->selected() : null;
    }

    private function selected(): EmployeeAdvance
    {
        abort_unless(static::canAccess(), 403);

        return EmployeeAdvance::query()->with('user')->findOrFail($this->selectedAdvanceId);
    }

    private function cashAccounts(): array
    {
        return Account::query()->whereIn('code', ['1-1100', '1-1200', '1-1300'])->where('is_active', true)
            ->orderBy('code')->get()->mapWithKeys(fn (Account $account): array => [
                $account->id => $account->code.' '.$account->name,
            ])->all();
    }

    private function requireAbility(string $ability): void
    {
        abort_unless(PermissionMatrix::can(Auth::user(), PanelModule::EMPLOYEE_ADVANCES, $ability), 403);
    }
}
