<?php

namespace App\Services;

use App\Models\RiderClosing;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;

/** The operating-day gate for writes made by the cart-selling Rider. */
class RiderDayGate
{
    public function __construct(private readonly AttendanceService $attendance) {}

    public function assertReady(User $rider, ?Carbon $operatingDate = null, string $attendanceMessage = 'Absen dulu sebelum mencatat penjualan.', bool $allowClosed = false): void
    {
        $date = $operatingDate ?? Carbon::today();

        if (! $this->attendance->isStaffWindowOpen($date)) {
            throw new HttpResponseException(response()->json([
                'code' => 'DAY_NOT_OPEN',
                'message' => 'Produksi belum buka absen hari ini, jadi transaksi belum bisa jalan dulu ya.',
            ], 422));
        }

        if (! $this->attendance->hasClockedIn($rider, $date)) {
            abort(422, $attendanceMessage);
        }

        if (! $allowClosed && RiderClosing::query()->where('rider_id', $rider->id)
            ->whereDate('operating_date', $date->toDateString())->exists()) {
            throw new HttpResponseException(response()->json([
                'code' => 'CLOSING_DONE',
                'message' => 'Closing hari ini sudah masuk. Transaksi baru tidak bisa ditambah ya.',
            ], 409));
        }
    }
}
