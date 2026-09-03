<?php

namespace App\Http\Requests;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /showcase/hand-to-cart` — the barista's Add Stock form: pick a gerobak, pick the staff
 * on it, type the cups, and the day's money is already filled in.
 *
 * Deliberately does NOT require the cart to be on today's roster first. That is the point of the
 * feature: handing a cart its cups is what puts it on the roster, so a cart can never be blocked
 * from selling by an assignment nobody got around to making. StoreAllocationRequest takes the
 * opposite position (it requires an existing assignment) because Flow A is a different flow with
 * a different premise — see CentralStockService::handToCart().
 */
class StoreShowcaseHandoverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cart_id' => ['required', 'integer', Rule::exists('carts', 'id')],

            // Must be a STAFF account, checked here rather than only in the service so the
            // client gets a field-level error on the dropdown instead of a generic 422.
            'staff_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where('role', Role::STAFF->value),
            ],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')],
            'lines.*.qty' => ['required', 'integer', 'min:1', 'max:'.config('soul.max_qty_per_line', 100)],

            // Absent means "use the amount already written for today" — the normal path, since
            // the form arrives pre-filled and most days nobody touches it. Present means the
            // barista deliberately changed it, which is recorded as an override.
            'allowance_amount' => ['nullable', 'integer', 'min:0'],

            // Only needed for a cart that has never had a location before; otherwise it is
            // inherited from wherever the cart last stood.
            'location_id' => ['nullable', 'integer', Rule::exists('locations', 'id')],

            'operating_date' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'staff_id.exists' => 'Staff tidak ditemukan atau bukan role staff',
            'lines.required' => 'Pilih minimal satu produk',
            'lines.min' => 'Pilih minimal satu produk',
            'lines.*.qty.min' => 'Jumlah cups harus lebih dari 0',
            'lines.*.qty.max' => 'Jumlah cups per produk melebihi batas',
            'allowance_amount.min' => 'Uang harian tidak boleh negatif',
        ];
    }

    /**
     * @return array<int,int>
     */
    public function quantities(): array
    {
        $rows = [];

        foreach ($this->validated('lines') as $line) {
            $rows[(int) $line['product_id']] = ($rows[(int) $line['product_id']] ?? 0) + (int) $line['qty'];
        }

        return $rows;
    }
}
