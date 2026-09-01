<?php

namespace App\Http\Requests;

use App\Models\StaffAssignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * `POST /allocations` (docs/04 §Flow A, docs/02 §5).
 *
 * Deliberately has no `total`/`total_qty` field: the total is always computed server-side
 * from `lines` (§4 — "the paper form's arithmetic is exactly where human error lived"). A
 * client-supplied total, if sent, is simply never read.
 */
class StoreAllocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route reachability is gated by AllocationController::middleware() (role:BARISTA).
        // FormRequest::authorize() runs after that, so allowing here is not a widening.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'operating_date' => ['required', 'date'],
            'cart_id' => ['required', 'integer', Rule::exists('carts', 'id')],
            'staff_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'location_id' => ['required', 'integer', Rule::exists('locations', 'id')],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')],
            'lines.*.qty_issued' => ['required', 'integer', 'min:1'],
            // Required only when this becomes the cart's second allocation of the day (E20);
            // that existence check cannot be expressed as a static rule, so it is enforced in
            // AllocationService::create() where the "does one already exist" fact is known.
            'correction_reason' => ['nullable', 'string', 'min:10'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.required' => 'Pilih minimal satu produk',
            'lines.min' => 'Pilih minimal satu produk',
            'lines.*.qty_issued.min' => 'Jumlah harus lebih dari 0',
            'correction_reason.min' => 'Alasan koreksi wajib diisi (min. 10 karakter)',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->filled('staff_id') || ! $this->filled('cart_id') || ! $this->filled('operating_date')) {
                return; // a prior required/exists rule already failed on these
            }

            $assigned = StaffAssignment::query()
                ->where('user_id', $this->input('staff_id'))
                ->where('cart_id', $this->input('cart_id'))
                ->whereDate('operating_date', $this->input('operating_date'))
                ->exists();

            if (! $assigned) {
                $validator->errors()->add(
                    'staff_id',
                    'Staff tidak bertugas di gerobak ini pada tanggal tersebut.',
                );
            }
        });
    }
}
