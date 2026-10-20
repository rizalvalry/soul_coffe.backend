<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\DailyCartAllowance;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The only writer of `daily_cart_allowances`.
 *
 * The allowance is written for every active cart at 00:00 so that by the time a barista opens
 * the Add Stock form the amount is already there and needs no typing — the point of the feature
 * is that the barista only ever has to think about cups.
 */
class DailyAllowanceService
{
    /**
     * Writes today's allowance for every active cart that doesn't have one yet.
     *
     * Safe to run repeatedly (the unique index makes a re-run a no-op), which matters because
     * this runs from cron on shared hosting where a missed or doubled minute is ordinary.
     *
     * @return int how many rows were actually created
     */
    public function ensureForAllCarts(?Carbon $operatingDate = null): int
    {
        $date = ($operatingDate ?? Carbon::today())->toDateString();
        $amount = (int) config('soul.daily_cart_allowance', 50000);

        $created = 0;

        // 'active' | 'maintenance' | 'retired' — a cart in maintenance isn't selling today, so
        // handing it an allowance would put money against a cart nobody is standing at.
        Cart::query()
            ->where('status', 'active')
            ->orderBy('id')
            ->chunkById(200, function ($carts) use ($date, $amount, &$created): void {
                foreach ($carts as $cart) {
                    $allowance = DailyCartAllowance::query()->firstOrCreate(
                        ['cart_id' => $cart->id, 'operating_date' => $date],
                        ['amount_minor' => $amount, 'is_edited' => false, 'set_by' => null],
                    );

                    if ($allowance->wasRecentlyCreated) {
                        $created++;
                    }
                }
            });

        return $created;
    }

    /**
     * The amount to pre-fill in the barista's form.
     *
     * Creates the row on demand rather than returning the config default, so a cart added after
     * midnight — or a day the cron never ran — still behaves identically to every other day
     * instead of silently having no allowance on record.
     */
    public function forCart(Cart $cart, ?Carbon $operatingDate = null): DailyCartAllowance
    {
        $date = ($operatingDate ?? Carbon::today())->toDateString();

        return DailyCartAllowance::query()->firstOrCreate(
            ['cart_id' => $cart->id, 'operating_date' => $date],
            [
                'amount_minor' => (int) config('soul.daily_cart_allowance', 50000),
                'is_edited' => false,
                'set_by' => null,
            ],
        );
    }

    /**
     * A barista overriding the day's amount for one cart.
     *
     * Records who changed it and flags the row as edited — the difference between the default
     * nobody looked at and a figure someone decided on is the whole audit value here.
     */
    public function override(Cart $cart, int $amount, User $actor, ?Carbon $operatingDate = null): DailyCartAllowance
    {
        if ($amount < 0) {
            throw new RuntimeException('Uang harian tidak boleh negatif.');
        }

        $allowance = $this->forCart($cart, $operatingDate);

        if ($allowance->amount_minor !== $amount && JournalEntry::query()
            ->where('source_type', 'daily_allowance')->where('source_id', $allowance->id)->exists()) {
            throw ValidationException::withMessages(['amount_minor' => 'Uang harian sudah diberikan. Koreksinya lewat jurnal pembalik ya.']);
        }

        // Only mark as edited when the figure actually moved: submitting the pre-filled form
        // unchanged is the normal path and shouldn't look like a deliberate override.
        if ($allowance->amount_minor !== $amount) {
            $allowance->update([
                'amount_minor' => $amount,
                'is_edited' => true,
                'set_by' => $actor->id,
            ]);
        }

        return $allowance;
    }

    public function recordGiven(DailyCartAllowance $allowance, User $rider, User $actor): void
    {
        if ($allowance->amount_minor <= 0) {
            return;
        }
        DB::transaction(function () use ($allowance, $rider, $actor): void {
            User::query()->whereKey($rider->id)->lockForUpdate()->firstOrFail();
            app(JournalService::class)->assertOpenDate($allowance->operating_date->toDateString());
            $purpose = 'DAY_'.$allowance->operating_date->format('Ymd').'_R'.$rider->id;
            $existing = JournalEntry::query()->where('source_type', 'daily_allowance')
                ->where('source_id', $allowance->id)->where('purpose', $purpose)->first();
            if ($existing) {
                return;
            }
            $already = JournalEntry::query()->where('source_type', 'daily_allowance')
                ->where('purpose', $purpose)->first();
            if ($already) {
                return;
            }
            $expense = Account::query()->where('code', '6-1700')->firstOrFail();
            $cash = Account::query()->where('code', '1-1100')->firstOrFail();
            app(JournalService::class)->post([
                'entry_date' => $allowance->operating_date->toDateString(),
                'description' => 'Uang harian Rider '.$rider->name,
                'source_type' => 'daily_allowance', 'source_id' => $allowance->id,
                'purpose' => $purpose,
                'posted_by' => $actor->id,
            ], [
                ['account_id' => $expense->id, 'debit_minor' => $allowance->amount_minor, 'credit_minor' => 0],
                ['account_id' => $cash->id, 'debit_minor' => 0, 'credit_minor' => $allowance->amount_minor],
            ]);
        });
    }

    /**
     * Total allowance handed to one cart on one day — the figure Settlement consults when
     * `soul.allowance_counts_toward_settlement` is on.
     */
    public function totalForCart(Cart $cart, ?Carbon $operatingDate = null): int
    {
        return (int) DailyCartAllowance::query()
            ->where('cart_id', $cart->id)
            ->whereDate('operating_date', ($operatingDate ?? Carbon::today())->toDateString())
            ->sum('amount_minor');
    }
}
