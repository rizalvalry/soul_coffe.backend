<?php

namespace App\Services;

use App\Enums\PanelModule;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class FinancialReportSettingsService
{
    public const KPI_METRICS = [
        'hpp_ratio_bps' => 'Rasio HPP maksimal (bps)',
        'settlement_variance_minor' => 'Selisih setoran per Rider maksimal (Rp)',
        'qris_age_days' => 'Umur kliring QRIS maksimal (hari)',
        'waste_ratio_bps' => 'Rasio rusak maksimal (bps)',
        'deposit_deadline_days' => 'Batas hari setoran dari hari jualan',
        'on_time_deposits_bps' => 'Ketepatan setoran minimal (bps)',
        'period_close_days' => 'Hari sampai periode ditutup maksimal',
    ];

    public function saveCashFlowMapping(User $actor, int $accountId, string $category): void
    {
        $this->authorize($actor);
        Validator::make(compact('accountId', 'category'), [
            'accountId' => ['required', 'integer', 'exists:accounts,id'],
            'category' => ['required', 'in:OPERASI,INVESTASI,PENDANAAN'],
        ])->validate();
        $account = Account::query()->findOrFail($accountId);
        if (in_array($account->code, ['1-1100', '1-1200', '1-1300'], true)) {
            throw ValidationException::withMessages(['account_id' => 'Pemetaan akun kas tidak perlu diubah ya.']);
        }
        DB::transaction(function () use ($actor, $accountId, $category): void {
            $before = DB::table('cash_flow_mappings')->where('account_id', $accountId)->value('category');
            DB::table('cash_flow_mappings')->updateOrInsert(['account_id' => $accountId], [
                'category' => $category, 'updated_by' => $actor->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            AuditLog::recordAction($actor, 'financial.cash_flow_mapping_saved', $actor,
                ['account_id' => $accountId, 'category' => $before],
                ['account_id' => $accountId, 'category' => $category]);
        });
    }

    public function saveBudget(User $actor, array $input): void
    {
        $this->authorize($actor);
        $data = Validator::make($input, [
            'month' => ['required', 'date_format:Y-m'],
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
            'cart_id' => ['nullable', 'integer', 'exists:carts,id'],
            'amount_minor' => ['required', 'integer', 'min:0', 'max:1000000000000'],
        ])->validate();
        $account = Account::query()->findOrFail($data['account_id']);
        if (! in_array($account->type, ['REVENUE', 'EXPENSE'], true)) {
            throw ValidationException::withMessages(['account_id' => 'Anggaran hanya untuk akun pendapatan atau beban ya.']);
        }
        $scope = (int) ($data['cart_id'] ?? 0);
        DB::transaction(function () use ($actor, $data, $scope): void {
            $key = ['month' => $data['month'].'-01', 'account_id' => $data['account_id'], 'cart_scope' => $scope];
            $before = DB::table('financial_budgets')->where($key)->value('amount_minor');
            DB::table('financial_budgets')->updateOrInsert($key, [
                'cart_id' => $scope ?: null, 'amount_minor' => $data['amount_minor'],
                'updated_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            AuditLog::recordAction($actor, 'financial.budget_saved', $actor,
                ['month' => $data['month'], 'account_id' => $data['account_id'], 'cart_id' => $scope ?: null, 'amount_minor' => $before],
                ['month' => $data['month'], 'account_id' => $data['account_id'], 'cart_id' => $scope ?: null, 'amount_minor' => $data['amount_minor']]);
        });
    }

    public function saveKpiTarget(User $actor, string $metric, int $targetValue): void
    {
        $this->authorize($actor);
        $maximum = str_ends_with($metric, '_bps') ? 10000
            : (str_ends_with($metric, '_days') ? 366 : 1000000000000);
        if (! array_key_exists($metric, self::KPI_METRICS) || $targetValue < 0
            || $targetValue > $maximum) {
            throw ValidationException::withMessages(['target' => 'Target KPI belum valid ya.']);
        }
        DB::transaction(function () use ($actor, $metric, $targetValue): void {
            $before = DB::table('financial_kpi_targets')->where('metric', $metric)->value('target_value');
            DB::table('financial_kpi_targets')->updateOrInsert(['metric' => $metric], [
                'target_value' => $targetValue, 'updated_by' => $actor->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            AuditLog::recordAction($actor, 'financial.kpi_target_saved', $actor,
                ['metric' => $metric, 'target' => $before], ['metric' => $metric, 'target' => $targetValue]);
        });
    }

    private function authorize(User $actor): void
    {
        abort_unless(PermissionMatrix::can($actor, PanelModule::MANAGEMENT_REPORTS, 'edit'), 403);
    }
}
