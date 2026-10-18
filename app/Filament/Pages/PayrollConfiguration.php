<?php

namespace App\Filament\Pages;

use App\Models\AuditLog;
use App\Models\PayrollComponentEntry;
use App\Models\PayrollComponentType;
use App\Models\User;
use App\Services\Menu\MenuLabels;
use App\Services\PayrollCalculator;
use BackedEnum;
use DomainException;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
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

    public array $incentive = [];

    public array $component = [];

    public array $entry = [];

    public array $preview = [];

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
        $this->incentive = ['effective_from' => '', 'amount_per_cup_minor' => 0, 'target_cups' => 0, 'bonus_minor' => 0, 'source_note' => ''];
        $this->component = ['code' => '', 'name' => '', 'kind' => '', 'taxable' => false, 'effective_from' => '', 'source_note' => ''];
        $this->entry = ['user_id' => '', 'component_type_id' => '', 'period_start' => '', 'request_key' => (string) Str::uuid(), 'amount_minor' => '', 'note' => ''];
        $this->preview = ['user_id' => '', 'period' => now()->format('Y-m')];
    }

    public function previewPayroll(PayrollCalculator $calculator): void
    {
        Gate::authorize('manage-payroll');
        $data = $this->validate([
            'preview.user_id' => ['required', 'integer', 'exists:users,id'],
            'preview.period' => ['required', 'date_format:Y-m'],
        ])['preview'];

        $this->previewResult = null;
        $this->previewError = null;
        try {
            $this->previewResult = $calculator->calculate(User::query()->findOrFail($data['user_id']), $data['period']);
        } catch (DomainException $e) {
            $this->previewError = $e->getMessage();
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
            $this->audit('payroll_ter_version_published', 'payroll_ter_versions', $version->id, $data);
        });
        $this->success();
    }

    public function saveIncentive(): void
    {
        Gate::authorize('manage-payroll');
        $data = $this->validate([
            'incentive.effective_from' => ['required', 'date_format:Y-m-d', Rule::unique('rider_incentive_rules', 'effective_from')],
            'incentive.amount_per_cup_minor' => ['required', 'integer', 'min:0'],
            'incentive.target_cups' => ['required', 'integer', 'min:0'],
            'incentive.bonus_minor' => ['required', 'integer', 'min:0'],
            'incentive.source_note' => ['required', 'string', 'min:3', 'max:2000'],
        ])['incentive'];
        $this->append('rider_incentive_rules', $data);
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
            'component.effective_from' => ['required', 'date_format:Y-m-d'],
            'component.source_note' => ['required', 'string', 'min:3', 'max:2000'],
        ])['component'];
        if ($data['kind'] === 'DEDUCTION' && $data['taxable']) {
            throw ValidationException::withMessages(['component.taxable' => 'Potongan tidak dapat ditandai sebagai penghasilan kena pajak.']);
        }
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
            'rider_incentive_rules', 'payroll_component_types'], true)) {
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
            $data = ['payroll_detail' => '[redacted]'];
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
