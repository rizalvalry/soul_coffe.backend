<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AccountingPeriodService
{
    public function setStatus(int $year, int $month, string $status, User $actor): AccountingPeriod
    {
        Gate::forUser($actor)->authorize('manage-accounting-periods');
        if ($year < 2000 || $year > 9999 || $month < 1 || $month > 12 || ! in_array($status, ['OPEN', 'CLOSED'], true)) {
            throw ValidationException::withMessages(['period' => 'Periode atau status tidak valid.']);
        }

        return DB::transaction(function () use ($year, $month, $status, $actor): AccountingPeriod {
            $period = app(JournalService::class)->lockPeriod(Carbon::create($year, $month, 1));
            if ($period->status === $status) {
                return $period;
            }
            $before = ['status' => $period->status];
            $period->update([
                'status' => $status,
                'closed_by' => $status === 'CLOSED' ? $actor->id : null,
                'closed_at' => $status === 'CLOSED' ? now() : null,
            ]);
            AuditLog::query()->create([
                'actor_id' => $actor->id,
                'actor_role' => $actor->role->value,
                'action' => $status === 'CLOSED' ? 'accounting_period.closed' : 'accounting_period.reopened',
                'subject_type' => AccountingPeriod::class,
                'subject_id' => $period->id,
                'before_json' => $before,
                'after_json' => ['status' => $status],
            ]);

            return $period;
        }, 3);
    }
}
