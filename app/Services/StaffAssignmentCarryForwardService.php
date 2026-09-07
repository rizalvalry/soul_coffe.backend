<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Cart;
use App\Models\StaffAssignment;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * The only writer that creates today's roster from yesterday's, rather than from an admin's form.
 *
 * Before this existed, every operating day started with an Administrator re-typing the same
 * staff→cart→location triples into "Penugasan Staff" by hand, because StaffAssignmentForm has no
 * concept of "same as yesterday" — each row is scoped to one `operating_date` by the R11 unique
 * index. The roster rarely changes day to day, so that repetition was pure manual toil with no
 * decision in it.
 *
 * This carries a row forward only when nothing today already disagrees with it: a staff member
 * or cart with an existing row for the date is left alone, because a human already made a
 * decision for that slot and a batch job overwriting it silently would be worse than the toil it
 * replaces. `assigned_by` is copied rather than attributed to the system, because the roster
 * decision being repeated is still the same person's — a synthetic "system" actor would only
 * obscure who actually chose this pairing.
 */
class StaffAssignmentCarryForwardService
{
    /**
     * @return array{created: int, skipped_user_conflict: int, skipped_cart_conflict: int, skipped_inactive: int}
     */
    public function carryForward(?Carbon $operatingDate = null): array
    {
        $date = ($operatingDate ?? Carbon::today())->toDateString();
        $yesterday = ($operatingDate ?? Carbon::today())->copy()->subDay()->toDateString();

        $result = ['created' => 0, 'skipped_user_conflict' => 0, 'skipped_cart_conflict' => 0, 'skipped_inactive' => 0];

        // Active carts only — a cart under maintenance or retired isn't selling today, and
        // carrying an assignment onto it would roster a staff member to a bicycle nobody can use.
        $activeCartIds = Cart::query()->where('status', 'active')->pluck('id')->all();

        // Active STAFF only — the roster is meaningless for anyone else (only STAFF ever ride a
        // cart, per StaffAssignmentForm), and an account deactivated overnight must not be
        // re-rostered by a batch job the next morning.
        $activeStaffIds = User::query()
            ->where('role', Role::STAFF)
            ->where('is_active', true)
            ->pluck('id')
            ->all();

        StaffAssignment::query()
            ->whereDate('operating_date', $yesterday)
            ->whereIn('cart_id', $activeCartIds)
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($date, $activeStaffIds, &$result): void {
                foreach ($rows as $row) {
                    if (! in_array($row->user_id, $activeStaffIds, true)) {
                        $result['skipped_inactive']++;

                        continue;
                    }

                    // Someone already has a row for THIS staff member today — a manual edit, an
                    // earlier partial run, or a genuine reassignment. Leave it exactly as it is.
                    if (StaffAssignment::query()->where('user_id', $row->user_id)->whereDate('operating_date', $date)->exists()) {
                        $result['skipped_user_conflict']++;

                        continue;
                    }

                    // The cart itself already has today's staff decided (someone else was
                    // assigned to it before this ran) — the R11 unique index would reject the
                    // insert anyway; checking first turns that into a clean skip instead of a
                    // caught exception.
                    if (StaffAssignment::query()->where('cart_id', $row->cart_id)->whereDate('operating_date', $date)->exists()) {
                        $result['skipped_cart_conflict']++;

                        continue;
                    }

                    // The two exists() checks above narrow the common case; this still catches
                    // the race where a second, overlapping run (or a concurrent manual save)
                    // wins the same unique index between the check and this insert — the
                    // scheduler here is cron-driven and the same minute firing twice is ordinary
                    // (see routes/console.php). A lost race is a skip, not a batch failure.
                    try {
                        StaffAssignment::query()->create([
                            'user_id' => $row->user_id,
                            'cart_id' => $row->cart_id,
                            'location_id' => $row->location_id,
                            'operating_date' => $date,
                            'assigned_by' => $row->assigned_by,
                            'kitchen_id' => $row->kitchen_id,
                        ]);

                        $result['created']++;
                    } catch (UniqueConstraintViolationException) {
                        $result['skipped_user_conflict']++;
                    }
                }
            });

        return $result;
    }
}
