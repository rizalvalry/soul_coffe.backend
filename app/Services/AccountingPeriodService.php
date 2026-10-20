<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AccountingPeriodService
{
    public function setStatus(int $year, int $month, string $status, User $actor, ?string $reason = null): AccountingPeriod
    {
        Gate::forUser($actor)->authorize('manage-accounting-periods');
        if ($year < 2000 || $year > 9999 || $month < 1 || $month > 12 || ! in_array($status, ['OPEN', 'CLOSED'], true)) {
            throw ValidationException::withMessages(['period' => 'Periode atau status tidak valid.']);
        }

        return DB::transaction(function () use ($year, $month, $status, $actor, $reason): AccountingPeriod {
            $period = app(JournalService::class)->lockPeriod(Carbon::create($year, $month, 1));
            if ($period->status === $status) {
                return $period;
            }
            $reason = trim((string) $reason);
            if ($status === 'OPEN' && ($reason === '' || mb_strlen($reason) > 255)) {
                throw ValidationException::withMessages(['reason' => 'Alasan buka ulang wajib diisi, maksimal 255 karakter ya.']);
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
                'after_json' => ['status' => $status, 'reason' => $status === 'OPEN' ? $reason : null],
            ]);

            if ($status === 'OPEN') {
                $recipients = User::query()->where('role', Role::MANAGEMENT)->where('is_active', true)->pluck('id')->all();
                app(EventPublisher::class)->publish(
                    'AccountingPeriodReopened', 'Periode akuntansi dibuka lagi',
                    sprintf('Periode %04d-%02d dibuka lagi. Alasan: %s', $year, $month, $reason),
                    ['role.MANAGEMENT'], $recipients, includeActorPush: true,
                );
            }

            return $period;
        }, 3);
    }
}
