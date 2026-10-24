<?php

namespace App\Filament\Pages;

use App\Models\AuditLog;
use App\Models\PayrollComponentEntry;
use App\Models\PayrollComponentType;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Menu\MenuLabels;
use App\Services\PayrollRunService;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use UnitEnum;

class PayrollConfiguration extends Page
{
    private const HISTORY_LIMIT = 100;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Akuntansi';

    protected static ?string $navigationLabel = 'Payroll';

    protected static ?string $title = 'Konfigurasi Payroll';

    protected string $view = 'filament.pages.payroll-configuration';

    public array $bpjs = [];

    public array $ptkp = [];

    public array $ter = [];

    public array $terPublication = [];

    public array $component = [];

    public array $entry = [];

    public array $annualVersion = [];

    public array $annualBracket = [];

    public array $entryEdits = [];

    public string $verificationNote = '';

    public array $preview = [];

    public array $attendanceRule = [];

    public array $monthlyBonus = [];

    public ?array $previewResult = null;

    public ?string $previewError = null;

    public static function canAccess(): bool
    {
        return Gate::allows('manage-payroll');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return MenuLabels::group('Akuntansi');
    }

    public function mount(): void
    {
        Gate::authorize('manage-payroll');
        $this->bpjs = array_fill_keys(self::bpjsRateFields(), 0) + [
            'effective_from' => '', 'jp_wage_cap_minor' => 0, 'health_wage_cap_minor' => 0,
            'source_note' => '',
        ];
        $this->ptkp = ['status' => '', 'ter_category' => '', 'annual_amount_minor' => 0, 'effective_from' => '', 'source_note' => ''];
        $this->ter = ['category' => '', 'lower_gross_minor' => 0, 'upper_gross_minor' => null, 'rate_bps' => 0, 'effective_from' => '', 'source_note' => ''];
        $this->terPublication = ['category' => '', 'effective_from' => ''];
        $this->component = ['code' => '', 'name' => '', 'kind' => '', 'taxable' => false, 'account_id' => '', 'effective_from' => '', 'source_note' => ''];
        $this->entry = ['user_id' => '', 'component_type_id' => '', 'period_start' => '', 'request_key' => (string) Str::uuid(), 'amount_minor' => '', 'note' => ''];
        $this->annualVersion = ['effective_from' => '', 'source_note' => ''];
        $this->annualBracket = ['version_id' => '', 'lower_taxable_minor' => 0, 'upper_taxable_minor' => null, 'rate_bps' => 0];
        $this->preview = ['user_id' => '', 'period' => now()->format('Y-m')];
        $attendance = DB::table('payroll_attendance_settings')->first();
        $this->attendanceRule = [
            'late_after' => $attendance?->late_after ?? '09:00',
            'target_cups' => $attendance?->target_cups ?? 38,
        ];
        $this->monthlyBonus = ['min_cups' => '', 'amount_minor' => ''];
    }

    public function attendanceRuleForm(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('late_after')->label('Batas jam absen (WIB)')->type('time')->required(),
            TextInput::make('target_cups')->label('Target cup harian Rider')
                ->numeric()->minValue(1)->rule('integer')->required(),
        ])->columns(['default' => 1, 'md' => 2])->statePath('attendanceRule');
    }

    public function monthlyBonusForm(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('min_cups')->label('Minimal cup sebulan')->numeric()->minValue(1)->rule('integer')->required(),
            TextInput::make('amount_minor')->label('Bonus (rupiah)')->numeric()->minValue(0)->rule('integer')->required(),
        ])->columns(['default' => 1, 'md' => 2])->statePath('monthlyBonus');
    }

    public function saveAttendanceRule(): void
    {
        Gate::authorize('manage-payroll');
        $data = $this->validate([
            'attendanceRule.late_after' => ['required', 'date_format:H:i'],
            'attendanceRule.target_cups' => ['required', 'integer', 'between:1,1000000'],
        ])['attendanceRule'];
        DB::transaction(function () use ($data): void {
            DB::table('payroll_attendance_settings')->insertOrIgnore([
                'id' => 1, 'late_after' => $data['late_after'], 'target_cups' => (int) $data['target_cups'],
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('payroll_attendance_settings')->where('id', 1)->update([
                'late_after' => $data['late_after'], 'target_cups' => (int) $data['target_cups'],
                'updated_at' => now(),
            ]);
            $this->audit('payroll_attendance_rule_saved', 'payroll_attendance_settings', 1, $data);
        });
        $this->success();
    }

    public function saveMonthlyBonus(): void
    {
        Gate::authorize('manage-payroll');
        $data = $this->validate([
            'monthlyBonus.min_cups' => ['required', 'integer', 'between:1,1000000'],
            'monthlyBonus.amount_minor' => ['required', 'integer', 'between:0,1000000000'],
        ])['monthlyBonus'];
        DB::transaction(function () use ($data): void {
            $inserted = DB::table('payroll_monthly_bonus_rules')->insertOrIgnore([
                'amount_minor' => (int) $data['amount_minor'], 'created_at' => now(), 'updated_at' => now(),
                'min_cups' => (int) $data['min_cups'],
            ]);
            $before = $inserted ? null : DB::table('payroll_monthly_bonus_rules')
                ->where('min_cups', (int) $data['min_cups'])->lockForUpdate()->first();
            DB::table('payroll_monthly_bonus_rules')->where('min_cups', (int) $data['min_cups'])->update([
                'amount_minor' => (int) $data['amount_minor'], 'updated_at' => now(),
            ]);
            $this->audit('payroll_monthly_bonus_saved', 'payroll_monthly_bonus_rules', (int) $data['min_cups'], [
                'min_cups' => (int) $data['min_cups'], 'amount_minor' => '[redacted]',
                '_payroll_ciphertext' => Crypt::encryptString(json_encode([
                    'before' => ['amount_minor' => $before?->amount_minor],
                    'after' => ['amount_minor' => (int) $data['amount_minor']],
                ], JSON_THROW_ON_ERROR)),
            ]);
        });
        $this->monthlyBonus = ['min_cups' => '', 'amount_minor' => ''];
        $this->success();
    }

    public function removeMonthlyBonus(int $minCups): void
    {
        Gate::authorize('manage-payroll');
        DB::transaction(function () use ($minCups): void {
            $before = DB::table('payroll_monthly_bonus_rules')->where('min_cups', $minCups)->lockForUpdate()->first();
            DB::table('payroll_monthly_bonus_rules')->where('min_cups', $minCups)->delete();
            $this->audit('payroll_monthly_bonus_removed', 'payroll_monthly_bonus_rules', $minCups, [
                'min_cups' => $minCups, 'amount_minor' => '[redacted]',
                '_payroll_ciphertext' => Crypt::encryptString(json_encode([
                    'before' => ['amount_minor' => $before?->amount_minor],
                    'after' => ['amount_minor' => null],
                ], JSON_THROW_ON_ERROR)),
            ]);
        });
        $this->success();
    }

    public function monthlyBonusRules(): array
    {
        Gate::authorize('manage-payroll');

        return DB::table('payroll_monthly_bonus_rules')->orderByDesc('min_cups')->get()->all();
    }

    public function previewForm(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('user_id')->label('Karyawan')
                ->options(fn (): array => collect($this->employeeOptions())
                    ->mapWithKeys(fn (User $user): array => [$user->id => $user->name])->all())
                ->searchable()->required(),
            TextInput::make('period')->label('Periode')->type('month')->required(),
        ])->columns(['default' => 1, 'md' => 2])->statePath('preview');
    }

    public function bpjsForm(Schema $schema): Schema
    {
        return $this->configurationForm($schema, 'bpjs');
    }

    public function ptkpForm(Schema $schema): Schema
    {
        return $this->configurationForm($schema, 'ptkp');
    }

    public function terForm(Schema $schema): Schema
    {
        return $this->configurationForm($schema, 'ter');
    }

    public function incentiveForm(Schema $schema): Schema
    {
        return $this->configurationForm($schema, 'incentive');
    }

    public function componentForm(Schema $schema): Schema
    {
        return $this->configurationForm($schema, 'component');
    }

    public function terPublicationForm(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('category')->label('Kategori')->options(['A' => 'A', 'B' => 'B', 'C' => 'C'])->required(),
            DatePicker::make('effective_from')->label('Berlaku mulai')->required(),
        ])->columns(['default' => 1, 'md' => 2])->statePath('terPublication');
    }

    public function verificationForm(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('verificationNote')->label('Catatan verifikasi'),
        ]);
    }

    public function annualVersionForm(Schema $schema): Schema
    {
        return $schema->components([
            DatePicker::make('effective_from')->label('Berlaku mulai')->required(),
            TextInput::make('source_note')->label('Sumber regulasi')->required(),
        ])->columns(['default' => 1, 'md' => 2])->statePath('annualVersion');
    }

    public function annualBracketForm(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('version_id')->label('Versi')
                ->options(fn (): array => collect($this->rows('payroll_annual_tax_versions'))
                    ->mapWithKeys(fn ($version): array => [$version->id => $version->effective_from])->all())->required(),
            TextInput::make('lower_taxable_minor')->label('Dasar mulai (Rp)')->numeric()->minValue(0)->required(),
            TextInput::make('upper_taxable_minor')->label('Dasar sampai (kosong = tanpa batas)')->numeric()->minValue(0),
            TextInput::make('rate_bps')->label('Tarif (bps)')->numeric()->minValue(0)->maxValue(10000)->required(),
        ])->columns(['default' => 1, 'md' => 2])->statePath('annualBracket');
    }

    public function entryForm(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('user_id')->label('Karyawan')
                ->options(fn (): array => collect($this->employeeOptions())
                    ->mapWithKeys(fn (User $user): array => [$user->id => $user->name])->all())
                ->searchable()->required(),
            Select::make('component_type_id')->label('Jenis komponen')
                ->options(fn (): array => collect($this->typeOptions())
                    ->mapWithKeys(fn ($type): array => [$type->id => $type->name.' ('.$type->effective_from->format('Y-m-d').')'])->all())
                ->searchable()->required(),
            DatePicker::make('period_start')->label('Awal periode (tanggal 1)')->required(),
            TextInput::make('amount_minor')->label('Nominal (Rp)')->numeric()->minValue(1)->required(),
            TextInput::make('note')->label('Catatan'),
        ])->columns(['default' => 1, 'md' => 3])->statePath('entry');
    }

    private function configurationForm(Schema $schema, string $state): Schema
    {
        $fields = match ($state) {
            'bpjs' => [
                'effective_from' => 'Berlaku mulai', 'jht_employer_bps' => 'JHT perusahaan (bps)',
                'jht_employee_bps' => 'JHT karyawan (bps)', 'jp_employer_bps' => 'JP perusahaan (bps)',
                'jp_employee_bps' => 'JP karyawan (bps)', 'jkk_employer_bps' => 'JKK perusahaan (bps)',
                'jkk_employee_bps' => 'JKK karyawan (bps)', 'jkm_employer_bps' => 'JKM perusahaan (bps)',
                'jkm_employee_bps' => 'JKM karyawan (bps)', 'health_employer_bps' => 'Kesehatan perusahaan (bps)',
                'health_employee_bps' => 'Kesehatan karyawan (bps)', 'jp_wage_cap_minor' => 'Batas upah JP (Rp)',
                'health_wage_cap_minor' => 'Batas upah Kesehatan (Rp)', 'source_note' => 'Sumber regulasi / catatan',
            ],
            'ptkp' => ['status' => 'Status', 'ter_category' => 'Kategori TER',
                'annual_amount_minor' => 'PTKP tahunan (Rp)', 'effective_from' => 'Berlaku mulai',
                'source_note' => 'Sumber regulasi / catatan'],
            'ter' => ['category' => 'Kategori', 'lower_gross_minor' => 'Bruto mulai (Rp)',
                'upper_gross_minor' => 'Bruto sampai (Rp, kosong = tanpa batas)', 'rate_bps' => 'Tarif (bps)',
                'effective_from' => 'Berlaku mulai', 'source_note' => 'Sumber regulasi / catatan'],
            'incentive' => ['effective_from' => 'Berlaku mulai', 'amount_per_cup_minor' => 'Per cup (Rp)',
                'target_cups' => 'Target cup', 'bonus_minor' => 'Bonus target (Rp)', 'source_note' => 'Sumber / catatan'],
            'component' => ['code' => 'Kode', 'name' => 'Nama', 'kind' => 'Jenis', 'taxable' => 'Kena pajak',
                'account_id' => 'Akun potongan', 'effective_from' => 'Berlaku mulai', 'source_note' => 'Sumber / catatan'],
        };
        $components = [];
        foreach ($fields as $field => $label) {
            $components[] = match (true) {
                $field === 'effective_from' => DatePicker::make($field)->label($label)->required(),
                $field === 'source_note' => Textarea::make($field)->label($label)->columnSpanFull(),
                $field === 'taxable' => Toggle::make($field)->label($label),
                $field === 'status' => Select::make($field)->label($label)
                    ->options(array_combine(['TK/0', 'TK/1', 'TK/2', 'TK/3', 'K/0', 'K/1', 'K/2', 'K/3'],
                        ['TK/0', 'TK/1', 'TK/2', 'TK/3', 'K/0', 'K/1', 'K/2', 'K/3'])),
                in_array($field, ['ter_category', 'category'], true) => Select::make($field)->label($label)
                    ->options(['A' => 'A', 'B' => 'B', 'C' => 'C']),
                $field === 'kind' => Select::make($field)->label($label)
                    ->options(['EARNING' => 'Tunjangan / biaya', 'DEDUCTION' => 'Potongan']),
                $field === 'account_id' => Select::make($field)->label($label)
                    ->options(fn (): array => collect($this->accountOptions())
                        ->mapWithKeys(fn ($account): array => [$account->id => $account->code.' · '.$account->name])->all())
                    ->searchable(),
                str_ends_with($field, '_minor') || str_ends_with($field, '_bps') || $field === 'target_cups' => TextInput::make($field)->label($label)->numeric()->minValue(0),
                default => TextInput::make($field)->label($label),
            };
        }

        return $schema->components($components)->columns(['default' => 1, 'md' => 3])->statePath($state);
    }

    public function previewPayroll(PayrollRunService $payroll): void
    {
        Gate::authorize('manage-payroll');
        $data = $this->validate([
            'preview.user_id' => ['required', 'integer', 'exists:users,id'],
            'preview.period' => ['required', 'date_format:Y-m'],
        ])['preview'];

        $this->previewResult = null;
        $this->previewError = null;
        try {
            $this->previewResult = $payroll->preview(User::query()->findOrFail($data['user_id']), $data['period']);
        } catch (ValidationException $e) {
            $this->previewError = collect($e->errors())->flatten()->first() ?? $e->getMessage();
        }
    }

    public function saveBpjs(): void
    {
        Gate::authorize('manage-payroll');
        $rules = [
            'bpjs.effective_from' => ['required', 'date_format:Y-m-d', Rule::unique('payroll_settings', 'effective_from')],
            'bpjs.jp_wage_cap_minor' => ['required', 'integer', 'min:1'],
            'bpjs.health_wage_cap_minor' => ['required', 'integer', 'min:1'],
            'bpjs.source_note' => ['required', 'string', 'min:3', 'max:2000'],
        ];
        foreach (self::bpjsRateFields() as $field) {
            $rules['bpjs.'.$field] = ['required', 'integer', 'between:0,10000'];
        }
        $data = $this->validate($rules)['bpjs'];
        $this->append('payroll_settings', $data);
        $this->bpjs['effective_from'] = '';
        $this->success();
    }

    public function savePtkp(): void
    {
        Gate::authorize('manage-payroll');
        $data = $this->validate([
            'ptkp.status' => ['required', Rule::in(['TK/0', 'TK/1', 'TK/2', 'TK/3', 'K/0', 'K/1', 'K/2', 'K/3'])],
            'ptkp.ter_category' => ['required', Rule::in(['A', 'B', 'C'])],
            'ptkp.annual_amount_minor' => ['required', 'integer', 'min:0'],
            'ptkp.effective_from' => ['required', 'date_format:Y-m-d'],
            'ptkp.source_note' => ['required', 'string', 'min:3', 'max:2000'],
        ])['ptkp'];
        if (DB::table('ptkp_rates')->where('status', $data['status'])->where('effective_from', $data['effective_from'])->exists()) {
            throw ValidationException::withMessages(['ptkp.effective_from' => 'Versi untuk status dan tanggal ini sudah ada ya.']);
        }
        $this->append('ptkp_rates', $data);
        $this->success();
    }

    public function saveTer(): void
    {
        Gate::authorize('manage-payroll');
        $data = $this->validate([
            'ter.category' => ['required', Rule::in(['A', 'B', 'C'])],
            'ter.lower_gross_minor' => ['required', 'integer', 'min:0'],
            'ter.upper_gross_minor' => ['nullable', 'integer', 'gte:ter.lower_gross_minor'],
            'ter.rate_bps' => ['required', 'integer', 'between:0,10000'],
            'ter.effective_from' => ['required', 'date_format:Y-m-d'],
            'ter.source_note' => ['required', 'string', 'min:3', 'max:2000'],
        ])['ter'];
        $data['upper_gross_minor'] = $data['upper_gross_minor'] === '' ? null : $data['upper_gross_minor'];
        DB::transaction(function () use ($data): void {
            DB::table('payroll_ter_versions')->insertOrIgnore([
                'category' => $data['category'], 'effective_from' => $data['effective_from'],
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $version = DB::table('payroll_ter_versions')->where('category', $data['category'])
                ->where('effective_from', $data['effective_from'])->lockForUpdate()->first();
            if ($version->published_at !== null) {
                throw ValidationException::withMessages(['ter.effective_from' => 'Versi TER sudah diterbitkan; buat tanggal versi baru.']);
            }
            $overlap = DB::table('pph21_ter_brackets')
                ->where('category', $data['category'])->where('effective_from', $data['effective_from'])
                ->where(fn ($query) => $query->whereNull('upper_gross_minor')
                    ->orWhere('upper_gross_minor', '>=', $data['lower_gross_minor']))
                ->when($data['upper_gross_minor'] !== null, fn ($query) => $query->where('lower_gross_minor', '<=', $data['upper_gross_minor']))
                ->exists();
            if ($overlap) {
                throw ValidationException::withMessages(['ter.lower_gross_minor' => 'Rentang TER bertumpuk dengan rentang yang sudah ada.']);
            }
            $this->append('pph21_ter_brackets', $data);
        });
        $this->success();
    }

    public function publishTer(): void
    {
        Gate::authorize('manage-payroll');
        $data = $this->validate([
            'terPublication.category' => ['required', Rule::in(['A', 'B', 'C'])],
            'terPublication.effective_from' => ['required', 'date_format:Y-m-d'],
        ])['terPublication'];
        DB::transaction(function () use ($data): void {
            $version = DB::table('payroll_ter_versions')->where('category', $data['category'])
                ->where('effective_from', $data['effective_from'])->lockForUpdate()->first();
            if (! $version || $version->published_at !== null) {
                throw ValidationException::withMessages(['terPublication.effective_from' => 'Versi TER tidak tersedia atau sudah diterbitkan.']);
            }
            $rows = DB::table('pph21_ter_brackets')->where('category', $data['category'])
                ->where('effective_from', $data['effective_from'])->orderBy('lower_gross_minor')->get();
            $next = 0;
            foreach ($rows as $row) {
                if ($next === null || (int) $row->lower_gross_minor !== $next) {
                    throw ValidationException::withMessages(['terPublication.effective_from' => 'Rentang TER harus lengkap, mulai dari nol tanpa celah.']);
                }
                $next = $row->upper_gross_minor === null ? null : (int) $row->upper_gross_minor + 1;
            }
            if ($rows->isEmpty() || $next !== null) {
                throw ValidationException::withMessages(['terPublication.effective_from' => 'Rentang TER harus berakhir tanpa batas atas.']);
            }
            DB::table('payroll_ter_versions')->where('id', $version->id)->update(['published_at' => now(), 'updated_at' => now()]);
            DB::table('payroll_ter_versions')->where('id', $version->id)->update(['source_note' => $rows->first()->source_note]);
            $this->audit('payroll_ter_version_published', 'payroll_ter_versions', $version->id, $data);
        });
        $this->success();
    }

    public function saveComponent(): void
    {
        Gate::authorize('manage-payroll');
        $data = $this->validate([
            'component.code' => ['required', 'alpha_dash', 'max:32'],
            'component.name' => ['required', 'string', 'max:255'],
            'component.kind' => ['required', Rule::in(['EARNING', 'DEDUCTION'])],
            'component.taxable' => ['required', 'boolean'],
            'component.account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'component.effective_from' => ['required', 'date_format:Y-m-d'],
            'component.source_note' => ['required', 'string', 'min:3', 'max:2000'],
        ])['component'];
        if ($data['kind'] === 'DEDUCTION' && $data['taxable']) {
            throw ValidationException::withMessages(['component.taxable' => 'Potongan tidak dapat ditandai sebagai penghasilan kena pajak.']);
        }
        if ($data['kind'] === 'DEDUCTION' && ! $data['account_id']) {
            throw ValidationException::withMessages(['component.account_id' => 'Pilih akun untuk potongan ini ya.']);
        }
        if ($data['code'] === 'ADVANCE' && DB::table('accounts')->where('id', $data['account_id'])->value('code') !== '1-1500') {
            throw ValidationException::withMessages(['component.account_id' => 'Kasbon harus masuk ke Piutang Karyawan.']);
        }
        $data['account_id'] = $data['account_id'] ?: null;
        if (DB::table('payroll_component_types')->where('code', $data['code'])->where('effective_from', $data['effective_from'])->exists()) {
            throw ValidationException::withMessages(['component.effective_from' => 'Versi jenis komponen untuk tanggal ini sudah ada.']);
        }
        $this->append('payroll_component_types', $data);
        $this->success();
    }

    public function saveEntry(): void
    {
        Gate::authorize('manage-payroll');
        $data = $this->validate([
            'entry.user_id' => ['required', 'integer', 'exists:users,id'],
            'entry.component_type_id' => ['required', 'integer', 'exists:payroll_component_types,id'],
            'entry.period_start' => ['required', 'date_format:Y-m-d', 'regex:/^\d{4}-(0[1-9]|1[0-2])-01$/'],
            'entry.request_key' => ['required', 'uuid'],
            'entry.amount_minor' => ['required', 'integer', 'min:1'],
            'entry.note' => ['nullable', 'string', 'max:255'],
        ])['entry'];
        $data['note'] = trim((string) ($data['note'] ?? '')) ?: null;
        $existing = PayrollComponentEntry::query()->where('request_key', $data['request_key'])->first();
        if ($existing) {
            if (! $this->sameEntry($existing, $data)) {
                throw ValidationException::withMessages(['entry.request_key' => 'Kunci permintaan sudah dipakai untuk komponen berbeda.']);
            }
            $this->entry['amount_minor'] = '';
            $this->entry['request_key'] = (string) Str::uuid();
            $this->success();

            return;
        }
        $user = User::query()->findOrFail($data['user_id']);
        $this->assertDraftPeriod($data['period_start']);
        if (! $user->employeeProfile?->payroll_active) {
            throw ValidationException::withMessages(['entry.user_id' => 'Karyawan belum aktif untuk payroll.']);
        }
        $type = PayrollComponentType::query()->findOrFail($data['component_type_id']);
        if ($type->effective_from->format('Y-m-d') > $data['period_start']) {
            throw ValidationException::withMessages(['entry.component_type_id' => 'Jenis komponen belum berlaku pada periode ini.']);
        }
        $latest = PayrollComponentType::query()->where('code', $type->code)
            ->whereDate('effective_from', '<=', $data['period_start'])
            ->orderByDesc('effective_from')->first();
        if ($latest?->id !== $type->id) {
            throw ValidationException::withMessages(['entry.component_type_id' => 'Pilih versi komponen yang berlaku untuk periode ini.']);
        }
        DB::transaction(function () use ($data): void {
            $this->assertDraftPeriod($data['period_start']);
            $entry = PayrollComponentEntry::query()->firstOrCreate(
                ['request_key' => $data['request_key']],
                [...$data, 'created_by' => Auth::id()],
            );
            if (! $this->sameEntry($entry, $data)) {
                throw ValidationException::withMessages(['entry.request_key' => 'Kunci permintaan sudah dipakai untuk komponen berbeda.']);
            }
            if ($entry->wasRecentlyCreated) {
                $this->audit('payroll_component_entry_created', PayrollComponentEntry::class, $entry->id, [
                    'user_id' => (int) $data['user_id'], 'component_type_id' => (int) $data['component_type_id'],
                    'period_start' => $data['period_start'], 'amount_minor' => '[redacted]',
                ]);
            }
        });
        $this->entry['amount_minor'] = '';
        $this->entry['request_key'] = (string) Str::uuid();
        $this->success();
    }

    public function rows(string $table): array
    {
        Gate::authorize('manage-payroll');
        if (! in_array($table, ['payroll_settings', 'ptkp_rates', 'pph21_ter_brackets',
            'rider_incentive_rules', 'payroll_component_types', 'payroll_annual_tax_versions'], true)) {
            return [];
        }

        return DB::table($table)->orderByDesc('effective_from')->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT)->get()->toArray();
    }

    public function employeeOptions(): array
    {
        Gate::authorize('manage-payroll');

        return User::query()->whereHas('employeeProfile', fn ($query) => $query->where('payroll_active', true))
            ->orderBy('name')->get(['id', 'name'])->all();
    }

    public function terVersions(): array
    {
        Gate::authorize('manage-payroll');

        return DB::table('payroll_ter_versions')->orderByDesc('effective_from')->orderBy('category')
            ->limit(self::HISTORY_LIMIT)->get()->toArray();
    }

    public function typeOptions(): array
    {
        Gate::authorize('manage-payroll');

        return PayrollComponentType::query()->orderBy('code')->orderByDesc('effective_from')->get()->all();
    }

    public function entryRows(): array
    {
        Gate::authorize('manage-payroll');

        return PayrollComponentEntry::query()->with(['type', 'user'])->orderByDesc('id')->limit(100)->get()->all();
    }

    public function accountOptions(): array
    {
        Gate::authorize('manage-payroll');

        return DB::table('accounts')->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name'])->all();
    }

    public function annualBrackets(int $versionId): array
    {
        Gate::authorize('manage-payroll');

        return DB::table('payroll_annual_tax_brackets')->where('version_id', $versionId)
            ->orderBy('lower_taxable_minor')->get()->all();
    }

    public function saveAnnualVersion(): void
    {
        Gate::authorize('manage-payroll');
        $data = $this->validate([
            'annualVersion.effective_from' => ['required', 'date_format:Y-m-d', Rule::unique('payroll_annual_tax_versions', 'effective_from')],
            'annualVersion.source_note' => ['required', 'string', 'min:3', 'max:2000'],
        ])['annualVersion'];
        $this->append('payroll_annual_tax_versions', $data);
        $this->annualVersion['effective_from'] = '';
        $this->success();
    }

    public function saveAnnualBracket(): void
    {
        Gate::authorize('manage-payroll');
        $data = $this->validate([
            'annualBracket.version_id' => ['required', 'integer', 'exists:payroll_annual_tax_versions,id'],
            'annualBracket.lower_taxable_minor' => ['required', 'integer', 'min:0'],
            'annualBracket.upper_taxable_minor' => ['nullable', 'integer', 'gte:annualBracket.lower_taxable_minor'],
            'annualBracket.rate_bps' => ['required', 'integer', 'between:0,10000'],
        ])['annualBracket'];
        DB::transaction(function () use ($data): void {
            $version = DB::table('payroll_annual_tax_versions')->where('id', $data['version_id'])->lockForUpdate()->first();
            if ($version->verified_at !== null) {
                throw ValidationException::withMessages(['annualBracket.version_id' => 'Versi ini sudah diverifikasi. Buat versi baru ya.']);
            }
            $data['upper_taxable_minor'] = $data['upper_taxable_minor'] === '' ? null : $data['upper_taxable_minor'];
            $overlap = DB::table('payroll_annual_tax_brackets')->where('version_id', $version->id)
                ->where(fn ($query) => $query->whereNull('upper_taxable_minor')
                    ->orWhere('upper_taxable_minor', '>=', $data['lower_taxable_minor']))
                ->when($data['upper_taxable_minor'] !== null, fn ($query) => $query->where('lower_taxable_minor', '<=', $data['upper_taxable_minor']))
                ->exists();
            if ($overlap) {
                throw ValidationException::withMessages(['annualBracket.lower_taxable_minor' => 'Rentang Pasal 17 bertumpuk.']);
            }
            $this->append('payroll_annual_tax_brackets', $data);
        });
        $this->success();
    }

    public function verifyVersion(string $table, int $id): void
    {
        Gate::authorize('manage-payroll');
        if (! in_array($table, ['payroll_settings', 'ptkp_rates', 'payroll_ter_versions', 'payroll_annual_tax_versions'], true)) {
            abort(404);
        }
        DB::transaction(function () use ($table, $id): void {
            $version = DB::table($table)->where('id', $id)->lockForUpdate()->first();
            if (! $version) {
                abort(404);
            }
            if ($version->verified_at !== null) {
                return;
            }
            if ($table === 'payroll_ter_versions' && $version->published_at === null) {
                throw ValidationException::withMessages(['verificationNote' => 'Terbitkan rentang TER lengkap dulu ya.']);
            }
            if ($table === 'payroll_annual_tax_versions') {
                $brackets = DB::table('payroll_annual_tax_brackets')->where('version_id', $id)->orderBy('lower_taxable_minor')->get();
                $next = 0;
                foreach ($brackets as $bracket) {
                    if ($next === null || (int) $bracket->lower_taxable_minor !== $next) {
                        throw ValidationException::withMessages(['verificationNote' => 'Rentang Pasal 17 harus lengkap tanpa celah.']);
                    }
                    $next = $bracket->upper_taxable_minor === null ? null : (int) $bracket->upper_taxable_minor + 1;
                }
                if ($brackets->isEmpty() || $next !== null) {
                    throw ValidationException::withMessages(['verificationNote' => 'Rentang Pasal 17 harus mulai dari nol dan berakhir tanpa batas atas.']);
                }
            }
            DB::table($table)->where('id', $id)->update([
                'verified_by' => Auth::id(), 'verified_at' => now(),
                'verification_note' => trim($this->verificationNote) ?: null, 'updated_at' => now(),
            ]);
            $this->audit('payroll_configuration_verified', $table, $id, ['verified' => true]);
        });
        $this->success();
    }

    public function updateEntry(int $id, int $amountMinor, ?string $note = null): void
    {
        Gate::authorize('manage-payroll');
        if ($amountMinor < 1) {
            throw ValidationException::withMessages(['amount' => 'Nominal harus lebih dari nol.']);
        }
        DB::transaction(function () use ($id, $amountMinor, $note): void {
            $period = PayrollComponentEntry::query()->whereKey($id)->value('period_start');
            if (! $period) {
                abort(404);
            }
            $this->assertDraftPeriod($period);
            $entry = PayrollComponentEntry::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            $before = ['amount_minor' => $entry->amount_minor, 'note' => $entry->note];
            $entry->update(['amount_minor' => $amountMinor, 'note' => $note]);
            $this->audit('payroll_component_entry_updated', PayrollComponentEntry::class, $id, [
                '_payroll_ciphertext' => Crypt::encryptString(json_encode([
                    'before' => $before,
                    'after' => ['amount_minor' => $amountMinor, 'note' => $note],
                ], JSON_THROW_ON_ERROR)),
            ]);
        });
        $this->success();
    }

    public function saveEditedEntry(int $id): void
    {
        Gate::authorize('manage-payroll');
        $data = $this->validate([
            'entryEdits.'.$id.'.amount_minor' => ['required', 'integer', 'min:1'],
            'entryEdits.'.$id.'.note' => ['nullable', 'string', 'max:255'],
        ])['entryEdits'][$id];
        $this->updateEntry($id, (int) $data['amount_minor'], $data['note'] ?? null);
    }

    private function assertDraftPeriod(string $periodStart): void
    {
        $run = PayrollRun::query()->whereDate('period_start', $periodStart)->lockForUpdate()->first();
        if ($run && $run->status !== 'DRAFT') {
            throw ValidationException::withMessages(['entry.period_start' => 'Run sudah dikunci. Komponennya tidak bisa diubah.']);
        }
    }

    private static function bpjsRateFields(): array
    {
        $fields = [];
        foreach (['jht', 'jp', 'jkk', 'jkm', 'health'] as $part) {
            $fields[] = $part.'_employer_bps';
            $fields[] = $part.'_employee_bps';
        }

        return $fields;
    }

    private function append(string $table, array $data): void
    {
        DB::transaction(function () use ($table, $data): void {
            $id = DB::table($table)->insertGetId([...$data, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit('payroll_configuration_created', $table, $id, $data);
        });
    }

    private function audit(string $action, string $subject, int $id, array $data): void
    {
        if ($subject === PayrollComponentEntry::class) {
            // Audit Trail is shared with Administrator. No employee payroll metadata belongs there.
            $data = ['payroll_detail' => '[redacted]']
                + (isset($data['_payroll_ciphertext']) ? ['_payroll_ciphertext' => $data['_payroll_ciphertext']] : []);
        }
        AuditLog::query()->create([
            'actor_id' => Auth::id(), 'actor_role' => Auth::user()?->role?->value,
            'action' => $action, 'subject_type' => $subject, 'subject_id' => $id,
            'after_json' => $data, 'ip' => request()->ip(),
        ]);
    }

    private function sameEntry(PayrollComponentEntry $entry, array $data): bool
    {
        return $entry->user_id === (int) $data['user_id']
            && $entry->component_type_id === (int) $data['component_type_id']
            && $entry->period_start->format('Y-m-d') === $data['period_start']
            && $entry->amount_minor === (int) $data['amount_minor']
            && $entry->note === $data['note']
            && $entry->created_by === Auth::id();
    }

    private function success(): void
    {
        Notification::make()->title('Versi payroll tersimpan')->success()->send();
    }
}
