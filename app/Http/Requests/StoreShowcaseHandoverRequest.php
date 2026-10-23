<?php

namespace App\Http\Requests;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * `POST /showcase/hand-to-cart` — the barista's Add Stock form: pick a gerobak, pick the rider
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
            'lines.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('is_active', true)->where('is_sellable', true)],
            'lines.*.qty' => ['required', 'integer', 'min:1', 'max:9999'],
            'note' => ['nullable', 'string', 'max:500'],

            // Absent means "use the amount already written for today" — the normal path, since
            // the form arrives pre-filled and most days nobody touches it. Present means the
            // barista deliberately changed it, which is recorded as an override.
            'allowance_amount' => ['nullable', 'integer', 'min:0'],

            // Legacy APK compatibility only. Management's active setup determines the location.
            'location_id' => ['nullable', 'integer'],

            'operating_date' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'staff_id.exists' => 'Rider tujuan tidak ditemukan ya.',
            'lines.required' => 'Pilih minimal satu produk dulu ya.',
            'lines.min' => 'Pilih minimal satu produk dulu ya.',
            'lines.*.qty.min' => 'Jumlah cups harus lebih dari 0 ya.',
            'lines.*.qty.max' => 'Jumlah cups per produk maksimal 9.999',
            'lines.*.product_id.exists' => 'Produk ini bukan produk utama',
            'allowance_amount.min' => 'Uang harian tidak boleh negatif ya.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $totals = [];
            foreach ($this->input('lines', []) as $index => $line) {
                $productId = (int) $line['product_id'];
                $totals[$productId] = ($totals[$productId] ?? 0) + (int) $line['qty'];
                if ($totals[$productId] > 9999) {
                    $validator->errors()->add("lines.{$index}.qty", 'Jumlah cups per produk maksimal 9.999');
                }
            }
        });
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
