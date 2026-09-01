<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApproveRefillRequestRequest;
use App\Http\Requests\DeliverRefillRequestRequest;
use App\Http\Requests\MarkReadyRefillRequestRequest;
use App\Http\Requests\RejectRefillRequestRequest;
use App\Http\Resources\RefillRequestResource;
use App\Models\RefillRequest;
use App\Services\RefillRequestStateMachine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Every action here delegates the actual guard logic to
 * RefillRequestStateMachine — this controller only authorises (who) and
 * shapes the HTTP response, never touches `status` itself.
 *
 * `idempotent:require` (R12) is already applied to every route in
 * routes/api.php; nothing here re-implements that.
 */
class RefillTransitionController extends Controller
{
    private const EAGER = [
        'cart', 'staff', 'kitchen', 'finance', 'barista', 'rider',
        'lines.product', 'evidencePhoto', 'signature',
    ];

    public function __construct(private readonly RefillRequestStateMachine $stateMachine) {}

    public function approve(ApproveRefillRequestRequest $request, RefillRequest $refill)
    {
        Gate::authorize('approve', $refill);

        $updated = $this->stateMachine->approve(
            $refill,
            $request->user(),
            $request->validated(),
            $request->input('device_id'),
        );

        return new RefillRequestResource($updated->load(self::EAGER));
    }

    public function reject(RejectRefillRequestRequest $request, RefillRequest $refill)
    {
        Gate::authorize('reject', $refill);

        $updated = $this->stateMachine->reject(
            $refill,
            $request->user(),
            $request->validated('reason'),
            $request->input('device_id'),
        );

        return new RefillRequestResource($updated->load(self::EAGER));
    }

    public function cancel(Request $request, RefillRequest $refill)
    {
        Gate::authorize('cancel', $refill);

        $updated = $this->stateMachine->cancel($refill, $request->user(), $request->input('device_id'));

        return new RefillRequestResource($updated->load(self::EAGER));
    }

    public function startPreparing(Request $request, RefillRequest $refill)
    {
        Gate::authorize('startPreparing', $refill);

        $updated = $this->stateMachine->startPreparing($refill, $request->user(), $request->input('device_id'));

        return new RefillRequestResource($updated->load(self::EAGER));
    }

    public function markReady(MarkReadyRefillRequestRequest $request, RefillRequest $refill)
    {
        Gate::authorize('markReady', $refill);

        $updated = $this->stateMachine->markReady(
            $refill,
            $request->user(),
            $request->validated(),
            $request->input('device_id'),
        );

        return new RefillRequestResource($updated->load(self::EAGER));
    }

    public function claim(Request $request, RefillRequest $refill)
    {
        Gate::authorize('claim', $refill);

        $updated = $this->stateMachine->claim($refill, $request->user(), $request->input('device_id'));

        return new RefillRequestResource($updated->load(self::EAGER));
    }

    /**
     * See docs/04 `POST /refills/{id}/deliver` and E19: on a ledger-posting
     * failure the request stays DELIVERED and this answers 202, never a
     * silent 200/CLOSED.
     */
    public function deliver(DeliverRefillRequestRequest $request, RefillRequest $refill)
    {
        Gate::authorize('deliver', $refill);

        $result = $this->stateMachine->deliver(
            $refill,
            $request->user(),
            $request->validated(),
            $request->file('signature'),
        );

        $updated = $result['refill']->load(self::EAGER);

        if (! $result['ledger_posted']) {
            return response()->json([
                'message' => 'Pengiriman tercatat, posting stok sedang diproses ulang.',
                'data' => (new RefillRequestResource($updated))->toArray($request),
            ], 202);
        }

        return new RefillRequestResource($updated);
    }
}
