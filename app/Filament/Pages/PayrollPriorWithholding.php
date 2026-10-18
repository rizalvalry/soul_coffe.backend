<?php

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\PayrollPriorWithholding as Prior;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Menu\MenuLabels;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use UnitEnum;

class PayrollPriorWithholding extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Akuntansi';

    protected static ?string $navigationLabel = 'PPh Payroll Sebelumnya';

    protected static ?string $title = 'PPh Sudah Dipotong Sebelumnya';

    protected string $view = 'filament.pages.payroll-prior-withholding';

    public array $input = [];

    public static function canAccess(): bool
    {
        return Auth::user()?->is_active && in_array(Auth::user()?->role, [Role::FINANCE, Role::MANAGEMENT], true);
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return MenuLabels::group('Akuntansi');
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
        $this->input = [
            'user_id' => '', 'year' => now()->year, 'months' => '', 'gross_minor' => '',
            'jht_jp_minor' => '', 'amount_minor' => '', 'reason' => '',
        ];
    }

    public function save(): void
    {
        abort_unless(self::canAccess(), 403);
        $data = $this->validate([
            'input.user_id' => ['required', 'integer', 'exists:users,id'],
            'input.year' => ['required', 'integer', 'between:2020,2100'],
            'input.amount_minor' => ['required', 'integer', 'min:0'],
            'input.gross_minor' => ['required', 'integer', 'min:0'],
            'input.jht_jp_minor' => ['required', 'integer', 'min:0'],
            'input.months' => ['required', 'string', 'regex:/^([1-9]|1[01])(,\s*([1-9]|1[01]))*$/'],
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
        $this->input['amount_minor'] = '';
        Notification::make()->title('PPh sebelumnya tersimpan')->success()->send();
    }

    public function employees(): array
    {
        abort_unless(self::canAccess(), 403);

        return User::query()->whereHas('employeeProfile', fn ($query) => $query->where('payroll_active', true))
            ->orderBy('name')->get(['id', 'name'])->all();
    }
}
