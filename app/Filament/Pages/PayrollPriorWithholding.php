<?php

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\PayrollPriorWithholding as Prior;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Menu\MenuLabels;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use UnitEnum;

class PayrollPriorWithholding extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Akuntansi';

    protected static ?string $navigationLabel = 'PPh Payroll Sebelumnya';

    protected static ?string $title = 'PPh Sudah Dipotong Sebelumnya';

    protected string $view = 'filament.pages.payroll-prior-withholding';

    public array $input = [];

    public static function canAccess(): bool
    {
        return Auth::user()?->is_active && (Auth::user()?->role->grants(Role::FINANCE) ?? false);
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return MenuLabels::group('Akuntansi');
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
        $this->form->fill([
            'user_id' => '', 'year' => now()->year, 'months' => '', 'gross_minor' => '',
            'jht_jp_minor' => '', 'amount_minor' => '', 'reason' => '',
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Catat PPh dari payroll sebelumnya')
                ->description('Isi data dari sistem lama supaya hitungan PPh Desember lengkap.')
                ->schema([
                    Select::make('user_id')->label('Karyawan')
                        ->options(fn (): array => User::query()
                            ->whereHas('employeeProfile', fn ($query) => $query->where('payroll_active', true))
                            ->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()->required(),
                    TextInput::make('year')->label('Tahun')->numeric()->required(),
                    TextInput::make('months')->label('Bulan yang dicakup')
                        ->helperText('Pisahkan dengan koma, misalnya 1,2,3.')->required(),
                    TextInput::make('gross_minor')->label('Bruto kena pajak (Rp)')->numeric()->minValue(0)->required(),
                    TextInput::make('jht_jp_minor')->label('JHT/JP pegawai (Rp)')->numeric()->minValue(0)->required(),
                    TextInput::make('amount_minor')->label('PPh sudah dipotong (Rp)')->numeric()->minValue(0)->required(),
                    Textarea::make('reason')->label('Alasan')->required()->columnSpanFull(),
                ])->columns(['default' => 1, 'md' => 2]),
        ])->statePath('input');
    }

    public function save(): void
    {
        abort_unless(self::canAccess(), 403);
        $data = $this->validate([
            'input.user_id' => ['required', 'integer', 'exists:users,id', 'exists:employee_profiles,user_id,payroll_active,1'],
            'input.year' => ['required', 'integer', 'between:2020,2100'],
            'input.amount_minor' => ['required', 'integer', 'min:0'],
            'input.gross_minor' => ['required', 'integer', 'min:0'],
            'input.jht_jp_minor' => ['required', 'integer', 'min:0'],
            'input.months' => ['required', 'string', 'regex:/^([1-9]|1[0-2])(,\s*([1-9]|1[0-2]))*$/'],
            'input.reason' => ['required', 'string', 'min:5', 'max:2000'],
        ])['input'];
        $months = array_map('intval', preg_split('/,\s*/', $data['months']));
        if (count($months) !== count(array_unique($months))) {
            throw ValidationException::withMessages(['input.months' => 'Setiap bulan hanya boleh sekali ya.']);
        }
        sort($months);
        $data['months'] = $months;
        DB::transaction(function () use ($data): void {
            $december = PayrollRun::query()->whereDate('period_start', $data['year'].'-12-01')->lockForUpdate()->first();
            if ($december && $december->status !== 'DRAFT') {
                throw ValidationException::withMessages(['input.year' => 'Payroll Desember sudah dikunci.']);
            }
            $prior = Prior::query()->where('user_id', $data['user_id'])->where('year', $data['year'])
                ->lockForUpdate()->first();
            if ($prior && $prior->amount_minor === (int) $data['amount_minor']
                && $prior->gross_minor === (int) $data['gross_minor']
                && $prior->jht_jp_minor === (int) $data['jht_jp_minor']
                && $prior->months === $data['months'] && $prior->reason === $data['reason']) {
                return;
            }
            if ($prior) {
                $prior->update([...$data, 'created_by' => Auth::id()]);
            } else {
                $prior = Prior::query()->create([...$data, 'created_by' => Auth::id()]);
            }
            AuditLog::query()->create([
                'actor_id' => Auth::id(), 'actor_role' => Auth::user()->role->value,
                'action' => 'payroll_prior_withholding_saved', 'subject_type' => Prior::class,
                'subject_id' => $prior->id, 'after_json' => ['payroll_detail' => '[redacted]'],
                'ip' => request()->ip(),
            ]);
        });
        $this->form->fill([
            'user_id' => '', 'year' => now()->year, 'months' => '', 'gross_minor' => '',
            'jht_jp_minor' => '', 'amount_minor' => '', 'reason' => '',
        ]);
        Notification::make()->title('PPh sebelumnya tersimpan')->success()->send();
    }

    public function table(Table $table): Table
    {
        abort_unless(self::canAccess(), 403);

        return $table
            ->query(Prior::query()->with(['employee:id,name', 'creator:id,name']))
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('employee.name')->label('Karyawan')->searchable(),
                TextColumn::make('year')->label('Tahun')->sortable(),
                TextColumn::make('months')->label('Bulan')->getStateUsing(fn (Prior $record): string => implode(', ', $record->months)),
                TextColumn::make('gross_minor')->label('Bruto (Rp)')->numeric(decimalPlaces: 0, locale: 'id')->prefix('Rp ')->alignEnd(),
                TextColumn::make('jht_jp_minor')->label('JHT/JP (Rp)')->numeric(decimalPlaces: 0, locale: 'id')->prefix('Rp ')->alignEnd(),
                TextColumn::make('amount_minor')->label('PPh (Rp)')->numeric(decimalPlaces: 0, locale: 'id')->prefix('Rp ')->alignEnd(),
                TextColumn::make('reason')->label('Alasan')->wrap(),
                TextColumn::make('creator.name')->label('Disimpan oleh'),
                TextColumn::make('updated_at')->label('Waktu')->dateTime('d M Y H:i')->sortable(),
            ]);
    }
}
